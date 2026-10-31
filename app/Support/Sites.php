<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\SiteDomain;
use App\Models\SiteRelease;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Website metas. json_config.site holds the site's settings (title, tagline, logo, theme,
 * footer, language); its params are its pages: ref_meta_id the page meta, parent_id the page
 * it sits under (its address continues the parent's), label_name what the menu says, is_show
 * whether the header's menu shows it, json_config {slug, title, description, image, in_footer}
 * the address part, what search engines and shared links show, and whether the footer lists
 * it (left out: as in the header). The top page with an empty slug is the home
 * page. site_domains are the hosts it answers on.
 *
 * Pages are edited in the page builder as drafts; visitors see what was published
 * (site_releases, see SiteRelease). Publishing runs SiteChecks first.
 */
class Sites
{
    public const TYPE = 'website';

    /** The site's settings, each filled in. */
    public const BASE = ['title' => null, 'tagline' => null, 'description' => null, 'logo' => null, 'theme_meta_id' => null, 'look' => SiteLooks::DEFAULT, 'brand_accent' => true, 'header' => 'masthead', 'announcement' => null, 'footer_style' => 'letter', 'footer' => null, 'lang' => 'en', 'social' => []];

    /** How a site's header and footer are laid out (SiteView). */
    public const HEADERS = ['masthead', 'edge', 'pill', 'slab', 'banner'];

    public const FOOTERS = ['letter', 'mast', 'statement', 'colophon', 'columns'];

    /** Social networks a site can link to (settings.social: network => address), in the order they show. */
    public const SOCIALS = ['instagram', 'facebook', 'youtube', 'tiktok', 'x', 'linkedin'];

    /** Visits from these aren't counted. */
    private const BOTS = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|headless|lighthouse/i';

    private const MAX_DEPTH = 3;

    public static function requireSite(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === self::TYPE, 422, 'Only website metas are websites.');
    }

    /** @return array<string, mixed> */
    public static function settings(Meta $site): array
    {
        $input = (array) ($site->json_config['site'] ?? []);
        $settings = array_merge(self::BASE, array_intersect_key($input, self::BASE));
        $settings['theme_meta_id'] = $settings['theme_meta_id'] ? (int) $settings['theme_meta_id'] : null;
        $settings['look'] = in_array($settings['look'], SiteLooks::SLUGS, true) ? $settings['look'] : SiteLooks::DEFAULT;
        $settings['brand_accent'] = (bool) $settings['brand_accent'];
        $settings['header'] = in_array($settings['header'], self::HEADERS, true) ? $settings['header'] : 'masthead';
        $settings['footer_style'] = in_array($settings['footer_style'], self::FOOTERS, true) ? $settings['footer_style'] : 'letter';
        $social = is_array($settings['social']) ? $settings['social'] : [];
        $settings['social'] = array_filter(array_map(fn ($network) => self::text($social[$network] ?? null), array_combine(self::SOCIALS, self::SOCIALS)));

        return $settings;
    }

    /** Whether the footer lists a page: its own in_footer, else as the header does. */
    public static function inFooter(MetaParam $entry): bool
    {
        return (bool) ($entry->json_config['in_footer'] ?? $entry->is_show);
    }

    /** @return Collection<int, MetaParam> */
    public static function entries(Meta $site): Collection
    {
        return MetaParam::where('main_meta_id', $site->id)->orderBy('order_number')->orderBy('id')->get();
    }

    /**
     * Each page's address: "/" for the home page, else its parents' slugs and its own.
     *
     * @param  Collection<int, MetaParam>  $entries
     * @return array<int, string>
     */
    public static function paths(Collection $entries): array
    {
        $byId = $entries->keyBy('id');
        $paths = [];
        foreach ($entries as $entry) {
            $slugs = [];
            for ($e = $entry, $guard = 0; $e && $guard < 10; $e = $byId->get($e->parent_id), $guard++) {
                array_unshift($slugs, (string) ($e->json_config['slug'] ?? ''));
            }
            $paths[$entry->id] = '/'.implode('/', array_filter($slugs, fn ($s) => $s !== ''));
        }

        return $paths;
    }

    /**
     * The release each page shows now (if any); a release without a snapshot means taken down.
     *
     * @return array<int, SiteRelease>
     */
    public static function current(Meta $site): array
    {
        $current = [];
        SiteRelease::where('site_meta_id', $site->id)->whereNotNull('param_id')->where('publish_at', '<=', now())
            ->orderByDesc('publish_at')->orderByDesc('id')->get()
            ->each(function (SiteRelease $r) use (&$current) {
                $current[$r->param_id] ??= $r;
            });

        return $current;
    }

    /**
     * The pages visitors can open now, with their releases.
     *
     * @return array<int, SiteRelease>
     */
    public static function live(Meta $site): array
    {
        return array_filter(self::current($site), fn (SiteRelease $r) => $r->isLive());
    }

    /**
     * A page as it would be published now: its address details and widgets, with the parts
     * of composite widgets it uses, as plain data.
     *
     * @return array{title: string, description: ?string, image: ?string, items: array, templates: array<int, array>}
     */
    public static function snapshot(MetaParam $entry, User $user): array
    {
        $page = $entry->ref_meta_id ? Meta::find($entry->ref_meta_id) : null;
        $items = $page ? self::plain(PageLayout::items($page, $user)) : [];
        $templates = [];
        self::collectTemplates($items, $user, $templates);
        $config = $entry->json_config ?? [];

        return [
            'title' => trim((string) ($config['title'] ?? '')) ?: ($entry->label_name ?: ($page?->label_name ?: $page?->name ?? '')),
            'description' => self::text($config['description'] ?? null),
            'image' => self::text($config['image'] ?? null),
            'items' => $items,
            'templates' => $templates,
        ];
    }

    /** What tells two snapshots apart: so a page is "changed" when its draft no longer matches what's published. */
    public static function hash(array $snapshot): string
    {
        return sha1(json_encode($snapshot));
    }

    /**
     * The site for the website manager: settings, domains, and its pages as a tree, each with
     * its address and whether it is a draft, live (changed since or not), scheduled or taken down.
     *
     * @return array<string, mixed>
     */
    public static function show(Meta $site, User $user): array
    {
        $entries = self::entries($site);
        $paths = self::paths($entries);
        $current = self::current($site);
        $scheduled = SiteRelease::where('site_meta_id', $site->id)->where('publish_at', '>', now())->orderBy('publish_at')->get()->groupBy('param_id');
        $pages = Meta::whereIn('id', $entries->pluck('ref_meta_id')->filter())->get(['id', 'code', 'name', 'updated_at'])->keyBy('id');

        $rows = $entries->map(function (MetaParam $e) use ($paths, $current, $scheduled, $pages, $user) {
            $release = $current[$e->id] ?? null;
            $changed = $release?->isLive() ? $release->hash !== self::hash(self::snapshot($e, $user)) : null;

            return [
                'param_id' => $e->id,
                'parent_id' => $e->parent_id,
                'page_meta_id' => $e->ref_meta_id,
                'page' => $pages->get($e->ref_meta_id)?->only(['id', 'code', 'name']),
                'label' => $e->label_name,
                'in_menu' => (bool) $e->is_show,
                'in_footer' => self::inFooter($e),
                'slug' => (string) ($e->json_config['slug'] ?? ''),
                'title' => self::text($e->json_config['title'] ?? null),
                'description' => self::text($e->json_config['description'] ?? null),
                'image' => self::text($e->json_config['image'] ?? null),
                'path' => $paths[$e->id],
                'status' => match (true) {
                    $release === null => 'draft',
                    ! $release->isLive() => 'down',
                    $changed => 'changed',
                    default => 'live',
                },
                'published_at' => $release?->publish_at,
                'release_id' => $release?->id,
                'scheduled' => ($scheduled->get($e->id) ?? collect())->map(fn (SiteRelease $r) => ['id' => $r->id, 'publish_at' => $r->publish_at, 'live' => $r->isLive()])->values(),
            ];
        });

        return [
            'meta' => $site->load('type'),
            'site' => self::settings($site),
            'domains' => SiteDomain::where('site_meta_id', $site->id)->orderByDesc('is_primary')->orderBy('host')->get(['id', 'host', 'is_primary']),
            'pages' => self::nest($rows->all(), null),
        ];
    }

    /**
     * Saves the site's name, settings, domains and pages in one go (run it in a transaction).
     * pages: [{param_id?, page_meta_id? | new_page?, label, slug, title?, description?, image?,
     * in_menu?, in_footer?, children?}]; a page left out leaves the site (its page meta stays). new_page
     * makes an empty page meta to design in the page builder.
     *
     * @param  array{name?: string, site?: array, domains?: array, pages?: array}  $input
     */
    public static function save(Meta $site, array $input, User $user): void
    {
        if (array_key_exists('site', $input)) {
            $site->update(['json_config' => ['site' => self::normalize((array) $input['site'])] + ($site->json_config ?? []), 'updated_by' => $user->id]
                + (isset($input['name']) && trim($input['name']) !== '' ? ['name' => trim($input['name'])] : []));
        }
        if (array_key_exists('domains', $input)) {
            self::saveDomains($site, (array) $input['domains']);
        }
        if (array_key_exists('pages', $input)) {
            self::savePages($site, (array) $input['pages'], $user);
        }
    }

    /**
     * Publishes a page now or at a time: its draft becomes what visitors see. Floor problems stop
     * it; reflex ones need a reason (note), kept with the release.
     */
    public static function publish(Meta $site, MetaParam $entry, User $user, ?CarbonInterface $at, ?string $note): SiteRelease
    {
        abort_unless($entry->main_meta_id === $site->id, 404);
        abort_unless($entry->ref_meta_id, 422, 'This page has no page to publish. Pick one first.');
        $snapshot = self::snapshot($entry, $user);
        $issues = SiteChecks::page($site, $entry, $snapshot);
        $levels = array_count_values(array_column($issues, 'level'));
        $note = self::text($note);

        if (($levels['floor'] ?? 0) > 0) {
            abort(response()->json([
                'message' => __('This page can\'t be published until :count problem(s) are fixed.', ['count' => $levels['floor']]),
                'issues' => $issues,
            ], 422));
        }
        if (($levels['reflex'] ?? 0) > 0 && $note === null) {
            abort(response()->json([
                'message' => __('Say why this page goes out as it is, or fix what the checks found.'),
                'issues' => $issues,
                'needs_reason' => true,
            ], 422));
        }

        return SiteRelease::create([
            'site_meta_id' => $site->id,
            'param_id' => $entry->id,
            'page_meta_id' => $entry->ref_meta_id,
            'path' => self::paths(self::entries($site))[$entry->id] ?? null,
            'snapshot' => $snapshot,
            'hash' => self::hash($snapshot),
            'checks' => $issues,
            'note' => $note,
            'publish_at' => $at && $at->isFuture() ? $at : now(),
            'published_by' => $user->id,
        ]);
    }

    /** Takes a page down now: visitors get "not found" until it is published again. */
    public static function unpublish(Meta $site, MetaParam $entry, User $user, ?string $note): SiteRelease
    {
        abort_unless($entry->main_meta_id === $site->id, 404);

        return SiteRelease::create([
            'site_meta_id' => $site->id,
            'param_id' => $entry->id,
            'page_meta_id' => $entry->ref_meta_id,
            'path' => self::paths(self::entries($site))[$entry->id] ?? null,
            'note' => self::text($note),
            'publish_at' => now(),
            'published_by' => $user->id,
        ]);
    }

    /** Publishes an old release again, as it was. */
    public static function restore(SiteRelease $release, User $user): SiteRelease
    {
        abort_unless($release->isLive(), 422, 'This release took the page down; there is nothing in it to restore.');
        abort_unless($release->param_id && MetaParam::whereKey($release->param_id)->exists(), 422, 'This page is no longer on the site.');

        return SiteRelease::create([
            'site_meta_id' => $release->site_meta_id,
            'param_id' => $release->param_id,
            'page_meta_id' => $release->page_meta_id,
            'path' => $release->path,
            'snapshot' => $release->snapshot,
            'hash' => $release->hash,
            'checks' => $release->checks,
            'note' => __('Restored from release #:id.', ['id' => $release->id]),
            'restored_from_id' => $release->id,
            'publish_at' => now(),
            'published_by' => $user->id,
        ]);
    }

    /**
     * The website a request is for: by host name (its own domain) or by code (/site/{code}).
     */
    public static function resolve(?string $host, ?string $code): ?Meta
    {
        $type = MetaType::where('code', self::TYPE)->value('id');
        if ($host) {
            $siteId = SiteDomain::where('host', self::host($host))->value('site_meta_id');

            return $siteId ? Meta::where('type_id', $type)->where('is_active', true)->find($siteId) : null;
        }

        return $code ? Meta::where('type_id', $type)->where('is_active', true)->where('code', $code)->first() : null;
    }

    /**
     * What a visitor's browser draws for an address: the site's look and menu, and the page
     * (null when there's none there). With $draft (admins previewing), pages show as they are
     * in the builder and every page is in the menu.
     *
     * nav is the header's menu and footer_nav the footer's, both nested; the page lists the
     * pages under it (children) for its "in this section" list.
     *
     * @return array{site: array, nav: array, footer_nav: array, page: ?array}
     */
    public static function page(Meta $site, string $path, ?User $draftFor = null): array
    {
        $entries = self::entries($site);
        $paths = self::paths($entries);
        $live = $draftFor ? $entries->keyBy('id')->all() : self::live($site);
        $path = self::cleanPath($path);
        $paramId = array_search($path, array_intersect_key($paths, $live), true);

        $page = null;
        if ($paramId !== false) {
            $snapshot = $draftFor ? self::snapshot($entries->firstWhere('id', $paramId), $draftFor) : $live[$paramId]->snapshot;
            $children = $entries->filter(fn (MetaParam $e) => $e->parent_id === $paramId && isset($live[$e->id]))
                ->map(function (MetaParam $e) use ($paths, $live, $draftFor) {
                    $config = $draftFor ? ($e->json_config ?? []) : (array) $live[$e->id]->snapshot;

                    return ['label' => $e->label_name ?: (string) ($config['title'] ?? $paths[$e->id]), 'path' => $paths[$e->id], 'description' => self::text($config['description'] ?? null)];
                })->values()->all();
            $page = ['path' => $path] + $snapshot + ['children' => $children];
        }
        $settings = self::settings($site);
        $menu = fn (callable $shows) => array_map(
            fn ($n) => array_diff_key($n, ['param_id' => 1, 'parent_id' => 1]),
            self::nest($entries->filter(fn (MetaParam $e) => $shows($e) && isset($live[$e->id]))
                ->map(fn (MetaParam $e) => ['param_id' => $e->id, 'parent_id' => $e->parent_id, 'label' => $e->label_name ?: $paths[$e->id], 'path' => $paths[$e->id]])
                ->values()->all(), null),
        );

        return [
            'site' => [
                'id' => $site->id,
                'code' => $site->code,
                'name' => $site->name,
                ...$settings,
                'title' => $settings['title'] ?: $site->name,
                'theme' => self::theme($settings['theme_meta_id']),
                'host' => SiteDomain::where('site_meta_id', $site->id)->orderByDesc('is_primary')->orderBy('id')->value('host'),
            ],
            'nav' => $menu(fn (MetaParam $e) => $e->is_show),
            'footer_nav' => $menu(fn (MetaParam $e) => self::inFooter($e)),
            'page' => $page,
        ];
    }

    /** Counts a page view, one row per page per day; bots aren't counted. */
    public static function visit(Meta $site, string $path, ?string $userAgent): void
    {
        if ($userAgent === null || $userAgent === '' || preg_match(self::BOTS, $userAgent)) {
            return;
        }
        DB::table('site_visits')->upsert(
            [['site_meta_id' => $site->id, 'path' => mb_substr(self::cleanPath($path), 0, 255), 'day' => now()->toDateString(), 'views' => 1]],
            ['site_meta_id', 'path', 'day'],
            ['views' => DB::raw('site_visits.views + 1')],
        );
    }

    /**
     * Page views over the last $days days: each day's total, the busiest pages, and the total
     * compared with the days before.
     *
     * @return array{days: int, total: int, previous: int, daily: list<array{day: string, views: int}>, pages: list<array{path: string, views: int}>}
     */
    public static function stats(Meta $site, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('site_visits')->where('site_meta_id', $site->id)->where('day', '>=', $from->toDateString());
        $byDay = (clone $rows)->groupBy('day')->selectRaw('day, SUM(views) as views')->pluck('views', 'day');
        $daily = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $key = $d->toDateString();
            $daily[] = ['day' => $key, 'views' => (int) ($byDay[$key] ?? 0)];
        }

        return [
            'days' => $days,
            'total' => (int) array_sum(array_column($daily, 'views')),
            'previous' => (int) DB::table('site_visits')->where('site_meta_id', $site->id)
                ->whereBetween('day', [$from->copy()->subDays($days)->toDateString(), $from->copy()->subDay()->toDateString()])->sum('views'),
            'daily' => $daily,
            'pages' => (clone $rows)->groupBy('path')->selectRaw('path, SUM(views) as views')->orderByDesc('views')->limit(20)->get()
                ->map(fn ($r) => ['path' => $r->path, 'views' => (int) $r->views])->all(),
        ];
    }

    /**
     * The forms visitors can send from the site's pages (placed on them, or opened by their
     * buttons and links): whether guests may send them, what was sent lately, and how many
     * notifications watch them.
     *
     * @return list<array<string, mixed>>
     */
    public static function forms(Meta $site, User $user): array
    {
        $entries = self::entries($site);
        $paths = self::paths($entries);
        $formType = MetaType::where('code', 'form')->value('id');
        $found = [];
        foreach ($entries as $entry) {
            foreach (SiteChecks::metaIds(self::snapshot($entry, $user)) as $id) {
                $found[$id][] = $paths[$entry->id];
            }
        }
        $forms = Meta::whereIn('id', array_keys($found))->where('type_id', $formType)->get();
        $watching = DB::table('meta_notification_map')->whereIn('meta_id', $forms->pluck('id'))->groupBy('meta_id')->selectRaw('meta_id, COUNT(*) as n')->pluck('n', 'meta_id');

        return $forms->map(function (Meta $form) use ($found, $watching) {
            $table = $form->table_name;
            $counted = $table && Schema::hasTable($table) && Schema::hasColumn($table, 'created_at');

            return [
                'id' => $form->id,
                'code' => $form->code,
                'name' => $form->name,
                'is_public' => (bool) $form->is_public,
                'pages' => array_values(array_unique($found[$form->id])),
                'sent_30_days' => $counted ? DB::table($table)->where('created_at', '>=', now()->subDays(30))->count() : null,
                'sent_total' => $table && Schema::hasTable($table) ? DB::table($table)->count() : null,
                'last_sent_at' => $counted ? DB::table($table)->max('created_at') : null,
                'notifications' => (int) ($watching[$form->id] ?? 0),
            ];
        })->values()->all();
    }

    /** A theme meta's settings, or the default theme's, for the site's look. */
    public static function theme(?int $themeMetaId): array
    {
        $themes = Themes::all();
        $found = collect($themes['themes'])->firstWhere('id', $themeMetaId) ?? collect($themes['themes'])->firstWhere('id', $themes['default_id']);

        return $found['theme'] ?? Themes::BASE;
    }

    /** A host name as stored: lower case, no scheme, port, path or trailing dot. */
    public static function host(string $input): string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = preg_replace('#[/?\#].*$#', '', $host);

        return rtrim(preg_replace('/:\d+$/', '', $host), '.');
    }

    public static function cleanPath(string $path): string
    {
        $parts = array_filter(explode('/', strtolower(trim($path))), fn ($p) => $p !== '');

        return '/'.implode('/', $parts);
    }

    /** @return array<string, mixed> */
    private static function normalize(array $input): array
    {
        $settings = self::BASE;
        foreach (['title', 'tagline', 'description', 'logo', 'footer', 'announcement'] as $key) {
            $settings[$key] = self::text($input[$key] ?? null);
        }
        abort_if($settings['logo'] && ! preg_match('#^(https?://|/)#i', $settings['logo']), 422, 'The logo must be a web address.');
        $lang = self::text($input['lang'] ?? null);
        abort_if($lang && ! preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $lang), 422, __('":value" isn\'t a language code like en or mn.', ['value' => $lang]));
        $settings['lang'] = $lang ?? 'en';
        $look = self::text($input['look'] ?? null) ?? SiteLooks::DEFAULT;
        abort_unless(in_array($look, SiteLooks::SLUGS, true), 422, __('":value" isn\'t one of the website looks.', ['value' => $look]));
        $settings['look'] = $look;
        $settings['brand_accent'] = (bool) ($input['brand_accent'] ?? true);
        $settings['header'] = self::text($input['header'] ?? null) ?? 'masthead';
        abort_unless(in_array($settings['header'], self::HEADERS, true), 422, __('":value" isn\'t a header style.', ['value' => $settings['header']]));
        $settings['footer_style'] = self::text($input['footer_style'] ?? null) ?? 'letter';
        abort_unless(in_array($settings['footer_style'], self::FOOTERS, true), 422, __('":value" isn\'t a footer style.', ['value' => $settings['footer_style']]));
        $social = is_array($input['social'] ?? null) ? $input['social'] : [];
        foreach (self::SOCIALS as $network) {
            $url = self::text($social[$network] ?? null);
            abort_if($url && ! preg_match('#^https?://\S+$#i', $url), 422, __('The :network link must be a web address starting with https://.', ['network' => ucfirst($network)]));
            if ($url) {
                $settings['social'][$network] = $url;
            }
        }
        if (! empty($input['theme_meta_id'])) {
            $theme = Meta::whereKey((int) $input['theme_meta_id'])->where('type_id', MetaType::where('code', 'theme')->value('id'))->exists();
            abort_unless($theme, 422, 'Pick a theme meta for the site\'s look.');
            $settings['theme_meta_id'] = (int) $input['theme_meta_id'];
        }

        return $settings;
    }

    private static function saveDomains(Meta $site, array $domains): void
    {
        $hosts = [];
        $primary = null;
        foreach ($domains as $d) {
            $host = self::host((string) (is_array($d) ? ($d['host'] ?? '') : $d));
            if ($host === '') {
                continue;
            }
            abort_unless(preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host), 422, __('":host" isn\'t a host name like www.company.mn.', ['host' => $host]));
            $other = SiteDomain::where('host', $host)->where('site_meta_id', '!=', $site->id)->with('site:id,name')->first();
            abort_if($other, 422, __(':host is already used by the website ":name".', ['host' => $host, 'name' => $other?->site?->name]));
            $hosts[$host] = true;
            if (is_array($d) && ! empty($d['is_primary']) && $primary === null) {
                $primary = $host;
            }
        }
        $primary ??= array_key_first($hosts);

        SiteDomain::where('site_meta_id', $site->id)->whereNotIn('host', array_keys($hosts))->delete();
        foreach (array_keys($hosts) as $host) {
            SiteDomain::updateOrCreate(['host' => $host], ['site_meta_id' => $site->id, 'is_primary' => $host === $primary]);
        }
    }

    private static function savePages(Meta $site, array $pages, User $user): void
    {
        $pageType = MetaType::where('code', 'page')->value('id');
        $existing = MetaParam::where('main_meta_id', $site->id)->get()->keyBy('id');
        $kept = [];
        $order = 0;
        $homes = 0;

        $walk = function (array $items, ?int $parentId, int $depth) use (&$walk, &$kept, &$order, &$homes, $site, $user, $existing, $pageType) {
            abort_if($depth > self::MAX_DEPTH, 422, 'Pages can only sit '.self::MAX_DEPTH.' deep.');
            $slugs = [];
            foreach ($items as $item) {
                abort_unless(is_array($item), 422, 'Each page must be an object.');
                $label = self::text($item['label'] ?? null);
                $slug = Str::slug((string) ($item['slug'] ?? ''));
                if ($slug === '' && ($parentId !== null || ! array_key_exists('slug', $item) || $homes > 0)) {
                    $slug = Str::slug((string) $label) ?: 'page';
                }
                if ($slug === '') {
                    $homes++;
                    abort_if(! empty($item['children']), 422, 'The home page can\'t have pages under it.');
                }
                abort_if(isset($slugs[$slug]), 422, __('Two pages here have the address ":slug".', ['slug' => $slug === '' ? '/' : $slug]));
                $slugs[$slug] = true;

                $pageId = isset($item['page_meta_id']) ? (int) $item['page_meta_id'] : null;
                if (! $pageId && ! empty($item['new_page'])) {
                    $pageId = self::newPage($site, $label ?? $slug, $slug, $pageType, $user)->id;
                }
                abort_if($pageId && ! Meta::whereKey($pageId)->where('type_id', $pageType)->exists(), 422, __('":label" must show a page meta.', ['label' => $label ?? $slug]));

                $param = isset($item['param_id']) ? $existing->get((int) $item['param_id']) : null;
                abort_if(isset($item['param_id']) && ! $param, 422, 'A page of this site no longer exists. Reload it.');
                $fields = [
                    'main_meta_id' => $site->id,
                    'ref_meta_id' => $pageId,
                    'parent_id' => $parentId,
                    'order_number' => ++$order,
                    'label_name' => $label,
                    'is_show' => (bool) ($item['in_menu'] ?? true),
                    'data_type' => 'site_page',
                    'field_path' => $slug === '' ? 'home' : $slug,
                    'param_name' => $slug === '' ? 'home' : $slug,
                    'json_config' => array_filter([
                        'slug' => $slug,
                        'title' => self::text($item['title'] ?? null),
                        'description' => self::text($item['description'] ?? null),
                        'image' => self::text($item['image'] ?? null),
                        'in_footer' => isset($item['in_footer']) ? (bool) $item['in_footer'] : null,
                    ], fn ($v) => $v !== null),
                ];
                $param ? $param->update($fields) : $param = MetaParam::create($fields);
                $kept[] = $param->id;
                $walk(is_array($item['children'] ?? null) ? $item['children'] : [], $param->id, $depth + 1);
            }
        };
        $walk($pages, null, 1);

        MetaParam::where('main_meta_id', $site->id)->whereNotIn('id', $kept)->delete();
    }

    private static function newPage(Meta $site, string $name, string $slug, int $pageType, User $user): Meta
    {
        $base = $site->code.'_'.($slug === '' ? 'home' : str_replace('-', '_', $slug));
        $code = $base;
        for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }

        return Meta::create([
            'code' => $code,
            'name' => $name.' · '.$site->name,
            'label_name' => $name,
            'type_id' => $pageType,
            'description' => __('A page of the website :name.', ['name' => $site->name]),
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /**
     * Rows with parent_id as a tree under children.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function nest(array $rows, ?int $parentId): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === $parentId) {
                $out[] = $row + ['children' => self::nest($rows, $row['param_id'])];
            }
        }

        return $out;
    }

    /**
     * Page items as plain data: the library entry without its timestamps, so a snapshot only
     * changes when the page does.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function plain(array $items): array
    {
        return array_map(fn (array $item) => [
            ...$item,
            'widget' => collect($item['widget'])->only(['id', 'code', 'name', 'category', 'renderer', 'template_meta_id', 'icon', 'props'])->all(),
            'meta' => json_decode(json_encode($item['meta']), true),
            'children' => self::plain($item['children']),
        ], $items);
    }

    /** @param array<int, array> $templates */
    private static function collectTemplates(array $items, User $user, array &$templates): void
    {
        foreach ($items as $item) {
            $templateId = $item['widget']['renderer'] === 'composite' ? (int) ($item['widget']['template_meta_id'] ?? 0) : 0;
            if ($templateId && ! isset($templates[$templateId]) && ($template = Meta::find($templateId))) {
                $templates[$templateId] = [];
                $templates[$templateId] = self::plain(PageLayout::items($template, $user));
                self::collectTemplates($templates[$templateId], $user, $templates);
            }
            self::collectTemplates($item['children'], $user, $templates);
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
