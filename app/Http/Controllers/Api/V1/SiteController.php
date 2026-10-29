<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\SiteDomain;
use App\Models\SiteRelease;
use App\Support\Access;
use App\Support\SiteChecks;
use App\Support\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The website manager (admins): websites, their pages, domains and settings, checking and
 * publishing pages, their release history, the forms on them and visit counts. Visitors read
 * sites through PublicSiteController.
 */
class SiteController extends Controller
{
    /** GET /sites — every website, with its address, pages and visits in the last 7 days. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $sites = Meta::where('type_id', MetaType::where('code', Sites::TYPE)->value('id'))->orderBy('name')->get();
        $views = DB::table('site_visits')->whereIn('site_meta_id', $sites->pluck('id'))->where('day', '>=', now()->subDays(6)->toDateString())
            ->groupBy('site_meta_id')->selectRaw('site_meta_id, SUM(views) as views')->pluck('views', 'site_meta_id');
        $hosts = SiteDomain::whereIn('site_meta_id', $sites->pluck('id'))->orderByDesc('is_primary')->orderBy('id')->get()->groupBy('site_meta_id');

        return response()->json($sites->map(fn (Meta $site) => [
            'id' => $site->id,
            'code' => $site->code,
            'name' => $site->name,
            'is_active' => $site->is_active,
            'title' => Sites::settings($site)['title'],
            'host' => $hosts->get($site->id)?->first()?->host,
            'pages' => MetaParam::where('main_meta_id', $site->id)->count(),
            'live' => count(Sites::live($site)),
            'views_7_days' => (int) ($views[$site->id] ?? 0),
        ])->values());
    }

    /** POST /sites {name, code?} — a new website with an empty home page. */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'unique:metas,code'],
        ]);
        $user = $request->user();

        $site = DB::transaction(function () use ($data, $user) {
            $code = $data['code'] ?? null;
            if (! $code) {
                $base = 'site_'.(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($data['name'])), '_') ?: 'web');
                $code = $base;
                for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
                    $code = "{$base}_{$n}";
                }
            }
            $site = Meta::create([
                'code' => $code,
                'name' => $data['name'],
                'type_id' => MetaType::where('code', Sites::TYPE)->value('id'),
                'json_config' => ['site' => Sites::BASE],
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            Sites::save($site, ['pages' => [['label' => 'Home', 'slug' => '', 'new_page' => true]]], $user);

            return $site;
        });

        return response()->json(Sites::show($site, $user), 201);
    }

    /** GET /sites/{meta} — settings, domains and pages with where each stands. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        Sites::requireSite($meta);

        return response()->json(Sites::show($meta, $request->user()) + ['issues' => SiteChecks::site($meta)]);
    }

    /** PUT /sites/{meta} {name?, site?, domains?, pages?} — see Sites::save. */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        Sites::requireSite($meta);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'site' => ['sometimes', 'array'],
            'domains' => ['sometimes', 'array'],
            'pages' => ['sometimes', 'array'],
        ]);
        DB::transaction(fn () => Sites::save($meta, $data, $request->user()));

        return $this->show($request, $meta->fresh());
    }

    /** GET /sites/{meta}/pages/{param}/check — what the checks find in the page's draft. */
    public function check(Request $request, Meta $meta, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireEntry($meta, $param);

        return response()->json(['issues' => SiteChecks::page($meta, $param, Sites::snapshot($param, $request->user()))]);
    }

    /** POST /sites/{meta}/pages/{param}/publish {publish_at?, note?} — now, or at a time. */
    public function publish(Request $request, Meta $meta, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireEntry($meta, $param);
        $data = $request->validate([
            'publish_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $at = isset($data['publish_at']) ? Carbon::parse($data['publish_at']) : null;
        abort_if($at && $at->isPast() && $at->diffInMinutes(now()) > 1, 422, 'Pick a time still to come, or publish now.');

        return response()->json(Sites::publish($meta, $param, $request->user(), $at, $data['note'] ?? null), 201);
    }

    /** POST /sites/{meta}/pages/{param}/unpublish {note?} — takes the page down now. */
    public function unpublish(Request $request, Meta $meta, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireEntry($meta, $param);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(Sites::unpublish($meta, $param, $request->user(), $data['note'] ?? null), 201);
    }

    /** GET /sites/{meta}/pages/{param}/releases — the page's releases, latest first. */
    public function releases(Request $request, Meta $meta, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireEntry($meta, $param);
        $current = Sites::current($meta)[$param->id] ?? null;

        return response()->json(SiteRelease::with('publisher:id,name')->where('param_id', $param->id)
            ->orderByDesc('publish_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (SiteRelease $r) => [
                ...$r->only(['id', 'path', 'note', 'checks', 'restored_from_id', 'publish_at', 'created_at']),
                'live' => $r->isLive(),
                'current' => $r->id === $current?->id,
                'scheduled' => $r->publish_at->isFuture(),
                'publisher' => $r->publisher?->name,
            ]));
    }

    /** POST /site-releases/{release}/restore — publishes that release again now. */
    public function restore(Request $request, SiteRelease $release): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Sites::restore($release, $request->user()), 201);
    }

    /** DELETE /site-releases/{release} — cancels a release that hasn't gone out yet. */
    public function cancel(Request $request, SiteRelease $release): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_unless($release->publish_at->isFuture(), 422, 'Only a scheduled release can be cancelled; restore an older one instead.');
        $release->delete();

        return response()->json(null, 204);
    }

    /** GET /sites/{meta}/preview?path= — a page as it is in the builder, before it is published. */
    public function preview(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        Sites::requireSite($meta);

        return response()->json(Sites::page($meta, (string) $request->query('path', '/'), $request->user()));
    }

    /** GET /sites/{meta}/stats?days=30 — page views by day and by page. */
    public function stats(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        Sites::requireSite($meta);
        $days = max(7, min(365, (int) $request->query('days', 30)));

        return response()->json(Sites::stats($meta, $days));
    }

    /** GET /sites/{meta}/forms — the forms visitors can send from the site. */
    public function forms(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        Sites::requireSite($meta);

        return response()->json(Sites::forms($meta, $request->user()));
    }

    private function requireEntry(Meta $meta, MetaParam $param): void
    {
        Sites::requireSite($meta);
        abort_unless($param->main_meta_id === $meta->id, 404);
    }
}
