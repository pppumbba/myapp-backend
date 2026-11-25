<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaVersion;
use App\Support\Access;
use App\Support\MetaVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Version control for metas, admins only (see App\Support\MetaVersions). */
class MetaVersionController extends Controller
{
    /**
     * GET /metas/{meta}/versions: newest first. current_version is the version the meta is
     * exactly like now; none means it has changes no version holds yet.
     */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $matching = MetaVersions::matching($meta->id);

        return response()->json([
            'versions' => MetaVersion::where('meta_id', $meta->id)->with('creator:id,name')->orderByDesc('version_no')->get(),
            'current_version' => $matching?->version_no,
            'changed' => $matching === null,
        ]);
    }

    /** POST /metas/{meta}/versions {note}: saves the meta as it is now. */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        $matching = MetaVersions::matching($meta->id);
        abort_if($matching !== null, 422, __('Nothing to save: it is the same as version :version.', ['version' => $matching?->version_no]));

        return response()->json(MetaVersions::save($meta->id, $request->user(), $note)->load('creator:id,name'), 201);
    }

    /**
     * GET /meta-versions/{version}/diff?against=current|{version id}
     * What changed from this version to the other (by default, to the meta as it is now).
     */
    public function diff(Request $request, MetaVersion $version): JsonResponse
    {
        Access::requireAdmin($request->user());
        $against = $request->query('against', 'current');

        if ($against === 'current') {
            $to = MetaVersions::snapshot($version->meta_id);
            abort_if($to === null, 422, 'This meta has been deleted; restore it to compare.');
        } else {
            $other = MetaVersion::where('meta_id', $version->meta_id)->findOrFail((int) $against);
            $to = $other->snapshot;
        }

        return response()->json(MetaVersions::diff($version->snapshot, $to));
    }

    /** POST /meta-versions/{version}/restore. A changed meta is saved as a version first. */
    public function restore(Request $request, MetaVersion $version): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(MetaVersions::restore($version, $request->user()));
    }

    /** How many deleted metas a page of GET /meta-versions/deleted holds. */
    private const DELETED_PAGE = 20;

    /**
     * GET /meta-versions/deleted?q=&type_id=&tag_ids=1,2&page=1: metas that are gone, newest first,
     * each as its newest version with the type, description and tags it had (from the snapshot).
     * A page holds 20; q matches name, code or who deleted it, and a meta must have every tag.
     * type_counts and tag_counts are over every deleted meta, for the filters.
     *
     * @return JsonResponse {data, total, has_more, type_counts, tag_counts}
     */
    public function deleted(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type_id' => ['nullable', 'integer'],
            'tag_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $newest = MetaVersion::query()->select(DB::raw('MAX(id)'))->whereNotIn('meta_id', Meta::query()->select('id'))->groupBy('meta_id');
        $all = MetaVersion::whereIn('id', $newest)->with('creator:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(function (MetaVersion $v) {
                $meta = $v->snapshot['meta'] ?? [];

                return $v->toArray() + [
                    'type_id' => isset($meta['type_id']) ? (int) $meta['type_id'] : null,
                    'description' => $meta['description'] ?? null,
                    'tag_ids' => array_values(array_map(fn (array $row) => (int) $row['tag_id'], $v->snapshot['parts']['tags'] ?? [])),
                ];
            });

        $q = mb_strtolower(trim($data['q'] ?? ''));
        $tagIds = isset($data['tag_ids']) ? array_map('intval', explode(',', $data['tag_ids'])) : [];
        $shown = $all->filter(fn (array $v) => (empty($data['type_id']) || $v['type_id'] === (int) $data['type_id'])
            && ! array_diff($tagIds, $v['tag_ids'])
            && ($q === '' || collect([$v['name'], $v['code'], $v['creator']['name'] ?? ''])->contains(fn ($s) => str_contains(mb_strtolower((string) $s), $q))))->values();

        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'data' => $shown->forPage($page, self::DELETED_PAGE)->values(),
            'total' => $shown->count(),
            'has_more' => $shown->count() > $page * self::DELETED_PAGE,
            'type_counts' => (object) $all->whereNotNull('type_id')->countBy('type_id')->all(),
            'tag_counts' => (object) $all->flatMap(fn (array $v) => $v['tag_ids'])->countBy()->all(),
        ]);
    }

    /**
     * DELETE /meta-versions/deleted/{metaId}: forgets a deleted meta for good, every version of
     * it, so it can no longer be restored. A meta that still exists is refused.
     */
    public function purge(Request $request, int $metaId): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if(Meta::whereKey($metaId)->exists(), 422, __('Only a deleted meta can be deleted permanently.'));
        $count = MetaVersion::where('meta_id', $metaId)->delete();
        abort_if($count === 0, 404);

        return response()->json(['deleted_versions' => $count]);
    }
}
