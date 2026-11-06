<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\Structure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A structure meta's table, for admins: what applying would change, and applying it (see Structure). */
class MetaStructureController extends Controller
{
    /** GET /metas/{meta}/structure */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireStructure($meta);

        return response()->json(Structure::plan($meta));
    }

    /** POST /metas/{meta}/structure/apply {make_screens} */
    public function apply(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireStructure($meta);
        $data = $request->validate(['make_screens' => ['sometimes', 'boolean']]);

        $done = Structure::apply($meta, (bool) ($data['make_screens'] ?? false), $request->user());

        return response()->json($done + ['plan' => Structure::plan($meta)]);
    }

    private function requireStructure(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'structure', 422, 'Only structure metas make tables.');
    }
}
