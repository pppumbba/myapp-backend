<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;

/**
 * A query meta: a SELECT drawn in the query builder and kept in meta_params, compiled to
 * the meta's query_string so grids, statements and other queries can read from it.
 *
 * A design is {tables, columns, where, having, unions, distinct, limit, offset}:
 *  - tables: [{key, source: table|query, name, meta_id, bind, x, y, join}] — key is the
 *    alias; a query source is any meta with a stored SELECT (see sources), read as a
 *    sub-query, its :named parameters filled by bind. Every table after the first has join
 *    {type: inner|left|right|cross, on: [{left, op, right}]}.
 *  - columns: [{expr, alias, label, data_type, show, aggregate, sort, sort_order}] — expr is
 *    an operand: {table, column}, {custom: "SQL expression"} or a stored query
 *    {subquery: meta id, column?, bind: {name: {value}|{param}|operand}}, which a column
 *    in bind makes correlated (e.g. each order's line count). aggregate is group, sum,
 *    avg, min, max, count or count_distinct; with any of them the other shown columns are
 *    grouped by. sort is asc or desc, ordered by sort_order.
 *  - where, having: a group {logic: and|or, items: [group | condition]}; a condition is
 *    {left, aggregate?, op, value_type: value|param|column|custom|subquery, value, value2,
 *    right}; with a stored query on the right, in/not_in read its rows, and exists /
 *    not_exists need no left side.
 *    A param value is a :name the screen fills (a grid's filter); left empty, the
 *    condition is skipped, so (:name IS NULL OR …).
 *  - unions: [{meta_id, all}] — other query metas whose rows are added.
 *
 * Kept as params by data_type: table (param_name the alias, column_path the table,
 * ref_meta_id a query source, json_config {x, y, join}), column (field_path the alias,
 * expression_string the SQL, json_config {expr, aggregate, sort, sort_order}), where and
 * having (the root groups; groups and conditions nest under them by parent_id) and union;
 * distinct, limit and offset in the meta's json_config.query.
 */
class QueryDesign
{
    public const JOINS = ['inner' => 'INNER JOIN', 'left' => 'LEFT JOIN', 'right' => 'RIGHT JOIN', 'cross' => 'CROSS JOIN'];

    public const AGGREGATES = ['group', 'sum', 'avg', 'min', 'max', 'count', 'count_distinct'];

    public const OPERATORS = ['=', '<>', '<', '<=', '>', '>=', 'like', 'not_like', 'contains', 'starts', 'ends', 'in', 'not_in', 'between', 'not_between', 'null', 'not_null', 'exists', 'not_exists'];

    public const DATA_TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime'];

    public const MAX_TABLES = 20;

    private const MAX_DEPTH = 4;

    private const NAME = '/^[A-Za-z_]\w{0,63}$/';

    /** The query being checked, so sub-queries inside it can't read it back. */
    private static ?Meta $designing = null;

    /** The inline sub-queries of the design being compiled, by key (stored queries compiled inside it swap in their own). */
    private static array $inlines = [];

    /** The column names each inline sub-query of the design being checked selects, by key. */
    private static array $inlineColumns = [];

    /** The empty design a new query starts with. */
    public static function blank(): array
    {
        return self::blankLevel() + ['subqueries' => []];
    }

    /** One SELECT: the main query, or an inline sub-query. */
    private static function blankLevel(): array
    {
        return ['tables' => [], 'columns' => [], 'where' => self::group(), 'having' => self::group(), 'unions' => [], 'distinct' => false, 'limit' => null, 'offset' => null];
    }

    /** A query meta's design, read back from its params. */
    public static function load(Meta $meta): array
    {
        $params = MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->orderBy('id')->get();
        $design = self::loadLevel($params, null, (array) ($meta->json_config['query'] ?? []));
        $design['subqueries'] = [];
        foreach (self::childrenOf($params, null)->where('data_type', 'subquery') as $p) {
            $design['subqueries'][] = ['key' => $p->param_name, 'label' => $p->label_name, 'design' => self::loadLevel($params, $p->id, $p->json_config ?? [])];
        }

        return $design;
    }

    /** The params directly under a param (or at the top). */
    private static function childrenOf(Collection $params, ?int $parentId): Collection
    {
        return $params->filter(fn (MetaParam $p) => $parentId === null ? $p->parent_id === null : (int) $p->parent_id === $parentId);
    }

    /** One level of a design: the params under $parentId, with its DISTINCT, limit and skip. */
    private static function loadLevel(Collection $params, ?int $parentId, array $options): array
    {
        $design = self::blankLevel();
        $design['distinct'] = (bool) ($options['distinct'] ?? false);
        $design['limit'] = isset($options['limit']) ? (int) $options['limit'] : null;
        $design['offset'] = isset($options['offset']) ? (int) $options['offset'] : null;

        foreach (self::childrenOf($params, $parentId) as $p) {
            $c = $p->json_config ?? [];
            match ($p->data_type) {
                'subquery' => null,
                'table' => $design['tables'][] = [
                    'key' => $p->param_name, 'source' => in_array($p->render_type, ['query', 'inline'], true) ? $p->render_type : 'table',
                    'name' => $p->column_path, 'meta_id' => $p->ref_meta_id, 'label' => $p->label_name,
                    'x' => (int) ($c['x'] ?? 0), 'y' => (int) ($c['y'] ?? 0), 'join' => $c['join'] ?? null, 'bind' => (object) ($c['bind'] ?? []),
                ],
                'union' => $design['unions'][] = ['meta_id' => $p->ref_meta_id, 'inline' => $c['inline'] ?? null, 'all' => (bool) ($c['all'] ?? false)],
                'where', 'having' => $design[$p->data_type] = self::loadGroup($p, $params),
                default => $design['columns'][] = [
                    'expr' => $c['expr'] ?? ['custom' => (string) $p->expression_string], 'alias' => $p->field_path,
                    'label' => $p->label_name, 'data_type' => $p->data_type, 'show' => (bool) $p->is_show,
                    'aggregate' => $c['aggregate'] ?? null, 'sort' => $c['sort'] ?? null, 'sort_order' => $c['sort_order'] ?? null,
                ],
            };
        }

        return $design;
    }

    /**
     * Checks a design, keeps it as the meta's params and saves its SQL as the query_string.
     *
     * @return array{design: array, sql: string}
     */
    public static function save(Meta $meta, array $input, ?int $userId = null): array
    {
        $design = self::normalize($input, $meta);
        $sql = self::compile($design);

        DB::transaction(function () use ($meta, $design, $sql, $userId) {
            MetaParam::where('main_meta_id', $meta->id)->get()->each->delete();
            $order = 0;
            $make = function (array $values) use ($meta, &$order) {
                return MetaParam::create($values + ['main_meta_id' => $meta->id, 'order_number' => ++$order]);
            };

            self::saveLevel($design, null, $make);
            // An inline sub-query is a param holding its own tables, columns and conditions.
            foreach ($design['subqueries'] as $s) {
                $param = $make([
                    'data_type' => 'subquery', 'param_name' => $s['key'], 'field_path' => $s['key'], 'label_name' => $s['label'],
                    'is_show' => false, 'json_config' => self::options($s['design']) ?: null,
                ]);
                self::saveLevel($s['design'], $param->id, $make);
            }

            $config = $meta->json_config ?? [];
            $config['query'] = self::options($design);
            if (! $config['query']) {
                unset($config['query']);
            }
            $meta->update(['query_string' => $sql, 'json_config' => $config] + ($userId ? ['updated_by' => $userId] : []));
        });

        return ['design' => self::load($meta->fresh()), 'sql' => $sql];
    }

    /** One level of a design as params under $parentId. */
    private static function saveLevel(array $design, ?int $parentId, callable $make): void
    {
        foreach ($design['tables'] as $t) {
            $make([
                'parent_id' => $parentId, 'data_type' => 'table', 'param_name' => $t['key'], 'field_path' => $t['key'],
                'label_name' => $t['label'], 'column_path' => $t['name'], 'ref_meta_id' => $t['meta_id'],
                'render_type' => $t['source'], 'is_show' => false,
                'json_config' => ['x' => $t['x'], 'y' => $t['y']] + ($t['join'] ? ['join' => $t['join']] : []) + ($t['bind'] ? ['bind' => $t['bind']] : []),
            ]);
        }
        foreach ($design['columns'] as $c) {
            $make([
                'parent_id' => $parentId, 'data_type' => $c['data_type'], 'param_name' => $c['alias'], 'field_path' => $c['alias'],
                'label_name' => $c['label'], 'is_show' => $c['show'], 'expression_string' => self::operandText($c['expr']),
                'json_config' => array_filter(['expr' => $c['expr'], 'aggregate' => $c['aggregate'], 'sort' => $c['sort'], 'sort_order' => $c['sort_order']], fn ($v) => $v !== null),
            ]);
        }
        foreach (['where', 'having'] as $kind) {
            if ($design[$kind]['items']) {
                self::saveGroup($design[$kind], $kind, $parentId, $make);
            }
        }
        foreach ($design['unions'] as $u) {
            $make([
                'parent_id' => $parentId, 'data_type' => 'union', 'param_name' => 'union', 'field_path' => 'union', 'ref_meta_id' => $u['meta_id'],
                'is_show' => false, 'json_config' => ['all' => $u['all']] + ($u['inline'] ? ['inline' => $u['inline']] : []),
            ]);
        }
    }

    /** A level's DISTINCT, limit and skip, as kept in json_config. */
    private static function options(array $design): array
    {
        return array_filter(['distinct' => $design['distinct'] ?: null, 'limit' => $design['limit'], 'offset' => $design['offset']], fn ($v) => $v !== null);
    }

    /** An operand's SQL, kept as the column param's expression_string; an inline sub-query by its name. */
    private static function operandText(array $operand): string
    {
        return isset($operand['inline']) ? '(sub-query '.$operand['inline'].')' : self::operand($operand);
    }

    /** The SELECT a (normalized) design stands for, its inline sub-queries put in where they are used. */
    public static function compile(array $design): string
    {
        $previous = self::$inlines;
        self::$inlines = collect($design['subqueries'] ?? [])->mapWithKeys(fn ($s) => [$s['key'] => $s['design']])->all();
        try {
            return self::compileLevel($design);
        } finally {
            self::$inlines = $previous;
        }
    }

    /** One SELECT of a design. */
    private static function compileLevel(array $design): string
    {
        abort_if(! $design['tables'], 422, 'Drag a table or view onto the canvas first.');
        $shown = array_values(array_filter($design['columns'], fn ($c) => $c['show']));
        abort_if(! $shown, 422, 'Tick at least one column to select.');
        $g = DB::connection()->getQueryGrammar();

        $select = array_map(fn ($c) => self::aggregate($c['aggregate'], $c['expr']).' AS '.$g->wrap($c['alias']), $shown);
        $sql = 'SELECT '.($design['distinct'] ? 'DISTINCT ' : '').implode(', ', $select);

        foreach ($design['tables'] as $i => $t) {
            $source = match ($t['source']) {
                'query' => '('.self::sourceSql((int) $t['meta_id'], (array) ($t['bind'] ?? [])).') AS '.$g->wrap($t['key']),
                'inline' => '('.self::inlineSql($t['name'], null).') AS '.$g->wrap($t['key']),
                default => $g->wrapTable($t['name']).' AS '.$g->wrap($t['key']),
            };
            if ($i === 0) {
                $sql .= "\nFROM {$source}";

                continue;
            }
            $join = $t['join'];
            $sql .= "\n".self::JOINS[$join['type']].' '.$source;
            if ($join['type'] !== 'cross') {
                $sql .= ' ON '.implode(' AND ', array_map(fn ($on) => self::operand($on['left']).' '.$on['op'].' '.self::operand($on['right']), $join['on']));
            }
        }

        if ($where = self::condition($design['where'], false)) {
            $sql .= "\nWHERE {$where}";
        }

        $aggregated = collect($design['columns'])->contains(fn ($c) => $c['aggregate'] !== null);
        if ($aggregated) {
            $groups = collect($design['columns'])
                ->filter(fn ($c) => $c['aggregate'] === 'group' || ($c['aggregate'] === null && $c['show']))
                ->map(fn ($c) => self::operand($c['expr']))->unique()->values();
            if ($groups->isNotEmpty()) {
                $sql .= "\nGROUP BY ".$groups->implode(', ');
            }
        }

        if ($having = self::condition($design['having'], true)) {
            $sql .= "\nHAVING {$having}";
        }

        $sorts = collect($design['columns'])->filter(fn ($c) => $c['sort'] !== null)
            ->sortBy(fn ($c, $i) => [$c['sort_order'] ?? PHP_INT_MAX, $i])
            ->map(fn ($c) => ($c['show'] ? $g->wrap($c['alias']) : self::aggregate($c['aggregate'], $c['expr'])).' '.strtoupper($c['sort']));
        if ($sorts->isNotEmpty()) {
            $sql .= "\nORDER BY ".$sorts->implode(', ');
        }

        if ($design['limit'] !== null || $design['offset'] !== null) {
            // SQLite and MySQL both need a LIMIT before an OFFSET.
            $sql .= "\nLIMIT ".($design['limit'] ?? PHP_INT_MAX).($design['offset'] ? " OFFSET {$design['offset']}" : '');
        }

        if ($design['unions']) {
            $sql = 'SELECT * FROM ('.$sql.') AS '.$g->wrap('u0');
            foreach ($design['unions'] as $i => $u) {
                $part = $u['inline'] ?? null ? self::inlineSql($u['inline'], null) : self::sourceSql((int) $u['meta_id']);
                $sql .= "\nUNION ".($u['all'] ? 'ALL ' : '').'SELECT * FROM ('.$part.') AS '.$g->wrap('u'.($i + 1));
            }
        }

        return $sql;
    }

    /**
     * An inline sub-query's SQL; with a column, selecting just that one (see onlyColumn), so
     * it needs no wrapping that, in MySQL, couldn't see the outer row.
     */
    private static function inlineSql(string $key, ?string $column): string
    {
        $design = self::$inlines[$key] ?? null;
        abort_unless($design, 422, __('There\'s no sub-query ":key" in this query.', ['key' => $key]));
        if ($column === null) {
            return self::compileLevel($design);
        }
        if ($design['unions']) {
            $g = DB::connection()->getQueryGrammar();

            return 'SELECT '.$g->wrap($column).' FROM ('.self::compileLevel($design).') AS '.$g->wrap('sq');
        }

        return self::compileLevel(self::onlyColumn($design, $column));
    }

    /** A level selecting just one of its columns, the others kept for grouping and order. */
    private static function onlyColumn(array $design, string $column): array
    {
        $grouped = collect($design['columns'])->contains(fn ($c) => $c['aggregate'] !== null);
        $design['columns'] = array_map(fn ($c) => $c['alias'] === $column || ! $c['show'] ? $c : [
            'show' => false, 'aggregate' => $c['aggregate'] ?? ($grouped ? 'group' : null),
        ] + $c, $design['columns']);

        return $design;
    }

    /**
     * Checks a design sent by the builder and gives it back in its stored shape: names that
     * exist, operands that name a table on the canvas and a column it has, known operators.
     * Table aliases are unique across the main query and its inline sub-queries, so a
     * sub-query can use the main query's columns (correlated) without them being mistaken
     * for its own. Stops with 422 on the first problem.
     */
    public static function normalize(array $input, Meta $meta): array
    {
        self::$designing = $meta;
        $columnsOf = [];

        // An inline sub-query's columns are the names it selects.
        $subInputs = array_values(array_filter((array) ($input['subqueries'] ?? []), 'is_array'));
        abort_if(count($subInputs) > self::MAX_TABLES, 422, 'A query can have up to '.self::MAX_TABLES.' sub-queries.');
        $inlineColumns = [];
        foreach ($subInputs as $s) {
            $key = (string) ($s['key'] ?? '');
            abort_unless(preg_match(self::NAME, $key), 422, __('":key" can\'t be a sub-query\'s name: use letters, digits and _.', ['key' => $key]));
            abort_if(isset($inlineColumns[$key]), 422, __('Two sub-queries are both called ":key". Rename one.', ['key' => $key]));
            $inlineColumns[$key] = array_values(array_map(fn ($c) => (string) ($c['alias'] ?? ''), array_filter(
                (array) ($s['design']['columns'] ?? []), fn ($c) => is_array($c) && ($c['show'] ?? true),
            )));
        }
        self::$inlineColumns = $inlineColumns;

        // Every level's tables first: a sub-query may use the main query's columns.
        $design = self::blankLevel();
        $design['tables'] = self::checkTables((array) ($input['tables'] ?? []), $meta, $columnsOf);
        $own = array_intersect_key($columnsOf, array_flip(array_column($design['tables'], 'key')));
        $subs = [];
        foreach ($subInputs as $s) {
            $level = self::blankLevel();
            $level['tables'] = self::checkTables((array) ($s['design']['tables'] ?? []), $meta, $columnsOf);
            abort_if(! $level['tables'], 422, __('The sub-query ":key" has no table yet.', ['key' => $s['key']]));
            $subs[] = ['key' => (string) $s['key'], 'label' => is_string($s['label'] ?? null) && trim($s['label']) !== '' ? mb_substr(trim($s['label']), 0, 255) : null, 'level' => $level, 'input' => (array) ($s['design'] ?? [])];
        }

        $design = self::checkRest($input, $meta, $own) + $design;
        $design['subqueries'] = array_map(fn ($s) => [
            'key' => $s['key'], 'label' => $s['label'], 'design' => self::checkRest($s['input'], $meta, $columnsOf) + $s['level'],
        ], $subs);

        self::checkInlineUse($design);

        return $design;
    }

    /**
     * A level's tables: aliases unique across the whole design, sources that exist, joins on
     * columns they have. Fills $columnsOf (alias => column names).
     */
    private static function checkTables(array $tables, Meta $meta, array &$columnsOf): array
    {
        $tables = array_values(array_filter($tables, 'is_array'));
        abort_if(count($tables) > self::MAX_TABLES, 422, 'A query can join up to '.self::MAX_TABLES.' tables.');
        $out = [];
        foreach ($tables as $i => $t) {
            $key = (string) ($t['key'] ?? '');
            abort_unless(preg_match(self::NAME, $key), 422, __('":key" can\'t be a table\'s alias: use letters, digits and _.', ['key' => $key]));
            abort_if(isset($columnsOf[$key]), 422, __('Two tables are both called ":key" (sub-queries included). Give one another alias.', ['key' => $key]));
            $source = in_array($t['source'] ?? 'table', ['query', 'inline'], true) ? $t['source'] : 'table';
            $bind = [];
            $metaId = null;
            if ($source === 'query') {
                $sub = self::queryMeta((int) ($t['meta_id'] ?? 0), $meta);
                $columnsOf[$key] = self::queryColumns($sub);
                $name = null;
                $metaId = $sub->id;
                $label = $sub->name;
                // In FROM it can't see the other tables (that needs LATERAL), so values and parameters only.
                $bind = self::checkBind($t['bind'] ?? [], $sub, null);
            } elseif ($source === 'inline') {
                $name = (string) ($t['name'] ?? '');
                abort_unless(isset(self::$inlineColumns[$name]), 422, __('There\'s no sub-query ":name" in this query.', ['name' => $name]));
                $columnsOf[$key] = self::$inlineColumns[$name];
                $label = $name;
            } else {
                $name = (string) ($t['name'] ?? '');
                $columnsOf[$key] = array_column(self::columns($name), 'name');
                $label = $name;
            }

            $join = null;
            if ($i > 0) {
                $j = (array) ($t['join'] ?? []);
                $type = $j['type'] ?? 'inner';
                abort_unless(isset(self::JOINS[$type]), 422, __('":key" has an unknown join ":type".', ['key' => $key, 'type' => $type]));
                $on = [];
                if ($type !== 'cross') {
                    foreach (array_filter((array) ($j['on'] ?? []), 'is_array') as $cond) {
                        $op = (string) ($cond['op'] ?? '=');
                        abort_unless(in_array($op, ['=', '<>', '<', '<=', '>', '>='], true), 422, __('A join can\'t compare with ":op".', ['op' => $op]));
                        $on[] = ['left' => self::checkColumn($cond['left'] ?? null, $columnsOf), 'op' => $op, 'right' => self::checkColumn($cond['right'] ?? null, $columnsOf)];
                    }
                    abort_if(! $on, 422, __('Connect ":key" to another table: drag a column onto the matching one, or make it a cross join.', ['key' => $key]));
                }
                $join = ['type' => $type, 'on' => $on];
            }

            $out[] = [
                'key' => $key, 'source' => $source, 'name' => $name, 'meta_id' => $metaId, 'label' => $label,
                'x' => (int) ($t['x'] ?? 0), 'y' => (int) ($t['y'] ?? 0), 'join' => $join, 'bind' => $bind,
            ];
        }

        return $out;
    }

    /** A level's columns, conditions, unions, DISTINCT, limit and skip, its operands checked against $columnsOf. */
    private static function checkRest(array $input, Meta $meta, array $columnsOf): array
    {
        $design = ['columns' => [], 'unions' => []];
        $aliases = [];
        foreach (array_values(array_filter((array) ($input['columns'] ?? []), 'is_array')) as $c) {
            $alias = (string) ($c['alias'] ?? '');
            abort_unless(preg_match(self::NAME, $alias), 422, __('":alias" can\'t be a column\'s name: use letters, digits and _.', ['alias' => $alias]));
            abort_if(in_array($alias, $aliases, true), 422, __('Two columns are both called ":alias". Rename one.', ['alias' => $alias]));
            $aliases[] = $alias;
            $aggregate = $c['aggregate'] ?? null;
            abort_unless($aggregate === null || in_array($aggregate, self::AGGREGATES, true), 422, __('":alias" has an unknown total ":aggregate".', ['alias' => $alias, 'aggregate' => $aggregate]));
            $sort = $c['sort'] ?? null;
            $type = $c['data_type'] ?? 'string';
            $design['columns'][] = [
                'expr' => self::checkOperand($c['expr'] ?? null, $columnsOf),
                'alias' => $alias,
                'label' => is_string($c['label'] ?? null) && trim($c['label']) !== '' ? mb_substr(trim($c['label']), 0, 255) : null,
                'data_type' => in_array($type, self::DATA_TYPES, true) ? $type : 'string',
                'show' => (bool) ($c['show'] ?? true),
                'aggregate' => $aggregate,
                'sort' => in_array($sort, ['asc', 'desc'], true) ? $sort : null,
                'sort_order' => is_numeric($c['sort_order'] ?? null) ? (int) $c['sort_order'] : null,
            ];
        }

        $design['where'] = self::checkGroup((array) ($input['where'] ?? []), $columnsOf, false, 1);
        $design['having'] = self::checkGroup((array) ($input['having'] ?? []), $columnsOf, true, 1);

        foreach (array_filter((array) ($input['unions'] ?? []), 'is_array') as $u) {
            if (is_string($u['inline'] ?? null) && $u['inline'] !== '') {
                abort_unless(isset(self::$inlineColumns[$u['inline']]), 422, __('There\'s no sub-query ":key" in this query.', ['key' => $u['inline']]));
                $design['unions'][] = ['meta_id' => null, 'inline' => $u['inline'], 'all' => (bool) ($u['all'] ?? false)];
            } else {
                $design['unions'][] = ['meta_id' => self::queryMeta((int) ($u['meta_id'] ?? 0), $meta)->id, 'inline' => null, 'all' => (bool) ($u['all'] ?? false)];
            }
        }

        $design['distinct'] = (bool) ($input['distinct'] ?? false);
        $limit = $input['limit'] ?? null;
        $offset = $input['offset'] ?? null;
        abort_unless($limit === null || $limit === '' || (is_numeric($limit) && $limit >= 1), 422, 'The row limit must be 1 or more.');
        abort_unless($offset === null || $offset === '' || (is_numeric($offset) && $offset >= 0), 422, 'Skip must be 0 or more rows.');
        $design['limit'] = $limit === null || $limit === '' ? null : (int) $limit;
        $design['offset'] = $offset === null || $offset === '' || (int) $offset === 0 ? null : (int) $offset;

        return $design;
    }

    /**
     * Inline sub-queries that use each other mustn't come round in a circle, and one used as
     * a table (in FROM, or a union) can't use another level's columns: there it can't see them.
     */
    private static function checkInlineUse(array $design): void
    {
        $subs = collect($design['subqueries'])->keyBy('key');
        $uses = $subs->map(fn ($s) => self::inlineRefs($s['design']))->all();
        $visit = function (string $key, array $path) use (&$visit, $uses) {
            abort_if(in_array($key, $path, true), 422, 'The sub-queries '.implode(' → ', [...$path, $key]).' use each other in a circle.');
            foreach ($uses[$key] ?? [] as $next) {
                $visit($next, [...$path, $key]);
            }
        };
        foreach (array_keys($uses) as $key) {
            $visit($key, []);
        }

        $asTable = [];
        foreach ([$design, ...$subs->pluck('design')->all()] as $level) {
            foreach ($level['tables'] as $t) {
                if ($t['source'] === 'inline') {
                    $asTable[] = $t['name'];
                }
            }
            foreach ($level['unions'] as $u) {
                if ($u['inline']) {
                    $asTable[] = $u['inline'];
                }
            }
        }
        foreach (array_unique($asTable) as $key) {
            $sub = $subs[$key]['design'];
            $own = array_column($sub['tables'], 'key');
            $outside = array_diff(array_unique(self::tableRefs(array_diff_key($sub, ['tables' => 1]) + ['joins' => array_column($sub['tables'], 'join')])), $own);
            abort_if($outside !== [], 422, "The sub-query \"{$key}\" is used as a table, so it can't use \"".implode('", "', $outside).'": a table can\'t see the other tables\' rows. Use it as a column or in a condition instead.');
        }
    }

    /** The inline sub-queries a design (or part of one) uses, by key. */
    private static function inlineRefs(array $node): array
    {
        $keys = [];
        if (is_string($node['inline'] ?? null)) {
            $keys[] = $node['inline'];
        }
        if (($node['source'] ?? null) === 'inline' && is_string($node['name'] ?? null)) {
            $keys[] = $node['name'];
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($keys, ...self::inlineRefs($value));
            }
        }

        return array_values(array_unique($keys));
    }

    /** The table aliases the column operands in a design (or part of one) name. */
    private static function tableRefs(array $node): array
    {
        $aliases = is_string($node['table'] ?? null) && isset($node['column']) ? [$node['table']] : [];
        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($aliases, ...self::tableRefs($value));
            }
        }

        return $aliases;
    }

    /**
     * The tables and views a query can read: every one in the database but the system's own.
     *
     * @return list<array{name: string, type: string}>
     */
    public static function tables(): array
    {
        // Only the app's own database: the server may hold others.
        $schema = Schema::getCurrentSchemaName();
        $list = [];
        foreach (Schema::getTables($schema) as $t) {
            $list[] = ['name' => $t['name'], 'type' => 'table'];
        }
        foreach (Schema::getViews($schema) as $v) {
            $list[] = ['name' => $v['name'], 'type' => 'view'];
        }
        $list = array_values(array_filter($list, fn ($t) => self::allowed($t['name'])));
        usort($list, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * A table's or view's columns, with the type a param would have, and its foreign keys.
     *
     * @return list<array{name: string, type: string, data_type: string, nullable: bool}>
     */
    public static function columns(string $table): array
    {
        abort_unless(preg_match('/^[A-Za-z_][\w$]*$/', $table) && self::allowed($table), 422, __('":table" can\'t be used in a query.', ['table' => $table]));
        abort_unless(Schema::hasTable($table) || Schema::hasView($table), 422, __('There is no table or view called ":table".', ['table' => $table]));

        return array_map(fn ($c) => [
            'name' => $c['name'], 'type' => $c['type'], 'data_type' => self::dataType($c['type_name'], $c['type']), 'nullable' => (bool) $c['nullable'],
        ], Schema::getColumns($table));
    }

    /**
     * A table's foreign keys: which of its columns point at which table's column.
     *
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}>
     */
    public static function foreignKeys(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_map(fn ($f) => [
            'columns' => $f['columns'], 'foreign_table' => $f['foreign_table'], 'foreign_columns' => $f['foreign_columns'],
        ], array_filter(Schema::getForeignKeys($table), fn ($f) => self::allowed($f['foreign_table']))));
    }

    /**
     * The :named parameters a query's SQL uses (besides the built-ins), in order.
     *
     * @return list<string>
     */
    public static function namedParams(string $sql): array
    {
        [, , $names] = NamedSql::bind($sql, []);

        return array_values(array_diff(array_unique($names), ['user_id', 'today']));
    }

    /**
     * Makes a datagrid that reads this query (its ref_meta_id): a column for each selected
     * column, and a hidden filter for each :name, typed like the column it is compared with.
     */
    public static function makeGrid(Meta $query, ?int $userId = null, ?string $code = null): Meta
    {
        return DB::transaction(function () use ($query, $userId, $code) {
            $base = $code ?? Str::limit(preg_replace('/_query$/', '', $query->code), 58, '').'_grid';
            $code = $base;
            for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
                $code = "{$base}_{$n}";
            }
            $grid = Meta::create([
                'code' => $code, 'name' => $query->name, 'type_id' => MetaType::where('code', 'datagrid')->value('id'),
                'ref_meta_id' => $query->id, 'description' => $query->description, 'is_active' => true,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);

            $order = 0;
            $columns = MetaParam::where('main_meta_id', $query->id)->whereNull('parent_id')->where('is_show', true)
                ->whereNotIn('data_type', ['table', 'union', 'where', 'having'])->orderBy('order_number')->get();
            foreach ($columns as $c) {
                MetaParam::create([
                    'main_meta_id' => $grid->id, 'order_number' => ++$order, 'field_path' => $c->field_path, 'param_name' => $c->field_path,
                    'label_name' => $c->label_name ?: Str::headline($c->field_path), 'data_type' => $c->data_type,
                    'is_show' => true, 'is_primary' => $c->field_path === 'id', 'is_criteria' => in_array($c->data_type, ['string', 'text'], true),
                    'render_type' => $c->data_type === 'decimal' ? 'money' : null,
                ]);
            }
            $types = self::paramTypes(self::load($query));
            foreach (self::namedParams((string) $query->query_string) as $name) {
                if (! $columns->contains('field_path', $name)) {
                    MetaParam::create([
                        'main_meta_id' => $grid->id, 'order_number' => ++$order, 'field_path' => $name, 'param_name' => $name,
                        'label_name' => Str::headline($name), 'data_type' => $types[$name] ?? 'string', 'is_show' => false, 'is_criteria' => true,
                    ]);
                }
            }

            return $grid;
        });
    }

    /**
     * Each :name's data type: that of the column its condition compares it with.
     *
     * @return array<string, string>
     */
    private static function paramTypes(array $design): array
    {
        $tables = collect($design['tables'])->keyBy('key');
        $types = [];
        $walk = function (array $group) use (&$walk, &$types, $tables) {
            foreach ($group['items'] as $item) {
                if (($item['type'] ?? null) === 'group') {
                    $walk($item);
                } elseif (($item['value_type'] ?? null) === 'param' && isset($item['left']['table'])) {
                    $table = $tables->get($item['left']['table']);
                    if ($table && $table['source'] === 'table') {
                        $column = collect(self::columns($table['name']))->firstWhere('name', $item['left']['column']);
                        $types[$item['value']] ??= $column['data_type'] ?? 'string';
                    }
                }
            }
        };
        $walk($design['where']);
        $walk($design['having']);

        return $types;
    }

    /**
     * For a meta that reads a query meta: that query's sorted columns the meta has, in order,
     * as [column, dir]. Empty when it reads none or the query sorts by none of them.
     *
     * @param  list<string>  $columns
     * @return list<array{0: string, 1: string}>
     */
    public static function defaultOrder(Meta $meta, array $columns): array
    {
        if (trim((string) $meta->query_string) !== '' || trim((string) $meta->table_name) !== '' || self::readFrom($meta) === null) {
            return [];
        }

        return collect(self::load(Meta::find($meta->ref_meta_id))['columns'])
            ->filter(fn ($c) => $c['sort'] && $c['show'] && in_array($c['alias'], $columns, true))
            ->sortBy(fn ($c, $i) => [$c['sort_order'] ?? PHP_INT_MAX, $i])
            ->map(fn ($c) => [$c['alias'], $c['sort']])->values()->all();
    }

    /** The SQL of the query meta this meta's ref_meta_id names, if it names one that has some. */
    public static function readFrom(Meta $meta): ?string
    {
        if (! $meta->ref_meta_id) {
            return null;
        }
        $query = Meta::with('type')->find($meta->ref_meta_id);
        $sql = $query?->type?->code === 'query' ? trim((string) $query->query_string) : '';

        return $sql === '' ? null : $sql;
    }

    /** Whether a query may read this table: not one of the system's own (metas, workflows, users, tags…). */
    public static function allowed(string $table): bool
    {
        $name = strtolower($table);

        return ! in_array($name, [...MetaDataController::BLOCKED_TABLES, 'tags', 'session_values'], true)
            && ! preg_match('/^(meta_|wfm_|sqlite_)/', $name);
    }

    /**
     * The metas whose stored SELECT can be a sub-query: every meta with a query_string that
     * reads (query, datagrid, statement, chart, get…), with the :named parameters it takes.
     *
     * @return list<array{id: int, code: string, name: string, type: ?string, params: list<string>}>
     */
    public static function sources(): array
    {
        return Meta::with('type')->whereNotNull('query_string')->where('query_string', '<>', '')->orderBy('name')->get()
            ->filter(fn (Meta $m) => self::isSelect($m))
            ->map(fn (Meta $m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'type' => $m->type?->code, 'params' => self::namedParams((string) $m->query_string)])
            ->values()->all();
    }

    /** The column names a stored query gives. */
    public static function queryColumns(Meta $query): array
    {
        return array_column(self::describe($query), 'name');
    }

    /**
     * A stored query's columns as the database gives them (it is run for no rows, with its
     * parameters empty), typed like the meta's params of the same name where it has them.
     *
     * @return list<array{name: string, type: string, data_type: string, nullable: bool}>
     */
    public static function describe(Meta $query): array
    {
        abort_unless(self::isSelect($query), 422, __('":name" has no stored SELECT to read from.', ['name' => $query->name]));
        [$sql, $bindings] = NamedSql::bind('SELECT * FROM ('.self::sourceSql($query->id).') AS '.DB::connection()->getQueryGrammar()->wrap('d').' LIMIT 0', []);
        try {
            $statement = DB::connection()->getPdo()->prepare($sql);
            $statement->execute($bindings);
        } catch (PDOException $e) {
            abort(422, "\"{$query->name}\" doesn't run as a sub-query: ".Str::limit($e->getMessage(), 300));
        }

        $known = MetaParam::where('main_meta_id', $query->id)->whereNull('parent_id')->pluck('data_type', 'field_path');
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $column = $statement->getColumnMeta($i);
            $type = (string) ($column['native_type'] ?? '');
            $columns[] = [
                'name' => $column['name'], 'type' => $type, 'nullable' => true,
                'data_type' => in_array($known[$column['name']] ?? null, self::DATA_TYPES, true) ? $known[$column['name']] : self::dataType($type, $type),
            ];
        }

        return $columns;
    }

    private static function group(string $logic = 'and', array $items = []): array
    {
        return ['logic' => $logic, 'items' => $items];
    }

    private static function loadGroup(MetaParam $group, $params): array
    {
        $items = [];
        foreach ($params->where('parent_id', $group->id) as $p) {
            $items[] = $p->data_type === 'group' ? ['type' => 'group'] + self::loadGroup($p, $params) : ['type' => 'condition'] + ($p->json_config ?? []);
        }

        return self::group(($group->json_config['logic'] ?? 'and') === 'or' ? 'or' : 'and', $items);
    }

    private static function saveGroup(array $group, string $type, ?int $parentId, callable $make): void
    {
        $param = $make(['data_type' => $type, 'param_name' => $type, 'field_path' => $type, 'parent_id' => $parentId, 'is_show' => false, 'json_config' => ['logic' => $group['logic']]]);
        foreach ($group['items'] as $item) {
            if (($item['type'] ?? null) === 'group') {
                self::saveGroup($item, 'group', $param->id, $make);
            } else {
                $make(['data_type' => 'condition', 'param_name' => 'condition', 'field_path' => 'condition', 'parent_id' => $param->id, 'is_show' => false, 'json_config' => array_diff_key($item, ['type' => 1])]);
            }
        }
    }

    private static function checkGroup(array $group, array $columnsOf, bool $having, int $depth): array
    {
        abort_if($depth > self::MAX_DEPTH, 422, 'Groups of conditions can only be nested '.self::MAX_DEPTH.' deep.');
        $items = [];
        foreach (array_filter((array) ($group['items'] ?? []), 'is_array') as $item) {
            if (($item['type'] ?? null) === 'group') {
                $sub = self::checkGroup($item, $columnsOf, $having, $depth + 1);
                if ($sub['items']) {
                    $items[] = ['type' => 'group'] + $sub;
                }

                continue;
            }
            $items[] = ['type' => 'condition'] + self::checkCondition($item, $columnsOf, $having);
        }

        return self::group(($group['logic'] ?? 'and') === 'or' ? 'or' : 'and', $items);
    }

    private static function checkCondition(array $c, array $columnsOf, bool $having): array
    {
        $op = (string) ($c['op'] ?? '=');
        abort_unless(in_array($op, self::OPERATORS, true), 422, __('Unknown comparison ":op".', ['op' => $op]));
        $aggregate = $having ? ($c['aggregate'] ?? null) : null;
        abort_unless($aggregate === null || in_array($aggregate, array_diff(self::AGGREGATES, ['group']), true), 422, __('Unknown total ":aggregate".', ['aggregate' => $aggregate]));
        $exists = in_array($op, ['exists', 'not_exists'], true);
        $out = ['left' => $exists ? null : self::checkOperand($c['left'] ?? null, $columnsOf), 'aggregate' => $exists ? null : $aggregate, 'op' => $op, 'value_type' => 'value', 'value' => null, 'value2' => null, 'right' => null];
        if (in_array($op, ['null', 'not_null'], true)) {
            return $out;
        }

        $valueType = $exists ? 'subquery' : ($c['value_type'] ?? 'value');
        abort_unless(in_array($valueType, ['value', 'param', 'column', 'custom', 'subquery'], true), 422, __('Unknown kind of value ":valuetype".', ['valuetype' => $valueType]));
        $out['value_type'] = $valueType;
        if ($valueType === 'param') {
            $name = ltrim((string) ($c['value'] ?? ''), ':');
            abort_unless(preg_match(self::NAME, $name), 422, __('":name" can\'t be a parameter\'s name: use letters, digits and _.', ['name' => $name]));
            $out['value'] = $name;
        } elseif ($valueType === 'subquery') {
            $out['right'] = self::checkOperand($c['right'] ?? null, $columnsOf);
            abort_unless(isset($out['right']['subquery']) || isset($out['right']['inline']), 422, $exists ? 'Pick the stored query whose rows must exist.' : 'Pick the stored query to compare with.');
        } elseif ($valueType === 'column' || $valueType === 'custom') {
            $out['right'] = self::checkOperand($valueType === 'custom' ? ['custom' => $c['right']['custom'] ?? $c['value'] ?? ''] : ($c['right'] ?? null), $columnsOf);
        } else {
            $value = $c['value'] ?? '';
            $out['value'] = is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : (string) $value;
            if (in_array($op, ['between', 'not_between'], true)) {
                $out['value2'] = (string) ($c['value2'] ?? '');
            }
        }

        return $out;
    }

    /** A {table, column} operand that names a table on the canvas and one of its columns. */
    private static function checkColumn(mixed $operand, array $columnsOf): array
    {
        $table = is_array($operand) ? (string) ($operand['table'] ?? '') : '';
        $column = is_array($operand) ? (string) ($operand['column'] ?? '') : '';
        abort_unless(isset($columnsOf[$table]), 422, __('There\'s no table ":table" on the canvas.', ['table' => $table]));
        abort_unless(in_array($column, $columnsOf[$table], true), 422, __('":table" has no column ":column".', ['table' => $table, 'column' => $column]));

        return ['table' => $table, 'column' => $column];
    }

    /**
     * A column operand, a custom SQL expression (builders write SQL as they do in
     * query_string), or a stored query: {subquery: meta id, column?, bind: {name: value}}.
     */
    private static function checkOperand(mixed $operand, array $columnsOf, bool $allowSubquery = true): array
    {
        if (is_array($operand) && array_key_exists('inline', $operand)) {
            abort_unless($allowSubquery, 422, 'A sub-query can’t fill a stored query’s parameter.');
            $key = (string) $operand['inline'];
            abort_unless(isset(self::$inlineColumns[$key]), 422, __('There\'s no sub-query ":key" in this query.', ['key' => $key]));
            $column = is_string($operand['column'] ?? null) && $operand['column'] !== '' ? $operand['column'] : null;
            abort_unless($column === null || in_array($column, self::$inlineColumns[$key], true), 422, __('The sub-query ":key" has no column ":column".', ['key' => $key, 'column' => $column]));

            return ['inline' => $key, 'column' => $column];
        }
        if (is_array($operand) && array_key_exists('subquery', $operand)) {
            abort_unless($allowSubquery && self::$designing, 422, 'A stored query can’t fill another stored query’s parameter.');
            $sub = self::queryMeta((int) $operand['subquery'], self::$designing);
            $column = is_string($operand['column'] ?? null) && $operand['column'] !== '' ? $operand['column'] : null;
            abort_unless($column === null || in_array($column, self::queryColumns($sub), true), 422, __('":name" has no column ":column".', ['name' => $sub->name, 'column' => $column]));
            $bind = self::checkBind((array) ($operand['bind'] ?? []), $sub, $columnsOf);
            $correlated = collect($bind)->contains(fn ($b) => ! array_key_exists('value', $b) && ! array_key_exists('param', $b));
            abort_if($column !== null && $correlated && $sub->type?->code !== 'query', 422,
                "To fill \"{$sub->name}\" from each row, it must select just the one value (pick no column), or be a query meta.");
            // Raw SQL can't have its aliases renamed (a query meta's are), so they mustn't clash with this query's.
            if ($correlated && $sub->type?->code !== 'query') {
                foreach ($bind as $b) {
                    foreach (array_unique(array_column(self::operandColumns($b), 'table')) as $alias) {
                        abort_if(preg_match('/\b'.preg_quote($alias, '/').'\s*\./i', (string) $sub->query_string) === 1, 422,
                            "\"{$sub->name}\" uses the alias \"{$alias}\" too, so it would read its own {$alias} instead of this row's: give the table here another alias.");
                    }
                }
            }

            return ['subquery' => $sub->id, 'column' => $column, 'bind' => $bind];
        }
        if (is_array($operand) && array_key_exists('custom', $operand)) {
            $sql = trim((string) $operand['custom']);
            abort_if($sql === '', 422, 'An expression is empty.');
            abort_if(mb_strlen($sql) > 2000 || str_contains($sql, ';'), 422, 'An expression must be one SQL expression, without ;.');

            return ['custom' => $sql];
        }

        return self::checkColumn($operand, $columnsOf);
    }

    /**
     * What fills a stored query's :named parameters, by name: {value}, {param} (a parameter of
     * this query) or an operand of this query (a column makes it correlated; not in FROM,
     * where $columnsOf is null). A name left out stays a parameter of this query.
     */
    private static function checkBind(mixed $bind, Meta $sub, ?array $columnsOf): array
    {
        $names = self::namedParams((string) $sub->query_string);
        $out = [];
        foreach ((array) $bind as $name => $value) {
            if (! is_array($value) || $value === [] || (array_key_exists('param', $value) && trim((string) $value['param']) === '')) {
                continue;
            }
            abort_unless(in_array($name, $names, true), 422, __('":name" has no parameter ::name2.', ['name' => $sub->name, 'name2' => $name]));
            if (array_key_exists('value', $value)) {
                $out[$name] = ['value' => is_scalar($value['value']) ? (string) $value['value'] : ''];
            } elseif (array_key_exists('param', $value)) {
                $param = ltrim((string) $value['param'], ':');
                abort_unless(preg_match(self::NAME, $param), 422, __('":param" can\'t be a parameter\'s name: use letters, digits and _.', ['param' => $param]));
                $out[$name] = ['param' => $param];
            } else {
                abort_if($columnsOf === null, 422, __('In the tables, ::name of ":name2" takes a value or a parameter: a column needs it as a column or a condition.', ['name' => $name, 'name2' => $sub->name]));
                $out[$name] = self::checkOperand($value, $columnsOf, false);
            }
        }

        return $out;
    }

    /** An operand as SQL. */
    private static function operand(array $operand): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        if (isset($operand['inline'])) {
            return '('.self::inlineSql($operand['inline'], $operand['column'] ?? null).')';
        }
        if (isset($operand['subquery'])) {
            $bind = (array) ($operand['bind'] ?? []);
            $column = $operand['column'] ?? null;
            if (($own = self::recompiled((int) $operand['subquery'], $column)) !== null) {
                return '('.self::bindSql($own, $bind).')';
            }
            $sql = self::sourceSql((int) $operand['subquery'], $bind);

            // A derived table can't see the outer row in MySQL/MariaDB; checkOperand keeps those apart.
            return $column
                ? '(SELECT '.$grammar->wrap($column).' FROM ('.$sql.') AS '.$grammar->wrap('sq').')'
                : '('.$sql.')';
        }

        return isset($operand['custom']) ? $operand['custom'] : $grammar->wrap($operand['table'].'.'.$operand['column']);
    }

    /**
     * A query meta's SQL compiled afresh for use inside another query: its table aliases get
     * its id added (o → o_12), so the outer query's columns put in its parameters can't be
     * taken for its own tables; with a column, it selects just that one (the others kept for
     * grouping and order), so it needs no wrapping, which in MySQL couldn't see the outer
     * row. Null for metas with raw SQL, and for a column of a query with unions.
     */
    private static function recompiled(int $metaId, ?string $column): ?string
    {
        $meta = Meta::with('type')->find($metaId);
        if ($meta?->type?->code !== 'query') {
            return null;
        }
        $design = self::toArrays(self::load($meta));
        if ($column !== null && $design['unions']) {
            return null;
        }
        if ($column !== null) {
            $design = self::onlyColumn($design, $column);
        }

        return self::compile(self::renamed($design, '_'.$meta->id));
    }

    /** A design with every table alias given a suffix, wherever it is used. */
    private static function renamed(array $design, string $suffix): array
    {
        $map = [];
        foreach ([$design, ...array_column($design['subqueries'] ?? [], 'design')] as $level) {
            foreach ($level['tables'] as $t) {
                $map[$t['key']] = $t['key'].$suffix;
            }
        }
        $walk = function (array $node) use (&$walk, $map) {
            if (is_string($node['table'] ?? null) && isset($node['column']) && isset($map[$node['table']])) {
                $node['table'] = $map[$node['table']];
            }
            if (is_string($node['custom'] ?? null)) {
                $node['custom'] = preg_replace_callback('/\b([A-Za-z_]\w*)\s*\./', fn ($m) => isset($map[$m[1]]) ? $map[$m[1]].'.' : $m[0], $node['custom']);
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };
        $design = $walk($design);
        $rekey = fn (array $level) => ['tables' => array_map(fn ($t) => ['key' => $map[$t['key']]] + $t, $level['tables'])] + $level;
        $design = $rekey($design);
        foreach ($design['subqueries'] ?? [] as $i => $sub) {
            $design['subqueries'][$i]['design'] = $rekey($sub['design']);
        }

        return $design;
    }

    /** The {table, column} parts of an operand (a custom expression names none we know). */
    private static function operandColumns(array $operand): array
    {
        return isset($operand['table'], $operand['column']) ? [$operand] : [];
    }

    /** JSON objects read back from params as arrays all the way down. */
    private static function toArrays(mixed $value): mixed
    {
        return is_array($value) || is_object($value) ? array_map(self::toArrays(...), (array) $value) : $value;
    }

    /** Pulls out the ids of the stored queries a param's json_config uses as sub-queries. */
    private static function subqueryIds(array $config): array
    {
        $ids = isset($config['subquery']) && is_numeric($config['subquery']) ? [(int) $config['subquery']] : [];
        foreach ($config as $value) {
            if (is_array($value)) {
                array_push($ids, ...self::subqueryIds($value));
            }
        }

        return $ids;
    }

    private static function isSelect(Meta $meta): bool
    {
        $meta->loadMissing('type');

        return $meta->type?->code !== 'stage_query' && preg_match('/^\s*(\(\s*)*(select|with)\b/i', (string) $meta->query_string) === 1;
    }

    private static function aggregate(?string $aggregate, array $expr): string
    {
        $sql = self::operand($expr);

        return match ($aggregate) {
            null, 'group' => $sql,
            'count_distinct' => "COUNT(DISTINCT {$sql})",
            default => strtoupper($aggregate)."({$sql})",
        };
    }

    /** A group of conditions as SQL, or '' when it has none. */
    private static function condition(array $group, bool $having): string
    {
        $parts = [];
        foreach ($group['items'] as $item) {
            $sql = ($item['type'] ?? null) === 'group' ? self::condition($item, $having) : self::comparison($item);
            if ($sql !== '') {
                $parts[] = count($group['items']) > 1 ? "({$sql})" : $sql;
            }
        }

        return implode($group['logic'] === 'or' ? ' OR ' : ' AND ', $parts);
    }

    private static function comparison(array $c): string
    {
        $op = $c['op'];
        if ($op === 'exists' || $op === 'not_exists') {
            return ($op === 'exists' ? 'EXISTS ' : 'NOT EXISTS ').self::operand($c['right']);
        }
        $left = self::aggregate($c['aggregate'] ?? null, $c['left']);
        if ($op === 'null' || $op === 'not_null') {
            return $left.($op === 'null' ? ' IS NULL' : ' IS NOT NULL');
        }

        $pdo = DB::connection()->getPdo();
        $literal = fn (string $v) => is_numeric($v) && ! preg_match('/^0\d/', $v) ? $v : $pdo->quote($v);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $concat = fn (string ...$parts) => $sqlite ? implode(' || ', $parts) : 'CONCAT('.implode(', ', $parts).')';

        if ($c['value_type'] === 'param') {
            $p = ':'.$c['value'];
            $right = match ($op) {
                'contains' => $concat("'%'", $p, "'%'"),
                'starts' => $concat($p, "'%'"),
                'ends' => $concat("'%'", $p),
                default => $p,
            };
            $test = match ($op) {
                'between', 'not_between', 'in', 'not_in' => $left.' '.($op === 'not_in' || $op === 'not_between' ? '<>' : '=')." {$p}",
                default => $left.' '.self::sqlOperator($op).' '.$right,
            };

            // An empty parameter leaves its condition out, so a filter left empty shows everything.
            return "({$p} IS NULL OR {$test})";
        }

        if (in_array($c['value_type'], ['column', 'custom', 'subquery'], true)) {
            $right = self::operand($c['right']);
            if ($op === 'in' || $op === 'not_in') {
                return $left.($op === 'in' ? ' IN ' : ' NOT IN ').(str_starts_with($right, '(') ? $right : "({$right})");
            }

            return $left.' '.self::sqlOperator($op).' '.$right;
        }

        $value = $c['value'];
        if (in_array($op, ['in', 'not_in'], true)) {
            $list = is_array($value) ? $value : array_map('trim', explode(',', (string) $value));
            $list = array_values(array_filter($list, fn ($v) => $v !== ''));

            return $list ? $left.($op === 'in' ? ' IN ' : ' NOT IN ').'('.implode(', ', array_map($literal, $list)).')' : ($op === 'in' ? '1 = 0' : '1 = 1');
        }
        $value = is_array($value) ? implode(',', $value) : (string) $value;
        if (in_array($op, ['between', 'not_between'], true)) {
            return $left.($op === 'between' ? ' BETWEEN ' : ' NOT BETWEEN ').$literal($value).' AND '.$literal((string) $c['value2']);
        }

        return $left.' '.self::sqlOperator($op).' '.match ($op) {
            'contains' => $pdo->quote('%'.$value.'%'),
            'starts' => $pdo->quote($value.'%'),
            'ends' => $pdo->quote('%'.$value),
            'like', 'not_like' => $pdo->quote($value),
            default => $literal($value),
        };
    }

    private static function sqlOperator(string $op): string
    {
        return match ($op) {
            'like', 'contains', 'starts', 'ends' => 'LIKE',
            'not_like' => 'NOT LIKE',
            default => $op,
        };
    }

    /**
     * A stored query this one may read: a meta with a SELECT in its query_string (see
     * sources), not itself, nor one that reads it back.
     */
    private static function queryMeta(int $id, Meta $self): Meta
    {
        $query = Meta::with('type')->find($id);
        abort_unless($query, 422, 'That stored query no longer exists.');
        abort_if($query->type?->code === 'query' && trim((string) $query->query_string) === '', 422, __('":name" has no SQL yet: save its design first.', ['name' => $query->name]));
        abort_unless(self::isSelect($query), 422, __('":name" has no stored SELECT to read from.', ['name' => $query->name]));
        abort_if($query->id === $self->id || self::reads($query, $self->id, 0), 422, __('":name" reads this query, so this one can\'t read it back.', ['name' => $query->name]));

        return $query;
    }

    /** Whether a query reads another (by tables, unions and sub-queries, at any depth). */
    private static function reads(Meta $query, int $target, int $depth): bool
    {
        if ($depth > 8) {
            return true;
        }
        $params = MetaParam::where('main_meta_id', $query->id)->get();
        $refs = $params->whereIn('data_type', ['table', 'union'])->pluck('ref_meta_id')->filter()->all();
        foreach ($params as $p) {
            array_push($refs, ...self::subqueryIds($p->json_config ?? []));
        }
        foreach (array_unique($refs) as $ref) {
            if ((int) $ref === $target || (($m = Meta::find($ref)) && self::reads($m, $target, $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /** A stored query's SQL, with what fills its :named parameters put in (see checkBind). */
    private static function sourceSql(int $metaId, array $bind = []): string
    {
        return self::bindSql(rtrim(trim((string) Meta::whereKey($metaId)->value('query_string')), ';'), $bind);
    }

    /** SQL with what fills its :named parameters put in. */
    private static function bindSql(string $sql, array $bind): string
    {
        if (! $bind) {
            return $sql;
        }
        $pdo = DB::connection()->getPdo();
        $replacements = [];
        foreach ($bind as $name => $b) {
            $replacements[$name] = match (true) {
                array_key_exists('value', $b) => $b['value'] === '' ? 'NULL' : (is_numeric($b['value']) && ! preg_match('/^0\d/', $b['value']) ? $b['value'] : $pdo->quote($b['value'])),
                array_key_exists('param', $b) => ':'.$b['param'],
                default => '('.self::operand($b).')',
            };
        }

        return NamedSql::substitute($sql, $replacements);
    }

    /** The param data_type a database column type reads as. */
    private static function dataType(string $typeName, string $type): string
    {
        $t = strtolower($typeName);

        return match (true) {
            $t === 'tinyint' && str_contains(strtolower($type), 'tinyint(1)'), in_array($t, ['bool', 'boolean'], true) => 'boolean',
            // PDO's native_type names too (LONGLONG, NEWDECIMAL, BLOB…), for stored queries' columns.
            str_contains($t, 'int'), in_array($t, ['long', 'longlong', 'short', 'tiny', 'integer'], true) => 'integer',
            in_array($t, ['decimal', 'newdecimal', 'numeric', 'float', 'double', 'real', 'money'], true) => 'decimal',
            $t === 'date' => 'date',
            in_array($t, ['datetime', 'timestamp', 'timestamptz'], true) => 'datetime',
            in_array($t, ['text', 'mediumtext', 'longtext', 'json', 'blob'], true) => 'text',
            default => 'string',
        };
    }
}
