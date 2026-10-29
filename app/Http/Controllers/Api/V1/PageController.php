<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\PageLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Page metas, built from widgets (and widget_template metas, a composite widget's parts,
 * built the same way). The layout itself is PageLayout's; the studio saves it too.
 */
class PageController extends Controller
{
    /** GET /metas/{meta}/page — the page and its widgets as a tree, leaving out widgets the user may not view. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->requirePage($meta);

        return response()->json([
            'meta' => $meta->load('type'),
            'items' => PageLayout::items($meta, $request->user()),
        ]);
    }

    /** PUT /metas/{meta}/page — saves the whole layout (see PageLayout::save). */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requirePage($meta);
        $data = $request->validate([
            'items' => ['present', 'array'],
        ]);

        DB::transaction(fn () => PageLayout::save($meta, $data['items'], $request->user()));

        return $this->show($request, $meta);
    }

    /** Pages, and composite widgets' templates, are laid out from widgets. */
    private function requirePage(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless(in_array($meta->type?->code, PageLayout::TYPES, true), 422, 'Only page metas are built from widgets.');
    }
}
