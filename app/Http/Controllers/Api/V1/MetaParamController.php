<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\Access;
use App\Support\Autonumber;
use App\Support\Criteria;
use App\Support\FilterLookups;
use App\Support\GridReuse;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class MetaParamController extends Controller
{
    /**
     * GET /metas/{meta}/params?effective=1
     * Returns the rows of one meta as a nested tree (roots first, children inside). With
     * effective, a datagrid that reuses another gets the params it shows with: its bases'
     * merged with its own (GridReuse). Without it, only its own, as the builder edits them.
     */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');

        $rows = $request->boolean('effective') && GridReuse::reuses($meta)
            ? GridReuse::params($meta)
            : MetaParam::where('main_meta_id', $meta->id)
                ->with('links')
                ->orderBy('order_number')
                ->get();

        // A filter without a lookup or list of its own picks with its form field's (filter_as).
        foreach (FilterLookups::borrowed($meta, $rows) as $field => $formField) {
            $rows->firstWhere('field_path', $field)?->setAttribute('filter_as', FilterLookups::describe($formField));
        }

        // A screen asks for its labels in the user's language (see Translations).
        if ($request->boolean('translated')) {
            Translations::apply($rows, 'meta_params');
        }

        return response()->json($this->buildTree($rows));
    }

    /** POST /metas/{meta}/params */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request, $meta);
        $data['main_meta_id'] = $meta->id;

        $param = MetaParam::create($data);

        return response()->json($param, 201);
    }

    /** GET /params/{param} */
    public function show(Request $request, MetaParam $param): JsonResponse
    {
        Access::require($request->user(), $param->main_meta_id, 'view');

        return response()->json($param->load('children'));
    }

    /** PUT/PATCH /params/{param} */
    public function update(Request $request, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request, $param->main, $param);

        $param->update($data);

        return response()->json($param->fresh());
    }

    /** DELETE /params/{param} — children are removed too (cascadeOnDelete). */
    public function destroy(Request $request, MetaParam $param): JsonResponse
    {
        Access::requireAdmin($request->user());
        $param->delete();

        return response()->json(null, 204);
    }

    /**
     * Shared validation for store and update.
     * $current is set on update so a row can't become its own ancestor.
     */
    private function validated(Request $request, Meta $owner, ?MetaParam $current = null): array
    {
        $data = $request->validate([
            'order_number' => ['sometimes', 'integer', 'min:0'],
            'parent_id' => [
                'nullable',
                Rule::exists('meta_params', 'id')->where('main_meta_id', $owner->id),
            ],
            'field_path' => ['sometimes', 'string', 'max:255'],
            'param_name' => ['sometimes', 'string', 'max:255'],
            'column_path' => ['nullable', 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'data_type' => ['sometimes', 'string', 'max:50'],
            'data_mask' => ['nullable', 'string', 'max:255'],
            'render_type' => ['nullable', 'string', 'max:50'],
            'icon_cls' => ['nullable', 'string', 'max:255'],
            'ref_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_show' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
            'is_criteria' => ['sometimes', 'boolean'],
            'is_unique' => ['sometimes', 'boolean'],
            'is_merge' => ['sometimes', 'boolean'],
            'is_select' => ['sometimes', 'boolean'],
            'is_mandatory_criteria' => ['sometimes', 'boolean'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'min_value' => ['nullable', 'string', 'max:255'],
            'max_value' => ['nullable', 'string', 'max:255'],
            'column_width' => ['nullable', 'string', 'max:50'],
            'expression_string' => ['nullable', 'string'],
            'json_config' => ['nullable', 'array'],
            'display_field' => ['nullable', 'string', 'max:255'],
            'value_field' => ['nullable', 'string', 'max:255'],
            'is_label' => ['sometimes', 'boolean'],
            'is_parent' => ['sometimes', 'boolean'],
        ]);

        // A row can't point at itself as its parent, and no row can be placed under its own descendant.
        if (! empty($data['parent_id']) && $current) {
            if ((int) $data['parent_id'] === $current->id || $this->isDescendant((int) $data['parent_id'], $current->id)) {
                abort(422, 'A row cannot be moved under itself or one of its children.');
            }
        }

        $problem = Autonumber::problem($data['json_config']['autonumber'] ?? null);
        // A detail's criteria (when a save takes it) must at least read.
        if ($problem === null && isset($data['json_config']['criteria'])) {
            try {
                Criteria::check(is_string($data['json_config']['criteria']) ? $data['json_config']['criteria'] : throw new InvalidArgumentException('The criteria must be text.'));
            } catch (InvalidArgumentException $e) {
                $problem = $e->getMessage();
            }
        }
        if ($problem !== null) {
            abort(response()->json(['message' => $problem, 'errors' => ['json_config' => [$problem]]], 422));
        }

        // A row can't reference the meta it belongs to (that would open the screen inside itself).
        if (! empty($data['ref_meta_id']) && (int) $data['ref_meta_id'] === $owner->id) {
            abort(422, 'ref_meta_id cannot point at the meta this row belongs to.');
        }

        return $data;
    }

    /** True if $candidateId is $ancestorId or sits anywhere below it. */
    private function isDescendant(int $candidateId, int $ancestorId): bool
    {
        $id = $candidateId;
        $steps = 0;

        while ($id !== null && $steps++ < 1000) {
            if ($id === $ancestorId) {
                return true;
            }
            $id = MetaParam::whereKey($id)->value('parent_id');
        }

        return false;
    }

    /** Turns a flat, ordered list into roots with a `children` array, recursively. */
    private function buildTree($rows, $parentId = null)
    {
        return $rows
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (MetaParam $row) use ($rows) {
                $row->setAttribute('children', $this->buildTree($rows, $row->id));

                return $row;
            });
    }
}
