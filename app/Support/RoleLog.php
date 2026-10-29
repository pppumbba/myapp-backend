<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\User;
use App\Models\WfmStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The change log of roles (role_log): who changed what about a role, wherever it was changed.
 * One row per role per save: its fields, the people added and removed, or the metas and
 * statuses whose abilities changed (as before and after, with names so they read after a
 * meta is gone). Saves that change nothing write nothing.
 */
class RoleLog
{
    private const META_ABILITIES = ['view', 'create', 'edit', 'delete'];

    private const STATUS_ABILITIES = ['enter', 'see'];

    /** The role's code, name or description changed: fields as [old, new]. */
    public static function role(int $roleId, array $before, array $after, ?User $actor): void
    {
        $changes = [];
        foreach (['code', 'name', 'description'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = [$before[$field] ?? null, $after[$field] ?? null];
            }
        }
        self::write($roleId, 'role', $changes, $actor);
    }

    /**
     * People given or taken the role.
     *
     * @param  int[]  $added
     * @param  int[]  $removed
     */
    public static function users(int $roleId, array $added, array $removed, ?User $actor): void
    {
        if ($added === [] && $removed === []) {
            return;
        }
        $people = User::whereIn('id', [...$added, ...$removed])->get(['id', 'name', 'email'])->keyBy('id');
        $list = fn (array $ids) => array_values(array_map(fn ($id) => ['id' => $id, 'name' => $people[$id]->name ?? null, 'email' => $people[$id]->email ?? null], $ids));
        self::write($roleId, 'users', ['added' => $list($added), 'removed' => $list($removed)], $actor);
    }

    /**
     * Meta permission rows of any roles, before and after a save; each role whose abilities
     * changed gets a row listing those metas.
     *
     * @param  Collection<int, object|array>  $before  rows with meta_id, role_id, can_*
     * @param  Collection<int, object|array>  $after
     */
    public static function metas(Collection $before, Collection $after, ?User $actor): void
    {
        $changed = self::compare($before, $after, 'meta_id', self::META_ABILITIES);
        $names = Meta::whereIn('id', collect($changed)->flatMap(fn ($rows) => array_keys($rows)))->get(['id', 'code', 'name'])->keyBy('id');
        foreach ($changed as $roleId => $rows) {
            self::write($roleId, 'metas', array_map(fn ($id) => ['meta_id' => $id, 'code' => $names[$id]->code ?? null, 'name' => $names[$id]->name ?? null] + $rows[$id], array_keys($rows)), $actor);
        }
    }

    /**
     * Status permission rows of any roles, before and after a save.
     *
     * @param  Collection<int, object|array>  $before  rows with status_id, role_id, can_enter, can_see
     * @param  Collection<int, object|array>  $after
     */
    public static function statuses(Collection $before, Collection $after, ?User $actor): void
    {
        $changed = self::compare($before, $after, 'status_id', self::STATUS_ABILITIES);
        $statuses = WfmStatus::whereIn('id', collect($changed)->flatMap(fn ($rows) => array_keys($rows)))->get(['id', 'code', 'name', 'workflow_meta_id'])->keyBy('id');
        $workflows = Meta::whereIn('id', $statuses->pluck('workflow_meta_id'))->pluck('name', 'id');
        foreach ($changed as $roleId => $rows) {
            self::write($roleId, 'statuses', array_map(fn ($id) => [
                'status_id' => $id,
                'name' => $statuses[$id]->name ?? null,
                'workflow' => $workflows[$statuses[$id]->workflow_meta_id ?? 0] ?? null,
            ] + $rows[$id], array_keys($rows)), $actor);
        }
    }

    /**
     * Per role, per target: the abilities before and after, for targets where they differ.
     * A missing row is all false.
     *
     * @return array<int, array<int, array{before: array<string, bool>, after: array<string, bool>}>>
     */
    private static function compare(Collection $before, Collection $after, string $key, array $abilities): array
    {
        $index = fn (Collection $rows) => $rows->map(fn ($r) => $r instanceof Model ? $r->getAttributes() : (array) $r)
            ->mapWithKeys(fn (array $r) => [$r['role_id'].':'.$r[$key] => array_combine(
                $abilities, array_map(fn ($a) => (bool) ($r["can_{$a}"] ?? false), $abilities),
            )]);
        $was = $index($before);
        $now = $index($after);
        $none = array_fill_keys($abilities, false);

        $changed = [];
        foreach ($was->keys()->merge($now->keys())->unique() as $pair) {
            [$roleId, $target] = array_map('intval', explode(':', $pair));
            $from = $was[$pair] ?? $none;
            $to = $now[$pair] ?? $none;
            if ($from !== $to) {
                $changed[$roleId][$target] = ['before' => $from, 'after' => $to];
            }
        }

        return $changed;
    }

    private static function write(int $roleId, string $action, array $changes, ?User $actor): void
    {
        if ($changes === []) {
            return;
        }
        DB::table('role_log')->insert([
            'role_id' => $roleId,
            'action' => $action,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
