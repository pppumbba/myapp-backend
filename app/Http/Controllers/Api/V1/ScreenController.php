<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Screen metas: built-in pages placed in apps' menus. */
class ScreenController extends Controller
{
    /**
     * GET /api/screens — the screens this user may open: their address and the apps whose menu
     * holds them, so an app links to the address and an address finds its app.
     */
    public function index(Request $request): JsonResponse
    {
        $screens = Access::viewable(Meta::query(), $request->user())
            ->whereHas('type', fn ($q) => $q->where('code', 'screen'))
            ->where('is_active', true)
            ->get(['metas.id', 'metas.code', 'metas.name', 'metas.json_config']);

        $apps = MetaParam::whereIn('ref_meta_id', $screens->pluck('id'))
            ->whereHas('main.type', fn ($q) => $q->where('code', 'app'))
            ->get(['ref_meta_id', 'main_meta_id'])
            ->groupBy('ref_meta_id');

        return response()->json($screens->map(fn (Meta $screen) => [
            'id' => $screen->id,
            'code' => $screen->code,
            'name' => $screen->name,
            'path' => $screen->json_config['path'] ?? null,
            'app_ids' => $apps->get($screen->id, collect())->pluck('main_meta_id')->unique()->values()->all(),
        ])->filter(fn (array $s) => $s['path'])->values());
    }
}
