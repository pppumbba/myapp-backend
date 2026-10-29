<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WfmStatus;
use App\Models\WfmStatusLog;
use App\Models\WfmStatusPermission;
use App\Models\WfmTransition;
use App\Support\Access;
use App\Support\Criteria;
use App\Support\Notifier;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Workflow metas: their statuses and transitions (the designer), and moving records
 * through them. A workflow's table_name is the table its records live in; each record's
 * current status is its status_id column and every move is logged in wfm_status_log.
 */
class WorkflowController extends Controller
{
    /** GET /workflows/{meta}: everything the designer draws. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->workflow($meta);

        $statuses = WfmStatus::where('workflow_meta_id', $meta->id)->orderBy('id')->get();
        if ($request->boolean('translated')) {
            Translations::apply($statuses, 'wfm_status');
        }

        return response()->json([
            'statuses' => $statuses,
            'transitions' => WfmTransition::where('workflow_meta_id', $meta->id)->orderBy('id')->get(),
            'layout' => $meta->json_config['layout'] ?? (object) [],
        ]);
    }

    /** PUT /workflows/{meta}/layout: where the designer's boxes sit, kept in json_config.layout. */
    public function layout(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->workflow($meta);
        $data = $request->validate(['layout' => ['present', 'array']]);

        $config = $meta->json_config ?? [];
        $config['layout'] = $data['layout'];
        $meta->update(['json_config' => $config]);

        return response()->json(['layout' => $config['layout']]);
    }

    /** POST /workflows/{meta}/statuses */
    public function storeStatus(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->workflow($meta);
        $data = $this->statusData($request, $meta);

        return response()->json(WfmStatus::create($data + ['workflow_meta_id' => $meta->id]), 201);
    }

    /** PUT /wfm-statuses/{status} */
    public function updateStatus(Request $request, WfmStatus $status): JsonResponse
    {
        Access::requireAdmin($request->user());
        $status->update($this->statusData($request, $status->workflow, $status));

        return response()->json($status);
    }

    /** DELETE /wfm-statuses/{status}. A status in any record's history stays. */
    public function destroyStatus(Request $request, WfmStatus $status): JsonResponse
    {
        Access::requireAdmin($request->user());
        $used = WfmStatusLog::where('status_id', $status->id)->orWhere('prev_status_id', $status->id)->exists();
        abort_if($used, 422, 'Records have been in this status, so it stays in their history. Remove its transitions instead.');

        $status->delete();

        return response()->json(null, 204);
    }

    /** POST /workflows/{meta}/transitions */
    public function storeTransition(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->workflow($meta);
        $data = $this->transitionData($request, $meta);

        return response()->json(WfmTransition::create($data + ['workflow_meta_id' => $meta->id]), 201);
    }

    /** PUT /wfm-transitions/{transition} */
    public function updateTransition(Request $request, WfmTransition $transition): JsonResponse
    {
        Access::requireAdmin($request->user());
        $meta = Meta::findOrFail($transition->workflow_meta_id);
        $transition->update($this->transitionData($request, $meta, $transition));

        return response()->json($transition);
    }

    /** DELETE /wfm-transitions/{transition} */
    public function destroyTransition(Request $request, WfmTransition $transition): JsonResponse
    {
        Access::requireAdmin($request->user());
        $transition->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /workflows/{meta}/records/{id}?source={grid id}
     * The record's status, the transitions out of it (each saying whether its criteria
     * holds, and why not), and its history, newest first. A record in a status the user may
     * not see is not found.
     */
    public function record(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $table = $this->workflow($meta, true);
        $row = $this->row($table, $id);
        abort_if(in_array((int) $row->status_id, Access::hiddenStatuses($request->user()), true), 404, 'Record not found.');

        return response()->json($this->state($request, $meta, $row, $id));
    }

    /**
     * POST /workflows/{meta}/records/{id}/move {transition_id, source_id?}
     * Checks the move again (the record may have changed), sets status_id and logs it, then
     * tells whoever may take the next step.
     */
    public function move(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $table = $this->workflow($meta, true);
        $data = $request->validate([
            'transition_id' => ['required', 'integer'],
            'source_id' => ['nullable', 'integer'],
        ]);

        $transition = DB::transaction(function () use ($request, $meta, $table, $id, $data) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_if(! $row || in_array((int) $row->status_id, Access::hiddenStatuses($request->user()), true), 404, 'Record not found.');

            $transition = WfmTransition::where('workflow_meta_id', $meta->id)->find($data['transition_id']);
            abort_if(! $transition, 422, 'That transition is not part of this workflow.');
            abort_unless(Access::canEnter($request->user(), $transition->next_status_id), 403, "You don't have permission to move records into that status.");
            [$allowed, $reason] = $this->allowed($transition, $row, $request, $data['source_id'] ?? null);
            abort_unless($allowed, 422, $reason);

            $now = now();
            $last = WfmStatusLog::where('workflow_meta_id', $meta->id)->where('record_id', $id)->latest('id')->first();
            $update = ['status_id' => $transition->next_status_id];
            if (Schema::hasColumn($table, 'updated_at')) {
                $update['updated_at'] = $now;
            }
            DB::table($table)->where('id', $id)->update($update);

            WfmStatusLog::create([
                'workflow_meta_id' => $meta->id,
                'record_id' => $id,
                'status_id' => $transition->next_status_id,
                'prev_status_id' => $row->status_id,
                'created_by' => $request->user()?->id,
                'time_spent' => $last?->created_at ? (int) $last->created_at->diffInSeconds($now, true) : null,
            ]);

            return $transition;
        });

        $this->notifyNext($request, $meta, $transition, $id, $data['source_id'] ?? null);
        Notifier::statusChanged($meta, $table, $id, $transition->next_status_id, $request->user());

        return response()->json($this->state($request, $meta, $this->row($table, $id), $id));
    }

    /**
     * Tells the people who may move the record on from its new status, apart from whoever
     * just moved it. Only roles named in the next statuses' can_enter rows count: a status
     * open to everyone would notify everyone, so it notifies no one.
     */
    private function notifyNext(Request $request, Meta $meta, WfmTransition $transition, int $id, ?int $source): void
    {
        $nextStatuses = WfmTransition::where('workflow_meta_id', $meta->id)->where('is_transition', true)
            ->where('prev_status_id', $transition->next_status_id)->pluck('next_status_id');
        $roles = WfmStatusPermission::whereIn('status_id', $nextStatuses)->where('can_enter', true)->pluck('role_id');
        if ($roles->isEmpty()) {
            return;
        }

        $status = WfmStatus::find($transition->next_status_id);
        $mover = $request->user();
        $now = now();
        $notifications = User::whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roles))
            ->when($mover, fn ($q) => $q->whereKeyNot($mover->id))
            ->pluck('id')
            ->map(fn ($userId) => [
                'user_id' => $userId,
                'type' => 'info',
                'title' => ($meta->label_name ?: $meta->name).": record #{$id} is now ".($status->label_name ?: $status->name),
                'body' => ($mover ? "{$mover->name} moved it. " : '').'It is waiting for your next step.',
                'link' => $source ? "/run/{$source}?record={$id}" : null,
                'created_at' => $now,
            ]);

        UserNotification::insert($notifications->all());
    }

    /**
     * Stops with 422 unless this is a workflow meta. With $records, also checks its table
     * (set, allowed, has status_id) and returns it.
     */
    private function workflow(Meta $meta, bool $records = false): ?string
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'workflow', 422, 'This meta is not a workflow.');
        if (! $records) {
            return null;
        }

        $table = trim((string) $meta->table_name);
        abort_if($table === '', 422, 'Set the table this workflow\'s records live in (the workflow meta\'s table).');
        abort_if(in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true), 422, 'This table cannot be used by a workflow.');
        abort_unless(Schema::hasTable($table), 422, __('The table ":table" does not exist.', ['table' => $table]));
        abort_unless(Schema::hasColumn($table, 'status_id'), 422, __('The table ":table" needs a status_id column for this workflow.', ['table' => $table]));

        return $table;
    }

    private function row(string $table, int $id): object
    {
        $row = DB::table($table)->where('id', $id)->first();
        abort_if(! $row, 404, 'Record not found.');

        return $row;
    }

    private function state(Request $request, Meta $meta, object $row, int $id): array
    {
        $source = $request->integer('source') ?: null;
        $statuses = WfmStatus::where('workflow_meta_id', $meta->id)->get()->keyBy('id');

        $transitions = WfmTransition::where('workflow_meta_id', $meta->id)
            ->where('is_transition', true)
            ->where(fn ($q) => $row->status_id ? $q->where('prev_status_id', $row->status_id) : $q->whereNull('prev_status_id'))
            ->orderBy('id')->get()
            ->map(function (WfmTransition $t) use ($row, $request, $source, $statuses) {
                [$allowed, $reason] = $this->allowed($t, $row, $request, $source);

                return $allowed || $reason !== null ? [
                    'id' => $t->id,
                    'criteria' => $t->criteria,
                    'next_status' => $statuses->get($t->next_status_id),
                    'allowed' => $allowed,
                    'reason' => $reason,
                ] : null;
            })->filter()->values();

        $log = WfmStatusLog::where('workflow_meta_id', $meta->id)->where('record_id', $id)
            ->with('user:id,name')->latest('id')->limit(50)->get();

        return ['status' => $statuses->get($row->status_id), 'transitions' => $transitions, 'log' => $log];
    }

    /**
     * Whether a transition can be used on a record: it must leave the record's current
     * status, be offered in this grid, and its criteria must hold.
     *
     * @return array{0: bool, 1: ?string} allowed, and why not (null when it just doesn't apply here)
     */
    private function allowed(WfmTransition $t, object $row, Request $request, ?int $source): array
    {
        if (! $t->is_transition) {
            return [false, 'This transition is only drawn in the diagram.'];
        }
        if ((int) $t->prev_status_id !== (int) $row->status_id) {
            return [false, 'The record is no longer in the status this transition starts from.'];
        }
        if ($t->source_id && $source && $t->source_id !== $source) {
            return [false, null];
        }

        $user = $request->user();
        if (! Access::canEnter($user, $t->next_status_id)) {
            return [false, 'Your role cannot move records into this status.'];
        }
        $vars = (array) $row + Criteria::userVars($user);
        try {
            return Criteria::passes($t->criteria, $vars) ? [true, null] : [false, "Needs: {$t->criteria}"];
        } catch (InvalidArgumentException $e) {
            return [false, $e->getMessage()];
        }
    }

    private function statusData(Request $request, Meta $workflow, ?WfmStatus $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:100', Rule::unique('wfm_status', 'code')->where('workflow_meta_id', $workflow->id)->ignore($current?->id)],
            'name' => [$req, 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:30'],
            'meta_id' => ['nullable', 'integer', 'exists:metas,id'],
        ]);
    }

    private function transitionData(Request $request, Meta $workflow, ?WfmTransition $current = null): array
    {
        $ofWorkflow = fn (string $table) => Rule::exists($table, 'id')->where('workflow_meta_id', $workflow->id);
        $data = $request->validate([
            'prev_status_id' => ['nullable', 'integer', $ofWorkflow('wfm_status')],
            'next_status_id' => [$current ? 'sometimes' : 'required', 'integer', $ofWorkflow('wfm_status')],
            'criteria' => ['nullable', 'string', 'max:2000'],
            'prev_id' => ['nullable', 'integer', $ofWorkflow('wfm_transition')],
            'next_id' => ['nullable', 'integer', $ofWorkflow('wfm_transition')],
            'source_id' => ['nullable', 'integer', 'exists:metas,id'],
            'is_transition' => ['sometimes', 'boolean'],
        ]);

        $prev = array_key_exists('prev_status_id', $data) ? $data['prev_status_id'] : $current?->prev_status_id;
        $next = $data['next_status_id'] ?? $current?->next_status_id;
        abort_if($prev !== null && (int) $prev === (int) $next, 422, 'A transition has to lead to a different status.');

        try {
            Criteria::check($data['criteria'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(response()->json(['message' => __('The given data was invalid.'), 'errors' => ['criteria' => [$e->getMessage()]]], 422));
        }

        return $data;
    }
}
