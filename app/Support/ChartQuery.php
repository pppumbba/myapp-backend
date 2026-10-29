<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Works out the numbers behind a chart meta.
 *
 * A chart reads from its table_name (any column of the table) or its query_string (the
 * query's columns; :user_id and :today are bound). Everything else is json_config:
 *
 *   type      column | bar | line | area | pie | donut | kpi | table
 *   x         the column to group by (not for kpi); bucket: day | week | month | quarter | year
 *             groups a date column by period
 *   measures  [{agg: count | sum | avg | min | max, column, label}], up to 4; count needs no column
 *   split     a column whose values become the series (only the first measure is used)
 *   filters   [{column, op: = != > >= < <= contains empty not_empty, value}]
 *   date_field  the date column a dashboard's date range applies to; a kpi also gets the
 *             value for the period before
 *   sort      value (biggest first) | label; limit how many groups to keep
 *   x_lookup  {meta_id, label_field}: x holds ids of that meta's table; show its label_field
 *   format    number | money | percent (for the frontend)
 *
 * Only names that are real columns of the source reach the SQL, always quoted by the
 * grammar; values are bound. Records in workflow statuses the user may not see are left out.
 */
class ChartQuery
{
    public const TYPES = ['column', 'bar', 'line', 'area', 'pie', 'donut', 'kpi', 'table'];

    public const AGGREGATES = ['count', 'sum', 'avg', 'min', 'max'];

    public const BUCKETS = ['day', 'week', 'month', 'quarter', 'year'];

    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'empty', 'not_empty'];

    /** A split shows this many series at most; the rest fold into "Other" when they can. */
    private const MAX_SERIES = 6;

    /** Groups read before sorting and limiting, so a careless x can't pull a whole table. */
    private const MAX_GROUPS = 5000;

    /**
     * The columns a chart can use, with a rough kind for the builder's pickers.
     *
     * @return array<int, array{name: string, kind: string}>
     */
    public static function columns(Meta $chart, ?User $user): array
    {
        $sql = trim((string) $chart->query_string);
        if ($sql === '') {
            return array_map(fn (array $c) => ['name' => $c['name'], 'kind' => self::kind($c['type_name'] ?? $c['type'] ?? '')], Schema::getColumns(self::table($chart)));
        }

        [$sql, $bindings] = NamedSql::bind($sql, NamedSql::builtins($user));

        return self::read(function () use ($sql, $bindings) {
            $statement = DB::connection()->getPdo()->prepare("SELECT * FROM ({$sql}) q LIMIT 1");
            $statement->execute($bindings);
            $sample = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $meta = $statement->getColumnMeta($i) ?: [];
                $name = $meta['name'] ?? "column_{$i}";
                $kind = self::kind((string) ($meta['native_type'] ?? ''));
                // SQLite (and some drivers) report dates as text: look at the value.
                if ($kind === 'text' && is_string($sample[$name] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $sample[$name])) {
                    $kind = 'date';
                }
                $columns[] = ['name' => $name, 'kind' => $kind];
            }

            return $columns;
        });
    }

    /**
     * Runs the chart. from/to (YYYY-MM-DD, both days included) narrow date_field.
     *
     * @return array{type: string, format: string, group_by: ?string, bucket: ?string, series: array, rows: array, truncated: bool, kpi?: array}
     */
    public static function run(Meta $chart, array $config, ?User $user, ?string $from = null, ?string $to = null): array
    {
        $names = array_column(self::columns($chart, $user), 'name');
        $c = self::config($config, $names);
        $range = [self::day($from), self::day($to)];

        $base = function (array $range) use ($chart, $user, $c, $names): Builder {
            $query = self::source($chart, $user);
            if (in_array('status_id', $names, true) && ($hidden = Access::hiddenStatuses($user))) {
                $query->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $hidden));
            }
            foreach ($c['filters'] as $f) {
                self::filter($query, $f);
            }
            if ($c['date_field'] && $range[0]) {
                $query->whereDate($c['date_field'], '>=', $range[0]);
            }
            if ($c['date_field'] && $range[1]) {
                $query->whereDate($c['date_field'], '<=', $range[1]);
            }

            return $query;
        };

        $series = array_map(fn (array $m, int $i) => ['key' => "m{$i}", 'label' => $m['label']], $c['measures'], array_keys($c['measures']));
        $out = ['type' => $c['type'], 'format' => $c['format'], 'group_by' => $c['x'], 'bucket' => $c['bucket'], 'series' => $series, 'rows' => [], 'truncated' => false];

        if ($c['type'] === 'kpi') {
            $value = fn (array $range) => self::read(function () use ($base, $range, $c) {
                $query = $base($range);
                $row = $query->selectRaw(self::aggregate($query, $c['measures'][0]).' AS m0')->first();

                return self::number($row?->m0);
            });
            $previous = null;
            if ($c['date_field'] && $range[0] && $range[1]) {
                $days = (int) round((strtotime($range[1]) - strtotime($range[0])) / 86400) + 1;
                $previous = $value([date('Y-m-d', strtotime("{$range[0]} -{$days} days")), date('Y-m-d', strtotime("{$range[0]} -1 day"))]);
            }

            return $out + ['kpi' => ['value' => $value($range), 'previous' => $previous]];
        }

        $groups = self::read(function () use ($base, $range, $c) {
            $query = $base($range);
            $x = $c['bucket'] ? self::bucket($query, $c['x'], $c['bucket']) : $query->getGrammar()->wrap($c['x']);
            $select = ["{$x} AS x"];
            if ($c['split']) {
                $select[] = $query->getGrammar()->wrap($c['split']).' AS s';
            }
            foreach ($c['split'] ? [$c['measures'][0]] : $c['measures'] as $i => $m) {
                $select[] = self::aggregate($query, $m)." AS m{$i}";
            }
            $query->selectRaw(implode(', ', $select))->groupByRaw($x);
            if ($c['split']) {
                $query->groupBy($c['split']);
            }

            return $query->limit(self::MAX_GROUPS)->get();
        });

        $additive = in_array($c['measures'][0]['agg'], ['count', 'sum'], true);
        [$rows, $series, $truncated] = $c['split'] ? self::pivot($groups, $additive) : [
            $groups->map(fn ($g) => ['x' => self::key($g->x), 'values' => array_combine(array_column($series, 'key'), array_map(fn ($i) => self::number($g->{"m{$i}"}), array_keys($series)))])->all(),
            $series,
            false,
        ];

        [$rows, $cut] = self::sortAndLimit($rows, $c, $additive && ! $c['split'] && in_array($c['type'], ['pie', 'donut'], true));

        $labels = self::lookupLabels($c['x_lookup'], array_column($rows, 'x'));
        $rows = array_map(fn (array $r) => $r + ['label' => $r['x'] === '__other__' ? 'Other' : ($labels[$r['x']] ?? $r['x'])], $rows);

        return ['series' => $series, 'rows' => $rows, 'truncated' => $truncated || $cut || $groups->count() >= self::MAX_GROUPS] + $out;
    }

    /** The config with defaults, or 422 naming what's wrong. */
    private static function config(array $config, array $names): array
    {
        $column = function (mixed $value, string $what, bool $required = false) use ($names): ?string {
            if ($value === null || $value === '') {
                abort_if($required, 422, __('The chart needs :what.', ['what' => $what]));

                return null;
            }
            abort_unless(is_string($value) && in_array($value, $names, true), 422, __('The chart\'s :what ":value" is not a column of its data.', ['what' => $what, 'value' => is_scalar($value) ? $value : '?']));

            return $value;
        };

        $type = in_array($config['type'] ?? null, self::TYPES, true) ? $config['type'] : 'column';

        $measures = [];
        foreach (array_slice(is_array($config['measures'] ?? null) ? $config['measures'] : [], 0, 4) as $m) {
            $agg = $m['agg'] ?? 'count';
            abort_unless(in_array($agg, self::AGGREGATES, true), 422, 'A measure must count, sum, average, or take the lowest or highest value.');
            $col = $column($m['column'] ?? null, 'measure column', $agg !== 'count');
            $measures[] = ['agg' => $agg, 'column' => $col, 'label' => is_string($m['label'] ?? null) && $m['label'] !== '' ? $m['label'] : self::measureLabel($agg, $col)];
        }
        $measures = $measures ?: [['agg' => 'count', 'column' => null, 'label' => 'Count']];
        // A pie shows one measure, a kpi one number.
        if (in_array($type, ['pie', 'donut', 'kpi'], true)) {
            $measures = [$measures[0]];
        }

        $bucket = in_array($config['bucket'] ?? null, self::BUCKETS, true) ? $config['bucket'] : null;
        $sort = in_array($config['sort'] ?? null, ['value', 'label'], true) ? $config['sort'] : ($bucket || in_array($type, ['line', 'area'], true) ? 'label' : 'value');
        $limit = is_numeric($config['limit'] ?? null) ? max(1, min(500, (int) $config['limit'])) : ($bucket ? null : (in_array($type, ['pie', 'donut'], true) ? 6 : 12));

        $filters = [];
        foreach (is_array($config['filters'] ?? null) ? $config['filters'] : [] as $f) {
            $op = $f['op'] ?? '=';
            abort_unless(in_array($op, self::OPERATORS, true), 422, __('A chart filter can\'t use ":op".', ['op' => $op]));
            $value = $f['value'] ?? null;
            abort_unless($value === null || is_scalar($value), 422, 'A chart filter value must be text or a number.');
            $filters[] = ['column' => $column($f['column'] ?? null, 'filter column', true), 'op' => $op, 'value' => $value];
        }

        $lookup = $config['x_lookup'] ?? null;
        $lookup = is_array($lookup) && is_numeric($lookup['meta_id'] ?? null) && is_string($lookup['label_field'] ?? null)
            ? ['meta_id' => (int) $lookup['meta_id'], 'label_field' => $lookup['label_field']]
            : null;

        return [
            'type' => $type,
            'x' => $column($config['x'] ?? null, 'a column to group by', $type !== 'kpi'),
            'bucket' => $bucket,
            'measures' => $measures,
            'split' => in_array($type, ['pie', 'donut', 'kpi'], true) ? null : $column($config['split'] ?? null, 'split column'),
            'filters' => $filters,
            'date_field' => $column($config['date_field'] ?? null, 'date column'),
            'sort' => $sort,
            'limit' => $limit,
            'x_lookup' => $lookup,
            'format' => in_array($config['format'] ?? null, ['number', 'money', 'percent'], true) ? $config['format'] : 'number',
        ];
    }

    private static function measureLabel(string $agg, ?string $column): string
    {
        $name = $column ? Str::of($column)->replace('_', ' ')->lower()->toString() : '';

        return match ($agg) {
            'count' => $column ? "Count of {$name}" : 'Count',
            'sum' => Str::ucfirst("total {$name}"),
            'avg' => Str::ucfirst("average {$name}"),
            'min' => Str::ucfirst("lowest {$name}"),
            'max' => Str::ucfirst("highest {$name}"),
        };
    }

    private static function source(Meta $chart, ?User $user): Builder
    {
        $sql = trim((string) $chart->query_string);
        if ($sql === '') {
            return DB::table(self::table($chart));
        }
        [$sql, $bindings] = NamedSql::bind($sql, NamedSql::builtins($user));

        return DB::query()->fromRaw("({$sql}) as q", $bindings);
    }

    /** The chart's table, or 422 when it can't be read. */
    private static function table(Meta $chart): string
    {
        $table = trim((string) $chart->table_name);
        abort_if($table === '', 422, 'This chart has no table or query to read from yet.');
        abort_if(in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true), 422, 'This table cannot be used by a meta.');
        abort_unless(Schema::hasTable($table), 422, __('The table ":table" does not exist.', ['table' => $table]));

        return $table;
    }

    private static function filter(Builder $query, array $f): void
    {
        $col = $f['column'];
        match ($f['op']) {
            'contains' => $query->where($col, 'like', '%'.$f['value'].'%'),
            'empty' => $query->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')),
            'not_empty' => $query->whereNotNull($col)->where($col, '!=', ''),
            default => $f['value'] === null ? ($f['op'] === '!=' ? $query->whereNotNull($col) : $query->whereNull($col)) : $query->where($col, $f['op'], $f['value']),
        };
    }

    private static function aggregate(Builder $query, array $m): string
    {
        if ($m['agg'] === 'count') {
            return $m['column'] ? 'COUNT('.$query->getGrammar()->wrap($m['column']).')' : 'COUNT(*)';
        }

        return strtoupper($m['agg']).'('.$query->getGrammar()->wrap($m['column']).')';
    }

    /** A date column as its period: 2026-10-20, 2026-W43, 2026-10, 2026-Q4 or 2026. */
    private static function bucket(Builder $query, string $column, string $bucket): string
    {
        $c = $query->getGrammar()->wrap($column);

        return match ($query->getConnection()->getDriverName()) {
            'sqlite' => match ($bucket) {
                'day' => "strftime('%Y-%m-%d', {$c})",
                'week' => "strftime('%Y-W%W', {$c})",
                'month' => "strftime('%Y-%m', {$c})",
                'quarter' => "(strftime('%Y', {$c}) || '-Q' || ((CAST(strftime('%m', {$c}) AS INTEGER) + 2) / 3))",
                'year' => "strftime('%Y', {$c})",
            },
            'pgsql' => match ($bucket) {
                'day' => "to_char({$c}, 'YYYY-MM-DD')",
                'week' => "to_char({$c}, 'IYYY-\"W\"IW')",
                'month' => "to_char({$c}, 'YYYY-MM')",
                'quarter' => "to_char({$c}, 'YYYY-\"Q\"Q')",
                'year' => "to_char({$c}, 'YYYY')",
            },
            default => match ($bucket) {
                'day' => "DATE_FORMAT({$c}, '%Y-%m-%d')",
                'week' => "DATE_FORMAT({$c}, '%x-W%v')",
                'month' => "DATE_FORMAT({$c}, '%Y-%m')",
                'quarter' => "CONCAT(YEAR({$c}), '-Q', QUARTER({$c}))",
                'year' => "DATE_FORMAT({$c}, '%Y')",
            },
        };
    }

    /**
     * Split groups (x, s, m0) as one row per x with a value per series. The biggest
     * MAX_SERIES - 1 series stay; for counts and sums the rest add up into "Other".
     *
     * @return array{0: array, 1: array, 2: bool} rows, series, whether series were dropped
     */
    private static function pivot($groups, bool $additive): array
    {
        $totals = [];
        foreach ($groups as $g) {
            $s = self::key($g->s) ?? '';
            $totals[$s] = ($totals[$s] ?? 0) + (float) $g->m0;
        }
        arsort($totals);
        $keep = count($totals) > self::MAX_SERIES ? array_slice(array_keys($totals), 0, self::MAX_SERIES - 1) : array_keys($totals);
        $other = count($totals) > count($keep) && $additive;

        $series = [];
        foreach ($keep as $i => $s) {
            $series[$s] = ['key' => "s{$i}", 'label' => $s === '' ? '(empty)' : (string) $s];
        }
        if ($other) {
            $series['__other__'] = ['key' => 's'.count($keep), 'label' => 'Other'];
        }

        $rows = [];
        foreach ($groups as $g) {
            $x = self::key($g->x);
            $s = self::key($g->s) ?? '';
            $target = $series[$s] ?? ($other ? $series['__other__'] : null);
            if (! $target) {
                continue;
            }
            $rows[$x ?? "\0"] ??= ['x' => $x, 'values' => array_fill_keys(array_column($series, 'key'), null)];
            $rows[$x ?? "\0"]['values'][$target['key']] = self::number(($rows[$x ?? "\0"]['values'][$target['key']] ?? 0) + (float) $g->m0);
        }

        return [array_values($rows), array_values($series), count($totals) > count($keep) && ! $additive];
    }

    /**
     * Orders the rows (by their total, biggest first, or by label) and keeps limit of them.
     * $foldRest adds what's cut off as one "Other" row (pies with counts or sums).
     *
     * @return array{0: array, 1: bool} rows, whether rows were cut
     */
    private static function sortAndLimit(array $rows, array $c, bool $foldRest): array
    {
        $total = fn (array $r) => array_sum(array_map('floatval', array_filter($r['values'], fn ($v) => $v !== null)));
        usort($rows, $c['sort'] === 'value'
            ? fn ($a, $b) => $total($b) <=> $total($a)
            : fn ($a, $b) => [$a['x'] === null, $a['x']] <=> [$b['x'] === null, $b['x']]);

        if (! $c['limit'] || count($rows) <= $c['limit']) {
            return [$rows, false];
        }
        if (! $foldRest) {
            return [array_slice($rows, 0, $c['limit']), true];
        }
        $kept = array_slice($rows, 0, $c['limit'] - 1);
        $rest = array_sum(array_map($total, array_slice($rows, $c['limit'] - 1)));

        return [[...$kept, ['x' => '__other__', 'values' => ['m0' => self::number($rest)]]], false];
    }

    /** id -> label from the x_lookup meta's table, for the ids on the chart. */
    private static function lookupLabels(?array $lookup, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null && $id !== '__other__'));
        if (! $lookup || ! $ids) {
            return [];
        }
        $table = trim((string) Meta::whereKey($lookup['meta_id'])->value('table_name'));
        if ($table === '' || in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true) || ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $lookup['label_field']) || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)->whereIn('id', $ids)->pluck($lookup['label_field'], 'id')
            ->mapWithKeys(fn ($label, $id) => [(string) $id => (string) $label])->all();
    }

    private static function key(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private static function day(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }

    private static function kind(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            (bool) preg_match('/date|time|year/', $type) => 'date',
            (bool) preg_match('/int|dec|num|float|double|real|money|long|short|tiny/', $type) => 'number',
            (bool) preg_match('/bool/', $type) => 'boolean',
            default => 'text',
        };
    }

    /** Runs a read; SQL that fails says why, so whoever built the chart can fix it. */
    private static function read(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException|\PDOException $e) {
            $message = $e instanceof QueryException ? ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage()) : $e->getMessage();
            abort(422, 'The chart could not run: '.($message ?? 'unknown error').'.');
        }
    }
}
