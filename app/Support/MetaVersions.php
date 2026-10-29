<?php

namespace App\Support;

use App\Models\MetaVersion;
use App\Models\User;
use App\Models\WfmStatusLog;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Version control for metas. A snapshot is the meta's row plus the rows of each part defined
 * with it (see parts()), read straight from their tables. Versions can be compared with each
 * other or with the meta as it is now, and restored.
 *
 * Restoring puts every row back under its old id: rows added since are deleted, rows changed
 * since are written back, rows deleted since are inserted again, and a deleted meta comes
 * back too. A reference to something that no longer exists (a lookup's meta, a role) is
 * cleared, or the row is left out when it means nothing without it; each is reported. A
 * workflow status that records have been in is never deleted.
 */
class MetaVersions
{
    /** Columns that say when or by whom, not what: never compared. */
    private const IGNORED = ['created_at', 'updated_at', 'created_by', 'updated_by'];

    /** Minutes one person's changes in a row go between automatic versions (see keepBefore). */
    public const AUTO_EVERY = 10;

    /** What the meta row points at: column => [table, needed]. */
    private const META_REFS = ['type_id' => ['meta_types', true], 'ref_meta_id' => ['metas', false], 'widget_id' => ['widgets', false]];

    /**
     * The parts of a meta, in the order they are restored. Each: its table, which rows are
     * the meta's (scope), the columns pointing at rows of the same part (written in a second
     * pass) and the columns pointing elsewhere (column => [table, needed]: a row whose
     * needed reference is gone is left out; any other is cleared).
     *
     * @return array<string, array{table: string, scope: Closure(Builder, int): Builder, self: list<string>, refs: array<string, array{0: string, 1: bool}>}>
     */
    public static function parts(): array
    {
        $own = fn (string $column) => fn (Builder $q, int $id) => $q->where($column, $id);

        return [
            'wfm_statuses' => ['table' => 'wfm_status', 'scope' => $own('workflow_meta_id'), 'self' => [], 'refs' => ['meta_id' => ['metas', false]]],
            'wfm_transitions' => ['table' => 'wfm_transition', 'scope' => $own('workflow_meta_id'), 'self' => ['prev_id', 'next_id'], 'refs' => [
                'prev_status_id' => ['wfm_status', true], 'next_status_id' => ['wfm_status', true], 'source_id' => ['metas', false],
            ]],
            // A page's own widgets: widget metas it made (see PageController).
            'widgets' => ['table' => 'metas', 'scope' => fn (Builder $q, int $id) => $q->where('ref_meta_id', $id)
                ->whereIn('type_id', DB::table('meta_types')->where('code', 'widget')->select('id')), 'self' => [], 'refs' => ['widget_id' => ['widgets', false]]],
            'params' => ['table' => 'meta_params', 'scope' => $own('main_meta_id'), 'self' => ['parent_id'], 'refs' => ['ref_meta_id' => ['metas', false]]],
            'actions' => ['table' => 'meta_actions', 'scope' => $own('grid_meta_id'), 'self' => [], 'refs' => ['form_meta_id' => ['metas', false], 'get_meta_id' => ['metas', false]]],
            'permissions' => ['table' => 'meta_permissions', 'scope' => $own('meta_id'), 'self' => [], 'refs' => ['role_id' => ['roles', true]]],
            'process_steps' => ['table' => 'meta_process_workflow', 'scope' => $own('main_process_id'), 'self' => [], 'refs' => ['process_id' => ['metas', true]]],
            'process_transitions' => ['table' => 'meta_process_transition', 'scope' => $own('main_process_id'), 'self' => [], 'refs' => [
                'source_id' => ['meta_process_workflow', true], 'target_id' => ['meta_process_workflow', true],
            ]],
            'process_behaviours' => ['table' => 'meta_process_behaviour', 'scope' => fn (Builder $q, int $id) => $q
                ->whereIn('workflow_id', DB::table('meta_process_workflow')->where('main_process_id', $id)->select('id')), 'self' => [], 'refs' => ['workflow_id' => ['meta_process_workflow', true]]],
            // Links held in the meta's and its params' json_config (see MetaLinks).
            'links' => ['table' => 'meta_meta_map', 'scope' => $own('src_meta_id'), 'self' => [], 'refs' => [
                'trg_meta_id' => ['metas', true], 'src_param_id' => ['meta_params', true], 'trg_param_id' => ['meta_params', false],
            ]],
            'notification' => ['table' => 'meta_notification_map', 'scope' => $own('notification_id'), 'self' => [], 'refs' => [
                'meta_id' => ['metas', false], 'status_id' => ['wfm_status', false],
            ]],
            'tags' => ['table' => 'meta_tag_map', 'scope' => $own('meta_id'), 'self' => [], 'refs' => ['tag_id' => ['tags', true]]],
            // Texts in other languages (see Translations); row_id points into the table named by table_name, so it is last.
            'translations' => ['table' => 'translations', 'scope' => $own('meta_id'), 'self' => [], 'refs' => []],
        ];
    }

    /**
     * The meta as it is now, or null when it doesn't exist.
     *
     * @return array{meta: array<string, mixed>, parts: array<string, list<array<string, mixed>>>}|null
     */
    public static function snapshot(int $metaId): ?array
    {
        $meta = DB::table('metas')->find($metaId);
        if (! $meta) {
            return null;
        }
        $parts = [];
        foreach (self::parts() as $name => $part) {
            $parts[$name] = ($part['scope'])(DB::table($part['table']), $metaId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return ['meta' => (array) $meta, 'parts' => $parts];
    }

    /** What a snapshot defines, without when or by whom: equal hashes, same meta. */
    public static function hash(array $snapshot): string
    {
        $strip = fn (array $row) => array_diff_key($row, array_flip(self::IGNORED));
        $parts = array_map(fn (array $rows) => array_map($strip, $rows), $snapshot['parts']);

        return hash('sha256', json_encode([$strip($snapshot['meta']), $parts]));
    }

    /** The newest version the meta is exactly like now, e.g. the one just restored; null if none. */
    public static function matching(int $metaId): ?MetaVersion
    {
        $now = self::snapshot($metaId);

        return $now === null ? null : MetaVersion::where('meta_id', $metaId)->where('hash', self::hash($now))->orderByDesc('version_no')->first();
    }

    /** Whether the meta is unlike every saved version: there are changes a version would keep. */
    public static function changed(int $metaId): bool
    {
        return self::matching($metaId) === null && DB::table('metas')->where('id', $metaId)->exists();
    }

    /** Saves the meta as it is now (or as a snapshot of it had it) as its next version. */
    public static function save(int $metaId, ?User $user, ?string $note, ?array $snapshot = null, bool $auto = false): MetaVersion
    {
        $snapshot ??= self::snapshot($metaId);
        abort_if($snapshot === null, 404, 'This meta does not exist.');

        return DB::transaction(function () use ($metaId, $snapshot, $user, $note, $auto) {
            $next = (int) MetaVersion::where('meta_id', $metaId)->lockForUpdate()->max('version_no') + 1;

            return MetaVersion::create([
                'meta_id' => $metaId,
                'version_no' => $next,
                'code' => $snapshot['meta']['code'],
                'name' => $snapshot['meta']['name'],
                'note' => $note,
                'snapshot' => $snapshot,
                'hash' => self::hash($snapshot),
                'is_auto' => $auto,
                'created_by' => $user?->id,
            ]);
        });
    }

    /**
     * After a change: keeps the meta as it was before (the snapshot) as a version, unless
     * nothing changed or a version already holds it. Someone changing what another person
     * left always gets one; one person's own run of changes gets one per AUTO_EVERY minutes.
     * The meta is stamped as last changed by the user.
     */
    public static function keepBefore(array $before, ?User $user): ?MetaVersion
    {
        $metaId = (int) $before['meta']['id'];
        $after = self::snapshot($metaId);
        $hash = self::hash($before);
        if ($after === null || self::hash($after) === $hash) {
            return null;
        }

        DB::table('metas')->where('id', $metaId)->update(['updated_by' => $user?->id, 'updated_at' => now()]);

        if (MetaVersion::where('meta_id', $metaId)->where('hash', $hash)->exists()) {
            return null;
        }
        $ownRun = $user && (int) ($before['meta']['updated_by'] ?? 0) === $user->id
            && MetaVersion::where('meta_id', $metaId)->where('is_auto', true)->where('created_by', $user->id)
                ->where('created_at', '>=', now()->subMinutes(self::AUTO_EVERY))->exists();
        if ($ownRun) {
            return null;
        }

        return self::save($metaId, $user, 'Before a change by '.($user->name ?? 'someone'), $before, true);
    }

    /** Saves a version first when there are changes to lose, e.g. before a restore or a delete. */
    public static function saveIfChanged(int $metaId, ?User $user, string $note): ?MetaVersion
    {
        return self::changed($metaId) ? self::save($metaId, $user, $note) : null;
    }

    /**
     * What changed from one snapshot to another: the meta's fields, and each part's rows
     * added, removed and changed (with their fields). Unchanged parts are left out.
     *
     * @return array{meta: list<array{field: string, from: mixed, to: mixed}>, parts: array<string, array{added: list<string>, removed: list<string>, changed: list<array{label: string, fields: list<array{field: string, from: mixed, to: mixed}>}>}>}
     */
    public static function diff(array $from, array $to): array
    {
        $parts = [];
        foreach (array_keys(self::parts()) as $name) {
            $old = collect($from['parts'][$name] ?? [])->keyBy('id');
            $new = collect($to['parts'][$name] ?? [])->keyBy('id');
            $label = fn (array $row, array $snapshot) => self::label($name, $row, $snapshot);

            $result = [
                'added' => $new->diffKeys($old)->map(fn ($r) => $label($r, $to))->values()->all(),
                'removed' => $old->diffKeys($new)->map(fn ($r) => $label($r, $from))->values()->all(),
                'changed' => $new->intersectByKeys($old)
                    ->map(fn ($r, $id) => ['label' => $label($r, $to), 'fields' => self::fieldChanges($old[$id], $r)])
                    ->filter(fn ($c) => $c['fields'] !== [])->values()->all(),
            ];
            if ($result['added'] || $result['removed'] || $result['changed']) {
                $parts[$name] = $result;
            }
        }

        return ['meta' => self::fieldChanges($from['meta'], $to['meta']), 'parts' => $parts];
    }

    /**
     * Puts the meta back as the version has it, after saving the current state as a
     * version when it has changes. Returns what could not be put back as it was.
     *
     * @return array{saved_first: ?int, warnings: list<string>}
     */
    public static function restore(MetaVersion $version, ?User $user): array
    {
        $before = self::saveIfChanged($version->meta_id, $user, "Before restoring version {$version->version_no}");
        $snapshot = $version->snapshot;
        $warnings = [];

        try {
            DB::transaction(function () use ($version, $snapshot, $user, &$warnings) {
                $id = $version->meta_id;
                $meta = self::withRefs('metas', $snapshot['meta'], self::META_REFS, $warnings, 'The meta');
                abort_if($meta === null, 422, 'This version’s meta type no longer exists, so it cannot be restored.');
                $meta['updated_at'] = now();
                $meta['updated_by'] = $user?->id;
                if (DB::table('metas')->where('id', $id)->exists()) {
                    DB::table('metas')->where('id', $id)->update($meta);
                } else {
                    abort_if(DB::table('metas')->where('code', $meta['code'])->exists(), 422, __('Another meta has the code ":code" now, so this one cannot come back.', ['code' => $meta['code']]));
                    DB::table('metas')->insert($meta);
                }

                $parts = self::parts();
                // Rows added since go first, last part first, so nothing points at them.
                foreach (array_reverse($parts, true) as $name => $part) {
                    $keep = array_column($snapshot['parts'][$name] ?? [], 'id');
                    $extra = ($part['scope'])(DB::table($part['table']), $id)->whereNotIn('id', $keep)->pluck('id');
                    if ($name === 'wfm_statuses') {
                        $used = WfmStatusLog::whereIn('status_id', $extra)->pluck('status_id')
                            ->merge(WfmStatusLog::whereIn('prev_status_id', $extra)->pluck('prev_status_id'))->unique();
                        foreach (DB::table('wfm_status')->whereIn('id', $used)->pluck('name') as $status) {
                            $warnings[] = "The status \"{$status}\" stays: records have been in it.";
                        }
                        $extra = $extra->diff($used);
                    }
                    DB::table($part['table'])->whereIn('id', $extra)->delete();
                }

                foreach ($parts as $name => $part) {
                    $rows = [];
                    foreach ($snapshot['parts'][$name] ?? [] as $row) {
                        $row = self::withRefs($part['table'], $row, $part['refs'], $warnings, self::label($name, $row, $snapshot));
                        if ($row !== null) {
                            $rows[] = $row;
                        }
                    }
                    // Rows pointing at each other: first without the link, then with it.
                    foreach ($rows as $row) {
                        DB::table($part['table'])->updateOrInsert(['id' => $row['id']], array_merge($row, array_fill_keys($part['self'], null)));
                    }
                    foreach ($part['self'] as $column) {
                        foreach (array_filter($rows, fn ($r) => ($r[$column] ?? null) !== null) as $row) {
                            if (DB::table($part['table'])->where('id', $row[$column])->exists()) {
                                DB::table($part['table'])->where('id', $row['id'])->update([$column => $row[$column]]);
                            }
                        }
                    }
                }
            });
        } catch (QueryException $e) {
            abort(422, 'The database refused to restore this version: '.($e->errorInfo[2] ?? 'unknown error').'.');
        }

        Translations::forget($version->meta_id);

        return ['saved_first' => $before?->version_no, 'warnings' => array_values(array_unique($warnings))];
    }

    /** A row with its references checked: a gone one cleared, or null when the row needs it. */
    private static function withRefs(string $table, array $row, array $refs, array &$warnings, string $label): ?array
    {
        $row = array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
        foreach ($refs as $column => [$target, $needed]) {
            $value = $row[$column] ?? null;
            if ($value === null || DB::table($target)->where('id', $value)->exists()) {
                continue;
            }
            if ($needed) {
                $warnings[] = "{$label} is left out: what it points at ({$column} #{$value}) no longer exists.";

                return null;
            }
            $warnings[] = "{$label}: {$column} #{$value} no longer exists, so it is cleared.";
            $row[$column] = null;
        }

        return $row;
    }

    /** @return list<array{field: string, from: mixed, to: mixed}> */
    private static function fieldChanges(array $old, array $new): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            if (in_array($field, self::IGNORED, true) || $field === 'id') {
                continue;
            }
            $a = $old[$field] ?? null;
            $b = $new[$field] ?? null;
            if ((string) json_encode($a) !== (string) json_encode($b) && (string) $a !== (string) $b) {
                $changes[] = ['field' => $field, 'from' => $a, 'to' => $b];
            }
        }

        return $changes;
    }

    /** How a row reads in a diff or a warning. */
    private static function label(string $part, array $row, array $snapshot): string
    {
        $find = fn (string $p, mixed $id) => collect($snapshot['parts'][$p] ?? [])->firstWhere('id', $id);
        $metaName = fn (mixed $id) => $id ? (DB::table('metas')->where('id', $id)->value('name') ?? "meta #{$id}") : null;

        return match ($part) {
            'params' => 'Field '.($row['field_path'] ?? "#{$row['id']}"),
            'actions' => 'Action '.($row['label'] ?: $row['action']),
            'permissions' => 'Permissions of '.(DB::table('roles')->where('id', $row['role_id'])->value('name') ?? "role #{$row['role_id']}"),
            'widgets' => 'Widget '.$row['name'],
            'wfm_statuses' => 'Status '.$row['name'],
            'wfm_transitions' => 'Transition '.($find('wfm_statuses', $row['prev_status_id'])['name'] ?? 'start').' → '.($find('wfm_statuses', $row['next_status_id'])['name'] ?? "#{$row['next_status_id']}"),
            'process_steps' => 'Step '.$metaName($row['process_id']),
            'process_transitions' => 'Flow '.($row['source_id'] ? $metaName($find('process_steps', $row['source_id'])['process_id'] ?? null) : 'main').' → '.$metaName($find('process_steps', $row['target_id'])['process_id'] ?? null),
            'process_behaviours' => 'Value '.$row['source_path'].' → '.$row['target_path'],
            'links' => ucfirst($row['type']).' → '.$metaName($row['trg_meta_id']).($row['trg_path'] ? " ({$row['src_path']} → {$row['trg_path']})" : ''),
            'notification' => 'Watches '.($metaName($row['meta_id']) ?? 'nothing'),
            'translations' => 'Translation ('.$row['locale'].') of '.$row['table_name'].' #'.$row['row_id'].' '.$row['field'],
            'tags' => 'Tag '.(DB::table('tags')->where('id', $row['tag_id'])->value('name') ?? "#{$row['tag_id']}"),
            default => "#{$row['id']}",
        };
    }
}
