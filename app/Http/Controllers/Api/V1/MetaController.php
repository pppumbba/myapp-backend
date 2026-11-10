<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\Builders;
use App\Support\MetaVersions;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MetaController extends Controller
{
    /** Columns that may hold a service's credentials; only admins see them. */
    private const SECRET_COLUMNS = ['service_headers', 'service_list_body'];

    /** GET /metas — every meta, with its type and how many rows it has. */
    public function index(Request $request): JsonResponse
    {
        $metas = Access::viewable(Meta::query(), $request->user())->with(['type:id,code,name', 'refMeta:id,code,name', 'widget:id,code,name,renderer,icon', 'links', 'notificationLink', 'tags'])
            ->withCount('params')
            ->orderBy('name')
            ->get();

        return response()->json($metas);
    }

    /**
     * GET /metas/options — short list for dropdowns (ref_meta lookups).
     * Defined before the resource routes so "options" isn't read as an id.
     */
    public function options(Request $request): JsonResponse
    {
        $options = Access::viewable(Meta::query(), $request->user())->with('type:id,code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type_id', 'created_by'])
            ->map(fn (Meta $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'type' => $m->type?->code,
                'created_by' => $m->created_by,
            ]);

        return response()->json($options);
    }

    /** POST /metas */
    public function store(Request $request): JsonResponse
    {
        Access::requireBuilder($request->user());
        $data = $this->validated($request);
        Builders::checkMeta($request->user(), $data);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $tagIds = $this->tagIds($data);

        $meta = DB::transaction(function () use ($data, $tagIds) {
            $meta = Meta::create($data);
            if ($tagIds !== null) {
                $meta->tags()->sync($tagIds);
            }

            return $meta;
        });

        return response()->json($meta->load(['type', 'refMeta', 'widget', 'tags'])->makeVisible(self::SECRET_COLUMNS), 201);
    }

    /** GET /metas/{meta} */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $meta->load(['type', 'refMeta', 'widget', 'tags']);
        if ($request->boolean('translated')) {
            Translations::apply([$meta], 'metas');
        }

        return response()->json($request->user()?->isAdmin() ? $meta->makeVisible(self::SECRET_COLUMNS) : $meta);
    }

    /** PUT/PATCH /metas/{meta} */
    public function update(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        $data = $this->validated($request, $meta);
        Builders::checkMeta($request->user(), $data, $meta);
        $data['updated_by'] = $request->user()->id;

        $tagIds = $this->tagIds($data);

        DB::transaction(function () use ($meta, $data, $tagIds) {
            $meta->update($data);
            if ($tagIds !== null) {
                $meta->tags()->sync($tagIds);
            }
        });

        return response()->json($meta->fresh()->load(['type', 'refMeta', 'widget', 'tags'])->makeVisible(self::SECRET_COLUMNS));
    }

    /**
     * DELETE /metas/{meta}
     * Its params are removed (cascadeOnDelete). Metas that pointed at it lose the link (nullOnDelete).
     */
    public function destroy(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        // Kept as a version, so the meta can be restored.
        MetaVersions::saveIfChanged($meta->id, $request->user(), 'Before deleting');
        $meta->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Meta $current = null): array
    {
        $data = $request->validate([
            'code' => [
                $current ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('metas', 'code')->ignore($current?->id),
            ],
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_log' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_open' => ['sometimes', 'boolean'],
            'type_id' => ['nullable', 'integer', 'exists:meta_types,id'],
            'ref_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            // A widget meta's library entry (see Widget).
            'widget_id' => ['nullable', 'integer', 'exists:widgets,id'],
            'description' => ['nullable', 'string'],
            'table_name' => ['nullable', 'string', 'max:191'],
            'query_string' => ['nullable', 'string'],
            'load_expression_string' => ['nullable', 'string'],
            'event_expression_string' => ['nullable', 'string'],
            'before_save_expression_string' => ['nullable', 'string'],
            'after_save_expression_string' => ['nullable', 'string'],
            'var_fnc_expression_string' => ['nullable', 'string'],
            // An external service instead of a table (see ExternalService).
            'service_url' => ['nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\{)/i'],
            'service_headers' => ['nullable', 'json'],
            'service_query' => ['nullable', 'json'],
            'service_list_body' => ['nullable', 'json'],
            'service_list_path' => ['nullable', 'string', 'max:255'],
            'service_total_path' => ['nullable', 'string', 'max:255'],
            'service_record_path' => ['nullable', 'string', 'max:255'],
            'service_update_method' => ['nullable', Rule::in(['PUT', 'PATCH', 'put', 'patch'])],
            'json_config' => ['nullable', 'array'],
            // What the meta is for; leaving it out keeps its tags.
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
        ]);

        // An app built into its own phone app (MobileController): the Android package id names it in
        // the store, so no two apps may share one. Checked apart, as nested rules would drop json_config's other keys.
        if (is_array($data['json_config'] ?? null) && array_key_exists('mobile', $data['json_config'])) {
            $request->validate([
                'json_config.mobile' => ['sometimes', 'nullable', 'array'],
                'json_config.mobile.enabled' => ['sometimes', 'boolean'],
                'json_config.mobile.name' => ['nullable', 'string', 'max:30'],
                'json_config.mobile.package_id' => [
                    'nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
                    function (string $attribute, mixed $value, \Closure $fail) use ($current) {
                        $taken = Meta::query()->when($current, fn ($q) => $q->whereKeyNot($current->id))
                            ->where('json_config->mobile->package_id', $value)->exists();
                        if ($taken) {
                            $fail(__('Another app already uses this package id.'));
                        }
                    },
                ],
                'json_config.mobile.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                // Set by POST /metas/{meta}/mobile-icon; a save may only keep or clear it, never point elsewhere.
                'json_config.mobile.icon' => ['nullable', 'string', 'regex:'.MobileController::ICON_FILE, Rule::in(array_filter([$current ? MobileController::iconFile($current) : null]))],
            ]);
        }

        if (! empty($data['ref_meta_id']) && $current && (int) $data['ref_meta_id'] === $current->id) {
            abort(422, 'A meta cannot reference itself.');
        }

        return $data;
    }

    /**
     * Takes tag_ids out of the validated data: the tags to set, or null to keep them.
     *
     * @return list<int>|null
     */
    private function tagIds(array &$data): ?array
    {
        $ids = array_key_exists('tag_ids', $data) ? array_map('intval', $data['tag_ids'] ?? []) : null;
        unset($data['tag_ids']);

        return $ids;
    }
}
