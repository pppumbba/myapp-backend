<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\MetaCopy;
use App\Support\MetaDerive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A meta copied, replaced with another, or made into a meta of another type (admins). */
class MetaCopyController extends Controller
{
    /** POST /metas/{meta}/copy {code?, name?} — a new meta with everything this one has. */
    public function copy(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate(['code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'], 'name' => ['nullable', 'string', 'max:255']]);
        $copy = MetaCopy::copy($meta, $input['code'] ?? MetaCopy::freeCode($meta->code), $input['name'] ?? "{$meta->name} (copy)", $request->user());

        return response()->json($this->shown($copy), 201);
    }

    /** POST /metas/{meta}/replace {source_meta_id} — makes this meta the same as another, keeping its id, code and name. */
    public function replace(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate(['source_meta_id' => ['required', 'integer', 'exists:metas,id']]);
        $warnings = MetaCopy::replace($meta, Meta::with('type')->findOrFail($input['source_meta_id']), $request->user());

        return response()->json(['meta' => $this->shown($meta->fresh()), 'warnings' => $warnings]);
    }

    /** GET /metas/{meta}/derive — the types this meta can be made into. */
    public function targets(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(MetaDerive::targets($meta));
    }

    /** POST /metas/{meta}/derive {type, name?, code?} — a new meta of that type, made from this one. */
    public function derive(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate([
            'type' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $base = preg_replace('/_(grid|form|query|datagrid)$/', '', $meta->code)."_{$input['type']}";
        $code = $input['code'] ?? $base;
        for ($n = 2; ! isset($input['code']) && Meta::where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }
        $made = MetaDerive::make($meta, $input['type'], $input['name'] ?? $meta->name, $code, $request->user());

        return response()->json($this->shown($made), 201);
    }

    private function shown(Meta $meta): Meta
    {
        return $meta->load(['type:id,code,name', 'refMeta:id,code,name', 'tags']);
    }
}
