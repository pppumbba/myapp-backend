<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\MetaUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Where each meta is used (MetaUsage), for admins tidying up or about to change one. */
class MetaUsageController extends Controller
{
    /** GET /meta-usage — every meta with the places that use it; used_by is empty when nothing does. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $uses = MetaUsage::index();
        $metas = $this->metas();

        return response()->json($metas->map(fn (array $meta) => [...$meta, 'used_by' => $this->named($uses[$meta['id']] ?? [], $metas)])->values());
    }

    /** GET /metas/{meta}/usage — the places that use this meta. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json($this->named(MetaUsage::index()[$meta->id] ?? [], $this->metas()));
    }

    /**
     * Every meta but the page widgets (they show up as their page's), by name.
     *
     * @return Collection<int, array{id: int, code: string, name: string, is_active: bool, type: array{code: ?string, name: ?string}}>
     */
    private function metas(): Collection
    {
        return DB::table('metas')->leftJoin('meta_types', 'meta_types.id', '=', 'metas.type_id')
            ->where(fn ($q) => $q->whereNull('meta_types.code')->orWhere('meta_types.code', '!=', 'widget'))
            ->orderBy('metas.name')
            ->get(['metas.id', 'metas.code', 'metas.name', 'metas.is_active', 'meta_types.code as type_code', 'meta_types.name as type_name'])
            ->mapWithKeys(fn ($m) => [$m->id => [
                'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'is_active' => (bool) $m->is_active,
                'type' => ['code' => $m->type_code, 'name' => $m->type_name],
            ]]);
    }

    /**
     * Uses with the using meta spelled out.
     *
     * @param  list<array{meta_id: ?int, where: string}>  $uses
     * @return list<array{meta: ?array<string, mixed>, where: string}>
     */
    private function named(array $uses, Collection $metas): array
    {
        $named = array_map(fn (array $use) => ['meta' => $use['meta_id'] !== null ? ($metas[$use['meta_id']] ?? null) : null, 'where' => $use['where']], $uses);
        usort($named, fn ($a, $b) => [$a['meta']['name'] ?? "\u{FFFF}", $a['where']] <=> [$b['meta']['name'] ?? "\u{FFFF}", $b['where']]);

        return $named;
    }
}
