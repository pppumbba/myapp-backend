<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\SiteDomain;
use App\Models\SiteRelease;
use App\Support\Access;
use App\Support\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Website management app's pages that look across every website at once (admins): an
 * overview, releases and the publishing schedule, domains, what visitors sent through the
 * sites' forms, and visits. One site's own pages and settings stay with SiteController.
 */
class SiteDeskController extends Controller
{
    /** How many rows a page of releases or messages holds. */
    private const PAGE = 20;

    /**
     * GET /sites/overview: counts across the sites, what is about to go out and what went out
     * lately, and the busiest sites in the last 30 days.
     */
    public function overview(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $sites = $this->sites();
        $ids = $sites->pluck('id');
        $from = now()->subDays(29)->toDateString();
        $views = DB::table('site_visits')->whereIn('site_meta_id', $ids)->where('day', '>=', $from)
            ->groupBy('site_meta_id')->selectRaw('site_meta_id, SUM(views) as views')->pluck('views', 'site_meta_id');
        $previous = (int) DB::table('site_visits')->whereIn('site_meta_id', $ids)
            ->whereBetween('day', [now()->subDays(59)->toDateString(), now()->subDays(30)->toDateString()])->sum('views');
        $releases = SiteRelease::with('publisher:id,name')->whereIn('site_meta_id', $ids);

        return response()->json([
            'sites' => $sites->count(),
            'active' => $sites->where('is_active', true)->count(),
            'pages' => MetaParam::whereIn('main_meta_id', $ids)->count(),
            'live_pages' => $sites->sum(fn (Meta $site) => count(Sites::live($site))),
            'domains' => SiteDomain::whereIn('site_meta_id', $ids)->count(),
            'scheduled' => (clone $releases)->where('publish_at', '>', now())->count(),
            'views' => ['days' => 30, 'total' => (int) $views->sum(), 'previous' => $previous],
            'messages_30_days' => $this->forms($sites)->sum(fn (array $form) => $form['counted'] && $form['is_public']
                ? DB::table($form['table'])->where('created_at', '>=', now()->subDays(30))->count() : 0),
            'upcoming' => $this->releaseRows((clone $releases)->where('publish_at', '>', now())->orderBy('publish_at')->limit(5)->get(), $sites),
            'recent' => $this->releaseRows((clone $releases)->where('publish_at', '<=', now())->orderByDesc('publish_at')->orderByDesc('id')->limit(8)->get(), $sites),
            'top_sites' => $sites->map(fn (Meta $site) => ['id' => $site->id, 'name' => $site->name, 'views' => (int) ($views[$site->id] ?? 0)])
                ->sortByDesc('views')->take(5)->values(),
        ]);
    }

    /**
     * GET /site-releases?status=all|scheduled|history&site_id=&q=&page=: releases across the
     * sites, 20 at a time. Scheduled ones soonest first, the rest latest first.
     */
    public function releases(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'status' => ['nullable', 'in:all,scheduled,history'],
            'site_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $sites = $this->sites();
        $status = $data['status'] ?? 'all';
        $query = SiteRelease::with('publisher:id,name')->whereIn('site_meta_id', $sites->pluck('id'))
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->where('site_meta_id', $id))
            ->when(trim($data['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q->where('path', 'like', '%'.trim($data['q']).'%')->orWhere('note', 'like', '%'.trim($data['q']).'%')));
        $counts = ['scheduled' => (clone $query)->where('publish_at', '>', now())->count(), 'history' => (clone $query)->where('publish_at', '<=', now())->count()];
        $query = match ($status) {
            'scheduled' => $query->where('publish_at', '>', now())->orderBy('publish_at'),
            'history' => $query->where('publish_at', '<=', now())->orderByDesc('publish_at'),
            default => $query->orderByRaw('CASE WHEN publish_at > ? THEN 0 ELSE 1 END', [now()])->orderByDesc('publish_at'),
        };
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count();

        return response()->json([
            'data' => $this->releaseRows($query->orderByDesc('id')->forPage($page, self::PAGE)->get(), $sites),
            'total' => $total,
            'has_more' => $total > $page * self::PAGE,
            'counts' => $counts,
        ]);
    }

    /** GET /site-domains: every site's domains, and the sites without one (reached at /site/{code}). */
    public function domains(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $sites = $this->sites()->keyBy('id');
        $domains = SiteDomain::whereIn('site_meta_id', $sites->keys())->orderBy('host')->get();

        return response()->json([
            'domains' => $domains->map(fn (SiteDomain $d) => [
                'id' => $d->id,
                'host' => $d->host,
                'is_primary' => (bool) $d->is_primary,
                'created_at' => $d->created_at?->toJSON(),
                'site' => $sites[$d->site_meta_id]->only(['id', 'code', 'name', 'is_active']),
            ])->values(),
            'without' => $sites->reject(fn (Meta $site) => $domains->contains('site_meta_id', $site->id))->map->only(['id', 'code', 'name', 'is_active'])->values(),
        ]);
    }

    /**
     * GET /site-messages?site_id=&form_id=&page=: what visitors sent through the sites' forms,
     * newest first, 20 at a time, each with its first few fields. Only public forms: the others
     * on a site's pages are the app's own data, which visitors can't send. Forms whose table has
     * no created_at can't be dated and are left out.
     */
    public function messages(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['site_id' => ['nullable', 'integer'], 'form_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $sites = $this->sites()->when($data['site_id'] ?? null, fn ($c, $id) => $c->where('id', (int) $id));
        $forms = $this->forms($sites);
        $all = $forms;
        if ($data['form_id'] ?? null) {
            $forms = $forms->where('id', (int) $data['form_id']);
        }
        $page = (int) ($data['page'] ?? 1);
        $want = $page * self::PAGE;

        $rows = collect();
        $total = 0;
        foreach ($forms->where('counted', true)->where('is_public', true) as $form) {
            $total += DB::table($form['table'])->count();
            $fields = $this->fieldsOf($form['id']);
            foreach (DB::table($form['table'])->orderByDesc('created_at')->limit($want)->get() as $row) {
                $row = (array) $row;
                $rows->push([
                    'form' => ['id' => $form['id'], 'name' => $form['name']],
                    'sites' => $form['sites'],
                    'record_id' => $row['id'] ?? null,
                    'created_at' => Carbon::parse($row['created_at'])->toJSON(),
                    'fields' => $fields->filter(fn (array $f) => array_key_exists($f['column'], $row) && $row[$f['column']] !== null && $row[$f['column']] !== '')
                        ->take(4)->map(fn (array $f) => ['label' => $f['label'], 'value' => mb_strimwidth((string) $row[$f['column']], 0, 300, '…')])->values(),
                ]);
            }
        }

        return response()->json([
            'data' => $rows->sortByDesc('created_at')->values()->forPage($page, self::PAGE)->values(),
            'total' => $total,
            'has_more' => $total > $want,
            'forms' => $all->map(fn (array $f) => ['id' => $f['id'], 'name' => $f['name'], 'sites' => $f['sites'], 'is_public' => $f['is_public'], 'dated' => $f['counted']])->values(),
        ]);
    }

    /** GET /site-visits?days=30: page views across the sites by day, by site and the busiest pages. */
    public function visits(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $days = max(7, min(365, (int) $request->query('days', 30)));
        $sites = $this->sites()->keyBy('id');
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('site_visits')->whereIn('site_meta_id', $sites->keys())->where('day', '>=', $from->toDateString());
        $byDay = (clone $rows)->groupBy('day')->selectRaw('day, SUM(views) as views')->pluck('views', 'day');
        $daily = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $daily[] = ['day' => $d->toDateString(), 'views' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }
        $before = DB::table('site_visits')->whereIn('site_meta_id', $sites->keys())
            ->whereBetween('day', [$from->copy()->subDays($days)->toDateString(), $from->copy()->subDay()->toDateString()]);
        $bySite = (clone $rows)->groupBy('site_meta_id')->selectRaw('site_meta_id, SUM(views) as views')->pluck('views', 'site_meta_id');
        $bySiteBefore = (clone $before)->groupBy('site_meta_id')->selectRaw('site_meta_id, SUM(views) as views')->pluck('views', 'site_meta_id');

        return response()->json([
            'days' => $days,
            'total' => (int) array_sum(array_column($daily, 'views')),
            'previous' => (int) $before->sum('views'),
            'daily' => $daily,
            'sites' => $sites->map(fn (Meta $site) => ['id' => $site->id, 'name' => $site->name, 'views' => (int) ($bySite[$site->id] ?? 0), 'previous' => (int) ($bySiteBefore[$site->id] ?? 0)])
                ->sortByDesc('views')->values(),
            'pages' => (clone $rows)->groupBy('site_meta_id', 'path')->selectRaw('site_meta_id, path, SUM(views) as views')->orderByDesc('views')->limit(20)->get()
                ->map(fn ($r) => ['site_id' => (int) $r->site_meta_id, 'site' => $sites[$r->site_meta_id]->name ?? '', 'path' => $r->path, 'views' => (int) $r->views]),
        ]);
    }

    /** @return Collection<int, Meta> every website, by name */
    private function sites(): Collection
    {
        return Meta::where('type_id', MetaType::where('code', Sites::TYPE)->value('id'))->orderBy('name')->get();
    }

    /**
     * Releases as the pages show them: site, address, who and when, and whether each is live
     * now, scheduled, a take-down or the page's current one.
     *
     * @param  Collection<int, SiteRelease>  $releases
     * @param  Collection<int, Meta>  $sites
     * @return list<array<string, mixed>>
     */
    private function releaseRows(Collection $releases, Collection $sites): array
    {
        $byId = $sites->keyBy('id');
        $current = [];
        foreach ($releases->pluck('site_meta_id')->unique() as $siteId) {
            $current += collect(Sites::current($byId[$siteId]))->map(fn ($r) => $r?->id)->all();
        }

        return $releases->map(fn (SiteRelease $r) => [
            'id' => $r->id,
            'site' => ['id' => $r->site_meta_id, 'name' => $byId[$r->site_meta_id]->name ?? ''],
            'param_id' => $r->param_id,
            'path' => $r->path,
            'note' => $r->note,
            'publish_at' => $r->publish_at->toJSON(),
            'created_at' => $r->created_at?->toJSON(),
            'publisher' => $r->publisher?->name,
            'scheduled' => $r->publish_at->isFuture(),
            'taken_down' => $r->snapshot === null,
            'restored' => $r->restored_from_id !== null,
            'current' => $r->param_id !== null && ($current[$r->param_id] ?? null) === $r->id,
        ])->values()->all();
    }

    /**
     * The forms visitors can send from these sites, once each, with the sites they are on and
     * whether their table can be dated (has created_at).
     *
     * @param  Collection<int, Meta>  $sites
     * @return Collection<int, array{id: int, name: string, table: ?string, counted: bool, is_public: bool, sites: list<string>}>
     */
    private function forms(Collection $sites): Collection
    {
        $forms = [];
        foreach ($sites as $site) {
            foreach (Sites::forms($site, request()->user()) as $form) {
                $forms[$form['id']] ??= ['id' => $form['id'], 'name' => $form['name'], 'is_public' => $form['is_public'], 'sites' => []];
                $forms[$form['id']]['sites'][] = $site->name;
            }
        }
        $tables = Meta::whereIn('id', array_keys($forms))->pluck('table_name', 'id');

        return collect($forms)->map(function (array $form) use ($tables) {
            $table = $tables[$form['id']] ?? null;

            return $form + [
                'table' => $table,
                'counted' => $table && Schema::hasTable($table) && Schema::hasColumn($table, 'created_at'),
            ];
        })->values();
    }

    /** @return Collection<int, array{column: string, label: string}> a form's own fields, in order */
    private function fieldsOf(int $formId): Collection
    {
        return MetaParam::where('main_meta_id', $formId)->whereNull('parent_id')->whereNotNull('field_path')->where('field_path', '!=', 'id')->where('is_show', true)
            ->orderBy('order_number')->get(['field_path', 'label_name', 'param_name'])
            ->map(fn (MetaParam $p) => ['column' => $p->field_path, 'label' => $p->label_name ?: $p->param_name]);
    }
}
