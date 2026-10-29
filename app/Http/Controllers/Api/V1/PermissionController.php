<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\WfmStatus;
use App\Models\WfmStatusPermission;
use App\Support\Access;
use App\Support\RoleLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Which roles may do what with a meta, and with a workflow's statuses. Admins manage them;
 * saving replaces all of the rows, and saving none opens the meta or statuses to everyone.
 */
class PermissionController extends Controller
{
    /** GET /metas/{meta}/permissions */
    public function meta(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(MetaPermission::where('meta_id', $meta->id)->orderBy('role_id')->get());
    }

    /** PUT /metas/{meta}/permissions {rows: [{role_id, can_view, can_create, can_edit, can_delete}]} */
    public function saveMeta(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'rows' => ['present', 'array'],
            'rows.*.role_id' => ['required', 'integer', 'distinct', 'exists:roles,id'],
            'rows.*.can_view' => ['sometimes', 'boolean'],
            'rows.*.can_create' => ['sometimes', 'boolean'],
            'rows.*.can_edit' => ['sometimes', 'boolean'],
            'rows.*.can_delete' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $meta, $data) {
            $before = MetaPermission::where('meta_id', $meta->id)->get();
            MetaPermission::where('meta_id', $meta->id)->delete();
            foreach ($data['rows'] as $row) {
                MetaPermission::create($row + ['meta_id' => $meta->id]);
            }
            RoleLog::metas($before, MetaPermission::where('meta_id', $meta->id)->get(), $request->user());
        });

        return $this->meta($request, $meta);
    }

    /** GET /workflows/{meta}/permissions: the rows of every status of this workflow. */
    public function workflow(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $statuses = WfmStatus::where('workflow_meta_id', $meta->id)->pluck('id');

        return response()->json(WfmStatusPermission::whereIn('status_id', $statuses)->orderBy('status_id')->orderBy('role_id')->get());
    }

    /** PUT /workflows/{meta}/permissions {rows: [{status_id, role_id, can_enter, can_see}]} */
    public function saveWorkflow(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $statuses = WfmStatus::where('workflow_meta_id', $meta->id)->pluck('id');
        $data = $request->validate([
            'rows' => ['present', 'array'],
            'rows.*.status_id' => ['required', 'integer', Rule::in($statuses->all())],
            'rows.*.role_id' => ['required', 'integer', 'exists:roles,id'],
            'rows.*.can_enter' => ['sometimes', 'boolean'],
            'rows.*.can_see' => ['sometimes', 'boolean'],
        ]);

        $pairs = collect($data['rows'])->map(fn ($r) => "{$r['status_id']}:{$r['role_id']}");
        abort_if($pairs->duplicates()->isNotEmpty(), 422, 'Each role can only appear once per status.');

        DB::transaction(function () use ($request, $statuses, $data) {
            $before = WfmStatusPermission::whereIn('status_id', $statuses)->get();
            WfmStatusPermission::whereIn('status_id', $statuses)->delete();
            foreach ($data['rows'] as $row) {
                WfmStatusPermission::create($row);
            }
            RoleLog::statuses($before, WfmStatusPermission::whereIn('status_id', $statuses)->get(), $request->user());
        });

        return $this->workflow($request, $meta);
    }
}
