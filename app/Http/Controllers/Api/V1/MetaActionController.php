<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaAction;
use App\Support\Access;
use App\Support\GridReuse;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The toolbar buttons of a datagrid (see MetaAction). */
class MetaActionController extends Controller
{
    /**
     * GET /metas/{meta}/actions?effective=1
     * With effective, a grid without buttons that reuses another gets the buttons of the
     * nearest base that has some (GridReuse); without it, only its own, as the builder edits them.
     */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $grid = $request->boolean('effective') ? GridReuse::actionsOf($meta) : $meta;

        $actions = MetaAction::where('grid_meta_id', $grid->id)
            ->with(['form:id,code,name', 'get:id,code,name'])
            ->orderBy('order_number')->orderBy('id')
            ->get();
        if ($request->boolean('translated')) {
            Translations::apply($actions, 'meta_actions');
        }

        return response()->json($actions);
    }

    /** POST /metas/{meta}/actions */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request);
        $this->requireStageQuery($data);
        $data['grid_meta_id'] = $meta->id;
        $data['order_number'] ??= (int) MetaAction::where('grid_meta_id', $meta->id)->max('order_number') + 1;

        return response()->json(MetaAction::create($data)->load(['form:id,code,name', 'get:id,code,name']), 201);
    }

    /** PUT /actions/{action} */
    public function update(Request $request, MetaAction $action): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request, true);
        $this->requireStageQuery($data + $action->only(['action', 'form_meta_id']));
        $action->update($data);

        return response()->json($action->load(['form:id,code,name', 'get:id,code,name']));
    }

    /** DELETE /actions/{action} */
    public function destroy(Request $request, MetaAction $action): JsonResponse
    {
        Access::requireAdmin($request->user());
        $action->delete();

        return response()->json(null, 204);
    }

    /**
     * A stage_query button runs a stage query meta, nothing else.
     *
     * @param  array{action?: string, form_meta_id?: int|null}  $data
     */
    private function requireStageQuery(array $data): void
    {
        if (($data['action'] ?? null) !== 'stage_query') {
            return;
        }
        $type = Meta::with('type')->find($data['form_meta_id'] ?? null)?->type?->code;
        if ($type !== 'stage_query') {
            throw ValidationException::withMessages(['form_meta_id' => __('Choose a stage query for this button.')]);
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'action' => [$req, Rule::in(MetaAction::ACTIONS)],
            'label' => [$req, 'string', 'max:255'],
            'icon_cls' => ['nullable', 'string', 'max:50'],
            // Every button works through a form: it opens it, or (delete) removes the row from its table.
            'form_meta_id' => [$req, 'integer', 'exists:metas,id'],
            'get_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'needs_row' => ['sometimes', 'boolean'],
            'show_in_toolbar' => ['sometimes', 'boolean'],
            'show_in_row' => ['sometimes', 'boolean'],
            'order_number' => ['sometimes', 'integer', 'min:1'],
            'json_config' => ['nullable', 'array'],
            'json_config.params' => ['sometimes', 'array'],
            'json_config.params.*' => ['string', 'max:255'],
        ]);
    }
}
