<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\User;
use App\Models\WfmStatus;
use App\Models\WfmStatusPermission;
use App\Support\Access;
use App\Support\MetaTree;
use App\Support\MetaVersions;
use App\Support\RoleLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One role on one screen, for admins: its people, what it may do with every meta, which
 * workflow statuses it may move records into and see, and the log of its changes. The
 * same rows as the permission panels on each meta, seen from the role's side.
 */
class RoleWorkspaceController extends Controller
{
    /** Meta types that never take permissions of their own (a page's placed widgets). */
    private const SKIPPED_TYPES = ['widget'];

    /** GET /roles/{role}/workspace */
    public function show(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());

        $metaRows = MetaPermission::all();
        $mine = $metaRows->where('role_id', $role->id)->keyBy('meta_id');
        $guarded = $metaRows->pluck('meta_id')->unique()->flip();
        $metas = Meta::with('type:id,code,name')
            ->whereNotIn('type_id', DB::table('meta_types')->whereIn('code', self::SKIPPED_TYPES)->select('id'))
            ->orderBy('name')->get(['id', 'code', 'name', 'type_id', 'is_active', 'is_public'])
            ->map(fn (Meta $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'type' => $m->type?->only(['code', 'name']),
                'is_active' => $m->is_active,
                'is_public' => $m->is_public,
                // Some role has rows on it; without any it is for admins only (or public).
                'guarded' => isset($guarded[$m->id]),
            ] + $this->abilities($mine->get($m->id), MetaPermission::ABILITIES));

        $statusRows = WfmStatusPermission::all();
        $statusMine = $statusRows->where('role_id', $role->id)->keyBy('status_id');
        $closed = fn (string $column) => $statusRows->where($column, true)->pluck('status_id')->unique()->flip();
        [$enterClosed, $seeClosed] = [$closed('can_enter'), $closed('can_see')];
        $workflows = Meta::whereHas('type', fn ($q) => $q->where('code', 'workflow'))->orderBy('name')->get(['id', 'code', 'name']);
        $statuses = WfmStatus::whereIn('workflow_meta_id', $workflows->pluck('id'))->orderBy('id')->get()->groupBy('workflow_meta_id');

        return response()->json([
            'role' => $role->loadCount('users'),
            'is_admin_role' => $role->code === Role::ADMIN,
            'users' => $role->users()->orderBy('name')->get(['users.id', 'name', 'email']),
            'metas' => $metas->values(),
            'workflows' => $workflows->map(fn (Meta $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'statuses' => ($statuses[$w->id] ?? collect())->map(fn (WfmStatus $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->label_name ?: $s->name,
                    'color' => $s->color,
                    // No role has rows of the kind: open to everyone.
                    'enter_open' => ! isset($enterClosed[$s->id]),
                    'see_open' => ! isset($seeClosed[$s->id]),
                ] + $this->abilities($statusMine->get($s->id), ['enter', 'see']))->values(),
            ])->filter(fn (array $w) => $w['statuses']->isNotEmpty())->values(),
        ]);
    }

    /**
     * PUT /roles/{role}/users {user_ids}: who has the role. The admin role always keeps
     * someone, so there is always someone who can hand out roles.
     */
    public function users(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        abort_if($role->code === Role::ADMIN && $data['user_ids'] === [], 422, 'There has to be at least one administrator.');

        $changes = $role->users()->sync($data['user_ids']);
        User::whereIn('id', [...$changes['attached'], ...$changes['detached']])->get()->each->forgetRoles();
        RoleLog::users($role->id, array_map('intval', $changes['attached']), array_map('intval', $changes['detached']), $request->user());

        return response()->json($role->users()->orderBy('name')->get(['users.id', 'name', 'email']));
    }

    /**
     * PUT /roles/{role}/metas {rows: [{meta_id, can_view, can_create, can_edit, can_delete}]}:
     * the role's abilities on the metas sent; others stay. A row with none is removed, and a
     * meta left with no rows at all is for admins only again. Each changed meta keeps how it
     * was as a version, like a save in its own permissions panel.
     */
    public function metas(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if($role->code === Role::ADMIN, 422, 'Administrators may do everything already.');
        $data = $request->validate([
            'rows' => ['present', 'array'],
            'rows.*.meta_id' => ['required', 'integer', 'distinct', 'exists:metas,id'],
            'rows.*.can_view' => ['sometimes', 'boolean'],
            'rows.*.can_create' => ['sometimes', 'boolean'],
            'rows.*.can_edit' => ['sometimes', 'boolean'],
            'rows.*.can_delete' => ['sometimes', 'boolean'],
        ]);
        $ids = array_column($data['rows'], 'meta_id');

        DB::transaction(function () use ($request, $role, $data, $ids) {
            $before = MetaPermission::whereIn('meta_id', $ids)->get();
            $snapshots = array_map(fn (int $id) => MetaVersions::snapshot($id), $ids);
            foreach ($data['rows'] as $row) {
                $abilities = $this->columns($row, MetaPermission::ABILITIES);
                $match = ['meta_id' => $row['meta_id'], 'role_id' => $role->id];
                in_array(true, $abilities, true)
                    ? MetaPermission::updateOrCreate($match, $abilities)
                    : MetaPermission::where($match)->delete();
            }
            RoleLog::metas($before, MetaPermission::whereIn('meta_id', $ids)->get(), $request->user());
            foreach (array_filter($snapshots) as $snapshot) {
                MetaVersions::keepBefore($snapshot, $request->user());
            }
        });

        return $this->show($request, $role);
    }

    /**
     * PUT /roles/{role}/statuses {rows: [{status_id, can_enter, can_see}]}: the role's
     * abilities on the statuses sent. Careful: the first row of a kind on a status closes it
     * to every other role.
     */
    public function statuses(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if($role->code === Role::ADMIN, 422, 'Administrators may do everything already.');
        $data = $request->validate([
            'rows' => ['present', 'array'],
            'rows.*.status_id' => ['required', 'integer', 'distinct', 'exists:wfm_status,id'],
            'rows.*.can_enter' => ['sometimes', 'boolean'],
            'rows.*.can_see' => ['sometimes', 'boolean'],
        ]);
        $ids = array_column($data['rows'], 'status_id');

        DB::transaction(function () use ($request, $role, $data, $ids) {
            $before = WfmStatusPermission::whereIn('status_id', $ids)->get();
            foreach ($data['rows'] as $row) {
                $abilities = $this->columns($row, ['enter', 'see']);
                $match = ['status_id' => $row['status_id'], 'role_id' => $role->id];
                in_array(true, $abilities, true)
                    ? WfmStatusPermission::updateOrCreate($match, $abilities)
                    : WfmStatusPermission::where($match)->delete();
            }
            RoleLog::statuses($before, WfmStatusPermission::whereIn('status_id', $ids)->get(), $request->user());
        });

        return $this->show($request, $role);
    }

    /** GET /meta-tree: metas as parents and children (see MetaTree), for picking a role's metas by app. */
    public function tree(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(MetaTree::get());
    }

    /** GET /roles/{role}/log?page=: its changes, newest first, with who made them. */
    public function log(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        $page = DB::table('role_log')->leftJoin('users', 'users.id', '=', 'role_log.user_id')
            ->where('role_id', $role->id)->orderByDesc('role_log.id')
            ->select('role_log.id', 'role_log.action', 'role_log.changes', 'role_log.created_at', 'role_log.user_id', 'users.name as user_name')
            ->paginate(30);
        $page->getCollection()->transform(fn (object $row) => ['changes' => json_decode($row->changes, true)] + (array) $row);

        return response()->json($page);
    }

    /** @return array<string, bool> can_* of a permission row, all false without one. */
    private function abilities(?object $row, array $abilities): array
    {
        return collect($abilities)->mapWithKeys(fn ($a) => ["can_{$a}" => (bool) ($row?->{"can_{$a}"} ?? false)])->all();
    }

    /** @return array<string, bool> */
    private function columns(array $row, array $abilities): array
    {
        return collect($abilities)->mapWithKeys(fn ($a) => ["can_{$a}" => (bool) ($row["can_{$a}"] ?? false)])->all();
    }
}
