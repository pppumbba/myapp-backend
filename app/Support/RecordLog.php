<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The change log of records saved through metas with is_log on (record_log): one row per
 * save, with the fields that changed as [old, new] and the lines added, removed or changed,
 * down to lines of lines. A delete keeps the whole record with its lines.
 *
 * Who saved comes from the caller; how (form, process flow, schedule, import, matrix) from
 * the innermost as() around the save. Saves inside one as() share a batch id.
 */
class RecordLog
{
    /** Columns that change on every save and say nothing. */
    private const IGNORED = ['created_at', 'updated_at'];

    /** @var list<string> */
    private static array $sources = [];

    private static ?string $batch = null;

    /**
     * Runs saves as coming from this source (form, process_flow, schedule, import, matrix).
     *
     * @template T
     *
     * @param  Closure(): T  $saves
     * @return T
     */
    public static function as(string $source, Closure $saves): mixed
    {
        $outer = self::$sources === [];
        self::$sources[] = $source;
        if ($outer) {
            self::$batch = (string) Str::uuid();
        }
        try {
            return $saves();
        } finally {
            array_pop(self::$sources);
            if ($outer) {
                self::$batch = null;
            }
        }
    }

    /**
     * Runs a save in the source around it, or as a form save when there is none, so the
     * saves it sets off (a process flow's steps) share its batch.
     *
     * @template T
     *
     * @param  Closure(): T  $save
     * @return T
     */
    public static function saving(Closure $save): mixed
    {
        return self::as(end(self::$sources) ?: 'form', $save);
    }

    public static function enabled(Meta $meta): bool
    {
        return (bool) $meta->is_log;
    }

    /**
     * Logs one save. $before and $after are the record with its lines (null when it didn't
     * exist), $details the lines' shape: [{field, one, source: {key}, details}]. An update
     * that changed nothing is not logged.
     */
    public static function write(Meta $meta, string $table, int $recordId, string $action, ?array $before, ?array $after, array $details, ?User $user): void
    {
        $changes = $action === 'deleted' ? null : self::diff($before ?? [], $after ?? [], $details);
        if ($action === 'updated' && $changes === []) {
            return;
        }
        DB::table('record_log')->insert([
            'meta_id' => $meta->id,
            'table_name' => $table,
            'record_id' => $recordId,
            'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'snapshot' => $action === 'deleted' ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $user?->id,
            'source' => end(self::$sources) ?: 'form',
            'batch_id' => self::$batch,
            'created_at' => now(),
        ]);
    }

    /**
     * Logs many rows saved at once, without lines (an import, a matrix): [[id, action, before, after], …].
     *
     * @param  list<array{0: int, 1: string, 2: ?array, 3: ?array}>  $rows
     */
    public static function writeMany(Meta $meta, string $table, array $rows, ?User $user): void
    {
        $now = now();
        $source = end(self::$sources) ?: 'form';
        $batch = self::$batch ?? (string) Str::uuid();
        $insert = [];
        foreach ($rows as [$id, $action, $before, $after]) {
            $changes = self::diff($before ?? [], $after ?? [], []);
            if ($action === 'updated' && $changes === []) {
                continue;
            }
            $insert[] = [
                'meta_id' => $meta->id, 'table_name' => $table, 'record_id' => $id, 'action' => $action,
                'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE), 'snapshot' => null,
                'user_id' => $user?->id, 'source' => $source, 'batch_id' => $batch, 'created_at' => $now,
            ];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('record_log')->insert($chunk);
        }
    }

    /**
     * What changed between two versions of a record: {fields: {name: [old, new]}, lines:
     * {detail: {added: [line], removed: [line], changed: [{id, fields, lines}]}}}, leaving out
     * what didn't change.
     */
    public static function diff(array $before, array $after, array $details): array
    {
        $lineFields = array_column($details, 'field');
        $fields = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            if (in_array($name, $lineFields, true) || in_array($name, self::IGNORED, true)) {
                continue;
            }
            $old = $before[$name] ?? null;
            $new = $after[$name] ?? null;
            if (! self::same($old, $new)) {
                $fields[$name] = [$old, $new];
            }
        }

        $lines = [];
        foreach ($details as $detail) {
            $key = $detail['source']['key'];
            $field = $detail['field'];
            if (! array_key_exists($field, $before) && ! array_key_exists($field, $after)) {
                continue;
            }
            $old = self::keyed(self::lineList($before[$field] ?? null), $key);
            $new = self::keyed(self::lineList($after[$field] ?? null), $key);
            $change = ['added' => [], 'removed' => [], 'changed' => []];
            foreach ($new as $id => $line) {
                if (! isset($old[$id])) {
                    $change['added'][] = $line;
                } elseif ($d = self::diff($old[$id], $line, $detail['details'] ?? [])) {
                    $change['changed'][] = ['id' => $id] + $d;
                }
            }
            foreach ($old as $id => $line) {
                if (! isset($new[$id])) {
                    $change['removed'][] = $line;
                }
            }
            $change = array_filter($change);
            if ($change) {
                $lines[$field] = $change;
            }
        }

        return array_filter(['fields' => $fields, 'lines' => $lines]);
    }

    /** Equal as the user sees them: 5 and "5.00" are, null and "" are. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return (string) json_encode($a) === (string) json_encode($b) || (is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    }

    /** A detail's lines as a list: a one-to-one's single record, or nothing. */
    private static function lineList(mixed $lines): array
    {
        if ($lines === null) {
            return [];
        }
        $lines = json_decode(json_encode($lines), true);

        return array_is_list($lines) ? $lines : [$lines];
    }

    /** @return array<string, array<string, mixed>> */
    private static function keyed(array $lines, string $key): array
    {
        $byId = [];
        foreach ($lines as $i => $line) {
            $byId[(string) ($line[$key] ?? "new{$i}")] = $line;
        }

        return $byId;
    }
}
