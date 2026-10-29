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

    /** GET /meta-versions/deleted: metas that are gone, each with its newest version. */
    public function deleted(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $newest = MetaVersion::query()->select(DB::raw('MAX(id)'))->whereNotIn('meta_id', Meta::query()->select('id'))->groupBy('meta_id');

        return response()->json(MetaVersion::whereIn('id', $newest)->with('creator:id,name')->orderByDesc('created_at')->get());
    }
}
