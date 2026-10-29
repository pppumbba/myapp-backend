<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a stage query: a meta whose query_string changes data (INSERT, UPDATE, DELETE or
 * REPLACE; several statements separated by ;). Its params are its named parameters: a
 * param's field_path is the :name, is_required makes it needed, default_value is used when
 * none is given. :user_id and :today are always there. Values are bound, never pasted.
 *
 * A run is one transaction: a statement that fails undoes the ones before it. A dry run
 * does everything and then undoes it, to see how many rows would change. Stage queries
 * run on their own (POST /metas/{meta}/run), as process flow steps after a form saves,
 * and on schedules once per datagrid row (ScheduledForms). Running one needs edit access
 * to it. Statements that create, drop or alter anything are refused, and so is any
 * statement naming a system table (MetaDataController::BLOCKED_TABLES).
 */
class StageQuery
{
    public const STARTS = ['insert', 'update', 'delete', 'replace', 'with'];

    /**
     * Runs it as this user with these parameter values.
     *
     * @return array{affected: int, statements: list<int>, dry_run: bool}
     */
    public static function run(Meta $meta, array $values, ?User $user, bool $dryRun = false): array
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'stage_query', 422, 'Only stage queries run like this.');
        Access::require($user, $meta, 'edit');

        $statements = self::statements((string) $meta->query_string);
        $values = self::values($meta, $values, $user) + NamedSql::builtins($user);

        $counts = [];
        $go = function () use ($statements, $values, &$counts) {
            foreach ($statements as $sql) {
                [$sql, $bindings] = NamedSql::bind($sql, $values);
                $counts[] = DB::affectingStatement($sql, $bindings);
            }
        };

        try {
            if ($dryRun) {
                DB::beginTransaction();
                try {
                    $go();
                } finally {
                    DB::rollBack();
                }
            } else {
                DB::transaction($go);
            }
        } catch (QueryException $e) {
            $n = count($counts) + 1;
            abort(422, count($statements) > 1
                ? __('Statement :number could not run: :error.', ['number' => $n, 'error' => $e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'unknown error'])
                : __('The query could not run: :error.', ['error' => $e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'unknown error']));
        }

        return ['affected' => array_sum($counts), 'statements' => $counts, 'dry_run' => $dryRun];
    }

    /**
     * The statements of a stage query, each checked: it must change data, and not a system table.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = array_values(array_filter(array_map('trim', self::split(self::withoutComments($sql))), fn ($s) => $s !== ''));
        abort_if($statements === [], 422, 'This stage query has no SQL yet.');

        foreach ($statements as $i => $statement) {
            $label = count($statements) > 1 ? 'Statement '.($i + 1) : 'The query';
            $first = strtolower(strtok($statement, " \t\r\n("));
            abort_unless(in_array($first, self::STARTS, true), 422, __(':label must be an INSERT, UPDATE, DELETE or REPLACE.', ['label' => $label]));
            $words = preg_split('/[^a-z0-9_]+/', strtolower(self::unquoted($statement)), -1, PREG_SPLIT_NO_EMPTY);
            if ($blocked = array_values(array_intersect($words, MetaDataController::BLOCKED_TABLES))) {
                abort(422, __(':label uses ":table", a system table that stage queries cannot touch.', ['label' => $label, 'table' => $blocked[0]]));
            }
            if ($first === 'with' && ! array_intersect($words, ['insert', 'update', 'delete'])) {
                abort(422, __(':label must change data: WITH … then INSERT, UPDATE or DELETE.', ['label' => $label]));
            }
        }

        return $statements;
    }

    /**
     * Parameter values by name: the given one, else the param's default (its {placeholders},
     * e.g. {sessionemployeeid}, filled for the user), else null. A required parameter without a
     * value stops with 422.
     *
     * @return array<string, mixed>
     */
    public static function values(Meta $meta, array $given, ?User $user = null): array
    {
        $values = [];
        $missing = [];
        foreach (MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get() as $param) {
            $value = $given[$param->field_path] ?? null;
            if ($value === null || $value === '') {
                $value = $param->default_value === null || $param->default_value === '' ? null : SessionValues::fill($param->default_value, $user);
            }
            if ($value === null && $param->is_required) {
                $missing[] = $param->label_name ?: $param->field_path;
            }
            $values[$param->field_path] = match (true) {
                $value === null => null,
                $param->data_type === 'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0,
                is_scalar($value) => $value,
                default => json_encode($value),
            };
        }
        abort_if($missing !== [], 422, 'Fill in '.implode(', ', $missing).'.');

        return $values;
    }

    /** Splits on ; outside quotes ('...', "...", `...`). */
    private static function split(string $sql): array
    {
        $parts = preg_split('/(\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`[^`]*`)/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $statements = [''];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $statements[count($statements) - 1] .= $part;

                continue;
            }
            $pieces = explode(';', $part);
            $statements[count($statements) - 1] .= array_shift($pieces);
            foreach ($pieces as $piece) {
                $statements[] = $piece;
            }
        }

        return $statements;
    }

    /** The SQL without -- and /* *\/ comments, leaving quoted text alone. */
    private static function withoutComments(string $sql): string
    {
        return preg_replace_callback('/(\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`[^`]*`)|--[^\n]*|\/\*.*?\*\//s', fn ($m) => ($m[1] ?? '') !== '' ? $m[1] : ' ', $sql);
    }

    /** The SQL with '...' text taken out (names in backticks or "..." stay). */
    private static function unquoted(string $sql): string
    {
        return preg_replace('/\'(?:[^\']|\'\')*\'/', "''", $sql);
    }
}
