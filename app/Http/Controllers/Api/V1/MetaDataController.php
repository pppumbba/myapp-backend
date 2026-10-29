<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use App\Models\WfmStatus;
use App\Support\Access;
use App\Support\Autonumber;
use App\Support\Contents;
use App\Support\Criteria;
use App\Support\Documents;
use App\Support\ExternalService;
use App\Support\FilterLookups;
use App\Support\GridReuse;
use App\Support\NamedSql;
use App\Support\Notifier;
use App\Support\ParamRules;
use App\Support\ProcessFlow;
use App\Support\QueryDesign;
use App\Support\RecordLog;
use App\Support\Xlsx;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Reads and writes the records behind a datagrid, form, calendar or gantt meta, and reads
 * them for statements (grouped lists with totals) and reports (one record, printed).
 *
 * The meta's table_name says which table, and its params say which columns. Only columns
 * that a param names and that really exist are ever selected or written, and system tables
 * are refused, so a meta can't be pointed at users or the metas themselves. A meta with a
 * query_string reads from that query instead, as written: whoever edits metas is trusted
 * with SQL. Get metas (fill) decide the values a form opens with. A meta with a service_url
 * reads and writes an external REST service instead (see ExternalService).
 */
class MetaDataController extends Controller
{
    public const BLOCKED_TABLES = [
        'users', 'password_reset_tokens', 'sessions', 'personal_access_tokens',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'metas', 'meta_params', 'meta_types', 'meta_actions',
        'roles', 'user_roles', 'meta_permissions', 'wfm_status_permissions', 'user_notifications',
        'wfm_status', 'wfm_transition', 'wfm_status_log', 'widgets', 'schedules', 'meta_schedules', 'autonumbers',
        'meta_process_workflow', 'meta_process_transition', 'meta_process_behaviour', 'meta_versions',
        'meta_meta_map', 'meta_notification_map',
    ];

    /** Meta types that read records. A report prints one record, with its details' lines. */
    private const DATA_TYPES = ['datagrid', 'form', 'calendar', 'gantt', 'statement', 'report'];

    /** A statement shows every row up to this many; past it, it says to narrow the filters. */
    public const STATEMENT_ROWS = 5000;

    /** An Excel export holds up to this many rows; past it, it says to narrow the filters. */
    public const EXPORT_ROWS = 50000;

    /**
     * GET /metas/{meta}/data?search=&filter[field]=&sort=&dir=&page=&per_page=&from=&to=
     *
     * A meta with a query_string reads from that query; its :named parameters take their
     * values from filter[name] (and the built-ins :user_id and :today), always bound, never
     * pasted into the SQL. Other filter[field] values filter the params marked is_criteria:
     * text contains, dates on that day, everything else equal. Until every
     * is_mandatory_criteria filter has a value, no rows come back and missing_filters says
     * which are needed. search matches the text criteria; from/to (dates) keep the records
     * whose json_config start_field..end_field span overlaps [from, to).
     */
    public function index(Request $request, Meta $meta): JsonResponse
    {
        Access::requireRecords($request->user(), $meta);
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        if (ExternalService::uses($meta)) {
            $sort = $request->query('sort');

            return response()->json(ExternalService::list(
                $meta, $request->user(), $this->filters($request, 'filter') + $this->filters($request, 'scope'),
                trim((string) $request->query('search', '')), is_string($sort) ? $sort : null,
                $request->query('dir') === 'desc' ? 'desc' : 'asc', max(1, (int) $request->query('page', 1)), $perPage,
            ));
        }
        $source = $this->source($meta);

        [$query, $missing] = $this->filtered($request, $meta, $source);
        if ($missing->isNotEmpty()) {
            return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'footer' => (object) [], 'missing_filters' => $missing]);
        }
        // A tree (a param with is_parent): ?parent= lists one level, the top one when empty.
        $parent = $this->parentField($source);
        $tree = $parent !== null && $request->has('parent') ? clone $query : null;
        if ($tree) {
            $under = (string) $request->query('parent', '');
            $under === '' ? $query->where(fn ($q) => $q->whereNull($parent)->orWhere($parent, '')) : $query->where($parent, $under);
        }
        // A grid's footer: its columns' json_config.aggregate over every matching row, not just this page.
        $footer = $this->totals($tree ?? $query, $source);

        $sort = $request->query('sort');
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        if (is_string($sort) && in_array($sort, $source['columns'], true)) {
            $query->orderBy($sort, $dir);
        } else {
            $this->defaultOrder($query, $meta, $source);
        }

        $page = $this->read(fn () => $query->paginate($perPage))->toArray();
        $page['data'] = $this->withFiles($meta, $page['data'], $source['key']);
        if ($tree) {
            $page['data'] = $this->withChildCounts($page['data'], $tree, $parent, $source['key']);
        }

        return response()->json($page + ['footer' => $footer]);
    }

    /** The field holding a record's parent record (is_parent), when the grid can read it. */
    private function parentField(array $source): ?string
    {
        $field = $source['params']->firstWhere('is_parent', true)?->field_path;

        return $field && in_array($field, $source['columns'], true) ? $field : null;
    }

    /** Each row with _children: how many records under the same filters have it as their parent. */
    private function withChildCounts(array $rows, Builder $all, string $parent, string $key): array
    {
        $ids = array_values(array_filter(array_map(fn ($r) => data_get($r, $key), $rows), fn ($id) => $id !== null));
        $counts = $ids ? $this->read(fn () => (clone $all)->reorder()->select($parent)->selectRaw('count(*) as n')
            ->whereIn($parent, $ids)->groupBy($parent)->pluck('n', $parent)) : collect();

        return array_map(function ($row) use ($counts, $key) {
            $n = (int) ($counts[data_get($row, $key)] ?? 0);
            is_array($row) ? $row['_children'] = $n : $row->_children = $n;

            return $row;
        }, $rows);
    }

    /** Rows with the files of the meta's singlefile, multifile and document fields (see Contents). */
    private function withFiles(Meta $meta, array $rows, string $key = 'id'): array
    {
        // A grid reusing another shows the files of its origin's file fields too.
        $origin = GridReuse::origin($meta);
        $params = Contents::params($meta, true)->merge($origin === $meta ? [] : Contents::params($origin, true))->unique('field_path')->values();

        return Contents::attach($origin->table_name, $params, $rows, $key);
    }

    /** A facet lists up to this many values, the most common first. */
    public const FACET_VALUES = 50;

    /**
     * GET /metas/{meta}/facets?fields[]=status&search=&filter[field]=&scope[field]=
     *
     * For a grid's sidebar filters: how many records have each value of these filter fields,
     * among those the other filters, the search and the scope leave. A field's own filter is
     * left out of its counts, so picking one value still shows how many the others have.
     *
     * @return JsonResponse {field: [{value, count}]}
     */
    public function facets(Request $request, Meta $meta): JsonResponse
    {
        Access::requireRecords($request->user(), $meta);
        $source = $this->source($meta);
        $fields = array_values(array_filter((array) $request->query('fields', []), 'is_string'));
        $criteria = $source['params']->where('is_criteria', true)->pluck('field_path')->all();

        $facets = [];
        foreach (array_slice($fields, 0, 20) as $field) {
            abort_unless(in_array($field, $criteria, true) && in_array($field, $source['columns'], true), 422, __('":field" is not a filter of this grid.', ['field' => $field]));
            $others = array_diff_key((array) $request->query('filter', []), [$field => true]);
            [$query, $missing] = $this->filtered($request->duplicate(['filter' => $others] + $request->query()), $meta, $source);
            if ($missing->isNotEmpty()) {
                $facets[$field] = [];

                continue;
            }
            $column = $query->getGrammar()->wrap($field);
            $facets[$field] = $this->read(fn () => DB::query()->fromSub($query, 'f')
                ->selectRaw("{$column} AS value, COUNT(*) AS count")
                ->groupBy($field)->orderByDesc('count')->limit(self::FACET_VALUES)->get()
                ->map(fn (object $f) => ['value' => $f->value, 'count' => (int) $f->count])
                ->values());
        }

        return response()->json((object) $facets);
    }

    /**
     * GET /metas/{meta}/statement?search=&filter[field]=
     *
     * A statement meta's rows, filtered like a grid's (see index), all of them up to
     * STATEMENT_ROWS: ordered by its json_config.group_by columns (outer group first), then
     * by json_config.sort ([{field, dir}]). totals covers every matching row, for the params
     * whose json_config.aggregate is count, sum, avg, min or max; the page adds up each
     * group's subtotals from the rows.
     */
    public function statement(Request $request, Meta $meta): JsonResponse
    {
        Access::requireRecords($request->user(), $meta);
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'statement', 422, 'Only statement metas have statements.');
        $source = $this->source($meta);

        [$query, $missing] = $this->filtered($request, $meta, $source);
        if ($missing->isNotEmpty()) {
            return response()->json(['rows' => [], 'totals' => (object) [], 'group_by' => [], 'truncated' => false, 'missing_filters' => $missing]);
        }

        $config = $meta->json_config ?? [];
        $groups = array_values(array_filter((array) ($config['group_by'] ?? []), fn ($c) => is_string($c) && in_array($c, $source['columns'], true)));

        $totals = $this->totals($query, $source);

        $this->statementOrder($query, $groups, $config, $source);
        $rows = $this->read(fn () => $query->limit(self::STATEMENT_ROWS + 1)->get());

        return response()->json([
            'rows' => $rows->take(self::STATEMENT_ROWS)->values(),
            'totals' => $totals,
            'group_by' => $groups,
            'truncated' => $rows->count() > self::STATEMENT_ROWS,
            'missing_filters' => [],
        ]);
    }

    /**
     * Each param's json_config.aggregate (count, sum, avg, min or max) over every row the
     * query matches, by field_path: a statement's grand totals, a grid's footer.
     */
    private function totals(Builder $query, array $source): object
    {
        $grammar = $query->getGrammar();
        $aggregates = $source['params']
            ->filter(fn (MetaParam $p) => in_array($p->json_config['aggregate'] ?? null, ['count', 'sum', 'avg', 'min', 'max'], true) && in_array($p->field_path, $source['columns'], true))
            ->map(fn (MetaParam $p) => strtoupper($p->json_config['aggregate']).'('.$grammar->wrap($p->field_path).') AS '.$grammar->wrap($p->field_path))
            ->values();

        return $aggregates->isEmpty() ? (object) [] : $this->read(fn () => DB::query()->fromSub(clone $query, 's')->selectRaw($aggregates->implode(', '))->first());
    }

    /**
     * The order rows take when none is picked: a query meta's own sort (QueryDesign), which a
     * sub-query would lose; else the newest first, unless a query's rows have no key column.
     */
    private function defaultOrder(Builder $query, Meta $meta, array $source): void
    {
        $sorts = QueryDesign::defaultOrder(GridReuse::origin($meta), $source['columns']);
        foreach ($sorts as [$column, $dir]) {
            $query->orderBy($column, $dir);
        }
        if (! $sorts && (! $source['query'] || $source['params']->contains('field_path', $source['key']))) {
            // A query's rows keep their own order unless the key is one of its columns.
            $query->orderByDesc($source['key']);
        }
    }

    /** A statement's order: its groups (outer first), then json_config.sort, then the key. */
    private function statementOrder(Builder $query, array $groups, array $config, array $source): void
    {
        foreach ($groups as $column) {
            $query->orderBy($column);
        }
        foreach ((array) ($config['sort'] ?? []) as $s) {
            if (is_array($s) && is_string($s['field'] ?? null) && in_array($s['field'], $source['columns'], true)) {
                $query->orderBy($s['field'], ($s['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
            }
        }
        if (! $source['query'] || $source['params']->contains('field_path', $source['key'])) {
            $query->orderBy($source['key']);
        }
    }

    /**
     * GET /metas/{meta}/export?search=&filter[field]=&sort=&dir=
     *
     * The records a grid, statement, calendar or gantt shows, filtered the same way (see
     * index), as an .xlsx file: one column per visible param, headed by its label, in the
     * params' order. Lookups show their record's display_field and workflow statuses their
     * name, so the file reads like the screen; a statement keeps its group order. Up to
     * EXPORT_ROWS rows.
     */
    public function export(Request $request, Meta $meta): Response
    {
        Access::requireRecords($request->user(), $meta);
        $source = $this->source($meta);
        abort_if($meta->type?->code === 'report', 422, 'A report prints one record; print it instead.');

        [$query, $missing] = $this->filtered($request, $meta, $source);
        abort_if($missing->isNotEmpty(), 422, __('Fill in :fields before exporting.', ['fields' => $missing->implode(', ')]));

        if ($meta->type?->code === 'statement') {
            $config = $meta->json_config ?? [];
            $groups = array_values(array_filter((array) ($config['group_by'] ?? []), fn ($c) => is_string($c) && in_array($c, $source['columns'], true)));
            $this->statementOrder($query, $groups, $config, $source);
        } else {
            $sort = $request->query('sort');
            if (is_string($sort) && in_array($sort, $source['columns'], true)) {
                $query->orderBy($sort, $request->query('dir') === 'desc' ? 'desc' : 'asc');
            } else {
                $this->defaultOrder($query, $meta, $source);
            }
        }

        $rows = $this->read(fn () => $query->limit(self::EXPORT_ROWS + 1)->get());
        abort_if($rows->count() > self::EXPORT_ROWS, 422, 'More than '.number_format(self::EXPORT_ROWS).' records match. Narrow the filters and export again.');

        $columns = $source['params']
            ->filter(fn (MetaParam $p) => $p->is_show && ! $p->is_primary && ! in_array($p->data_type, ['detail', 'subgrid', 'signature'], true) && in_array($p->field_path, $source['columns'], true))
            ->values();

        // A workflow's statuses read as their names; a grid that doesn't list status_id gets it last.
        $workflowId = GridReuse::origin($meta)->ref_meta_id;
        $statuses = $workflowId ? WfmStatus::where('workflow_meta_id', $workflowId)->pluck('name', 'id') : collect();
        $withStatus = $statuses->isNotEmpty() && in_array('status_id', $source['columns'], true) && ! $columns->contains('field_path', 'status_id');

        $names = $columns->mapWithKeys(fn (MetaParam $p) => [$p->field_path => $this->lookupNames($p, $rows)])->filter();
        $type = fn (MetaParam $p) => match (true) {
            $names->has($p->field_path), is_array($p->json_config['options'] ?? null) => 'string',
            $p->field_path === 'status_id' && $statuses->isNotEmpty() => 'string',
            $p->data_type === 'decimal' && $p->render_type === 'money' => 'money',
            in_array($p->data_type, ['integer', 'decimal', 'boolean', 'date', 'datetime'], true) => $p->data_type,
            default => 'string',
        };

        $headers = $columns->map(fn (MetaParam $p) => $p->label_name ?: $p->param_name)->all();
        $types = $columns->map($type)->all();
        if ($withStatus) {
            $headers[] = 'Status';
            $types[] = 'string';
        }

        $lines = $rows->map(function (object $row) use ($columns, $names, $statuses, $withStatus) {
            $line = $columns->map(function (MetaParam $p) use ($row, $names, $statuses) {
                $value = $row->{$p->field_path} ?? null;
                if ($value === null) {
                    return null;
                }
                if ($p->field_path === 'status_id' && $statuses->isNotEmpty()) {
                    return $statuses[$value] ?? $value;
                }

                return $names->has($p->field_path) ? ($names[$p->field_path][$value] ?? $value) : $value;
            })->all();
            if ($withStatus) {
                $line[] = $row->status_id === null ? null : ($statuses[$row->status_id] ?? $row->status_id);
            }

            return $line;
        });

        $bytes = Xlsx::write($meta->label_name ?: $meta->name, $headers, $lines, $types);
        $file = Str::slug($meta->label_name ?: $meta->name).'-'.now()->format('Y-m-d').'.xlsx';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /**
     * For a lookup param: the label of each looked-up record in these rows (value => label),
     * from its display_field (see MetaParam::displayField). Empty when unknown.
     *
     * @return array<int|string, string>
     */
    public static function lookupNames(MetaParam $param, Collection $rows): array
    {
        if (! $param->is_select || ! $param->ref_meta_id || ! ($table = ParamRules::lookupTable($param))) {
            return [];
        }
        $label = $param->displayField();
        $value = $param->valueField();
        if (! Schema::hasColumn($table, $label) || ! Schema::hasColumn($table, $value)) {
            return [];
        }
        $names = [];
        foreach ($rows->pluck($param->field_path)->filter(fn ($v) => $v !== null)->unique()->chunk(1000) as $values) {
            $names += DB::table($table)->whereIn($value, $values->values()->all())->pluck($label, $value)->all();
        }

        return $names;
    }

    /**
     * The meta's records with its filters applied (see index), unordered, or the labels of
     * required filters still empty.
     *
     * @return array{0: ?Builder, 1: Collection<int, string>}
     */
    private function filtered(Request $request, Meta $meta, array $source): array
    {
        $filters = $this->filters($request, 'filter');
        // scope[field]: values that always hold, e.g. a workspace's record (filter[] only reaches criteria).
        $scope = $this->filters($request, 'scope');

        $missing = $source['params']->where('is_mandatory_criteria', true)
            ->filter(fn (MetaParam $p) => ($scope[$p->field_path] ?? $filters[$p->field_path] ?? '') === '')
            ->map(fn (MetaParam $p) => $p->label_name ?: $p->param_name)->values();
        if ($missing->isNotEmpty()) {
            return [null, $missing];
        }

        $tokens = [];
        if ($source['query']) {
            [$sql, $bindings, $tokens] = NamedSql::bind($source['query'], $scope + $filters + NamedSql::builtins($request->user()));
            $query = DB::query()->fromRaw("({$sql}) as q", $bindings)->select('q.*');
        } else {
            $query = DB::table($source['table'])->select($source['columns']);
        }
        $this->withoutHidden($query, $source, $request);

        // A query's :name took its scope value already; any other scope must be a column, never ignored.
        foreach ($scope as $field => $value) {
            if (in_array($field, $tokens, true)) {
                continue;
            }
            abort_unless(in_array($field, $source['columns'], true), 422, __('":name" has no column ":field" to keep it to one record.', ['name' => $meta->name, 'field' => $field]));
            $query->where($field, $value);
        }

        $criteria = $source['params']->where('is_criteria', true)
            ->filter(fn (MetaParam $p) => in_array($p->field_path, $source['columns'], true) && ! in_array($p->field_path, $tokens, true));

        $search = trim((string) $request->query('search', ''));
        $texts = $criteria->filter(fn (MetaParam $p) => $this->isText($p))->pluck('field_path');
        if ($search !== '' && $texts->isNotEmpty()) {
            $query->where(function ($q) use ($texts, $search) {
                foreach ($texts as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        // A filter that picks with its form's lookup or list holds an exact value, not text to look for.
        $borrowed = FilterLookups::borrowed($meta, $criteria);
        foreach ($criteria as $param) {
            $value = $filters[$param->field_path] ?? '';
            if ($value === '') {
                continue;
            }
            $column = $param->field_path;
            match (true) {
                in_array($param->data_type, ['date', 'datetime'], true) => $query->whereDate($column, $value),
                $param->data_type === 'boolean' => $query->where($column, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                $this->isText($param) && ! isset($borrowed[$column]) => $query->where($column, 'like', '%'.$value.'%'),
                default => $query->where($column, $value),
            };
        }

        $this->inRange($query, $meta, $source['columns'], $request->query('from'), $request->query('to'));

        return [$query, collect()];
    }

    /**
     * GET /metas/{meta}/fill?params[name]=value
     * Runs a get meta's query with these named parameters (plus :user_id and :today) and
     * returns its first row: the values a form opens with. No row gives {}.
     */
    public function fill(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'get', 422, 'Only get metas fill forms.');
        $sql = trim((string) $meta->query_string);
        abort_if($sql === '', 422, 'This get meta has no query yet.');

        [$sql, $bindings] = NamedSql::bind($sql, $this->filters($request, 'params') + NamedSql::builtins($request->user()));
        $row = $this->read(fn () => DB::selectOne($sql, $bindings));

        return response()->json($row ?? (object) []);
    }

    /**
     * GET /metas/{meta}/autonumber?values[field]=value
     * The numbers a new record of this form would get now, by field, without taking them.
     * values are the form's current values, which pick the format (e.g. book_type_id).
     */
    public function autonumber(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'create');
        $source = $this->source($meta, true);

        return response()->json((object) Autonumber::preview($source['table'], $source['params'], $this->filters($request, 'values')));
    }

    /** Text the user types (not a lookup or a list of options), matched with "contains". */
    private function isText(MetaParam $param): bool
    {
        return in_array($param->data_type, ['string', 'text'], true) && ! $param->is_select && ! is_array($param->json_config['options'] ?? null);
    }

    /** Scalar values of an array query parameter like filter[...] or params[...]. */
    private function filters(Request $request, string $name): array
    {
        $values = $request->query($name, []);
        if (! is_array($values)) {
            return [];
        }

        return array_map(fn ($v) => trim((string) $v), array_filter($values, fn ($v) => is_scalar($v)));
    }

    /** GET /metas/{meta}/data/{id} */
    public function show(Request $request, Meta $meta, string $id): JsonResponse
    {
        Access::requireRecords($request->user(), $meta);
        if (ExternalService::uses($meta)) {
            return response()->json(ExternalService::find($meta, $request->user(), $id));
        }
        $id = (int) $id;
        $source = $this->source($meta, true);
        $row = $this->withoutHidden(DB::table($source['table'])->select($source['columns'])->where($source['key'], $id), $source, $request)->first();

        abort_if(! $row, 404, 'Record not found.');

        return response()->json($this->withFiles($meta, [$this->withDetails($row, $this->details($meta), $id)], $source['key'])[0]);
    }

    /**
     * One record with its lines, as this user may see it through this meta (GET
     * /metas/{meta}/data/{id}), as plain arrays. Documents print from it.
     */
    public static function recordAs(?User $user, Meta $meta, string|int $id): array
    {
        $request = Request::create("/api/metas/{$meta->id}/data/{$id}", 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user);

        return app(self::class)->show($request, $meta, (string) $id)->getData(true);
    }

    /** POST /metas/{meta}/data, with each detail's lines under its field_path. */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'create');
        $this->requireWritable($meta);
        if (ExternalService::uses($meta)) {
            return response()->json(ExternalService::create($meta, $request->user(), $request->all()), 201);
        }
        $source = $this->source($meta, true);
        $all = $this->details($meta);
        $details = $this->applicable($all, $request);
        [$data, $lines] = $this->validated($request, $source, $details);
        $files = Contents::validated($meta, $source['table'], $request->all(), $request->user(), null);
        $data += $this->timestamps($source['table'], true);

        // The forms of its process flow save in the same transaction: one refusing undoes all.
        [$id, $ran] = RecordLog::saving(fn () => $this->write(fn () => DB::transaction(function () use ($source, $details, $all, $data, $lines, $files, $meta, $request) {
            $data = Autonumber::fill($source['table'], $source['params'], $data);
            $id = DB::table($source['table'])->insertGetId($data);
            $this->saveLines($source, $details, $lines, $id);
            Contents::sync($meta, $source['table'], (int) $id, $files);
            if (RecordLog::enabled($meta)) {
                RecordLog::write($meta, $source['table'], (int) $id, 'created', null, $this->tree($source, $all, (int) $id), $all, $request->user());
            }
            $record = DB::table($source['table'])->where($source['key'], $id)->first();

            return [$id, ProcessFlow::run($meta, $record, $request->user())];
        })));
        DB::afterCommit(fn () => Notifier::recordChanged($source['table'], 'created', (int) $id, $request->user()));
        Documents::keepFor($meta, $source['table'], (int) $id, $request->user());

        $row = $this->withFiles($meta, [$this->withDetails($this->find($source, $id), $all, $id)], $source['key'])[0];
        if ($ran) {
            $row->_processes = $ran;
        }

        return response()->json($row, 201);
    }

    /**
     * Saves a new record through a form as this user, the way POST /metas/{meta}/data does:
     * the same permissions, validation, numbering, lines, process flow and notifications.
     * Scheduled forms and process flows save this way.
     */
    public static function storeAs(?User $user, Meta $meta, array $values): object
    {
        $request = Request::create("/api/metas/{$meta->id}/data", 'POST', $values, server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user);

        return app(self::class)->store($request, $meta)->getData();
    }

    /**
     * Every row a grid shows this user for these query values (filter[], scope[]), the way
     * GET /metas/{meta}/data pages them, up to $max rows. Stops with 422 when the grid
     * still needs filters or has more rows than $max.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsAs(?User $user, Meta $meta, array $query = [], int $max = 5000): array
    {
        $rows = [];
        $page = 1;
        do {
            $request = Request::create("/api/metas/{$meta->id}/data", 'GET', $query + ['per_page' => 100, 'page' => $page], server: ['HTTP_ACCEPT' => 'application/json']);
            $request->setUserResolver(fn () => $user);
            $data = app(self::class)->index($request, $meta)->getData(true);
            if (! empty($data['missing_filters'])) {
                abort(422, __('":name" needs these filters filled in: :filters.', ['name' => $meta->name, 'filters' => implode(', ', $data['missing_filters'])]));
            }
            array_push($rows, ...$data['data']);
            abort_if(count($rows) > $max, 422, __('":name" has more than :max rows.', ['name' => $meta->name, 'max' => number_format($max)]));
        } while ($page++ < ($data['last_page'] ?? 1));

        return $rows;
    }

    /** What a refused save says, in one line: its validation messages, or why it stopped. */
    public static function failure(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => collect($e->errors())->flatten()->implode(' '),
            $e instanceof HttpResponseException => collect($e->getResponse()->getData(true)['errors'] ?? [])->flatten()->implode(' ') ?: 'The form refused this record.',
            $e instanceof HttpExceptionInterface => $e->getMessage(),
            default => 'Something went wrong: '.$e->getMessage(),
        };
    }

    /** PUT/PATCH /metas/{meta}/data/{id}. A detail sent replaces all of that record's lines. */
    public function update(Request $request, Meta $meta, string $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'edit');
        $this->requireWritable($meta);
        if (ExternalService::uses($meta)) {
            return response()->json(ExternalService::update($meta, $request->user(), $id, $request->all()));
        }
        $id = (int) $id;
        $source = $this->source($meta, true);
        abort_unless($this->withoutHidden(DB::table($source['table'])->where($source['key'], $id), $source, $request)->exists(), 404, 'Record not found.');

        $before = DB::table($source['table'])->where($source['key'], $id)->first();
        $all = $this->details($meta);
        $details = $this->applicable($all, $request, $before);
        [$data, $lines] = $this->validated($request, $source, $details, $id);
        $files = Contents::validated($meta, $source['table'], $request->all(), $request->user(), $id);
        $data += $this->timestamps($source['table'], false);

        $logged = RecordLog::enabled($meta) ? $this->tree($source, $all, $id) : null;
        RecordLog::saving(fn () => $this->write(fn () => DB::transaction(function () use ($source, $details, $all, $data, $lines, $files, $id, $meta, $logged, $request) {
            DB::table($source['table'])->where($source['key'], $id)->update($data);
            $this->saveLines($source, $details, $lines, $id);
            Contents::sync($meta, $source['table'], $id, $files);
            if ($logged !== null) {
                RecordLog::write($meta, $source['table'], $id, 'updated', $logged, $this->tree($source, $all, $id), $all, $request->user());
            }
        })));
        Notifier::recordChanged($source['table'], 'updated', $id, $request->user(), $before);
        Documents::keepFor($meta, $source['table'], $id, $request->user());

        return response()->json($this->withFiles($meta, [$this->withDetails($this->find($source, $id), $all, $id)], $source['key'])[0]);
    }

    /** DELETE /metas/{meta}/data/{id}. Its detail lines go with it. */
    public function destroy(Request $request, Meta $meta, string $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'delete');
        $this->requireWritable($meta);
        if (ExternalService::uses($meta)) {
            ExternalService::delete($meta, $request->user(), $id);

            return response()->json(null, 204);
        }
        $id = (int) $id;
        $source = $this->source($meta, true);
        abort_unless($this->withoutHidden(DB::table($source['table'])->where($source['key'], $id), $source, $request)->exists(), 404, 'Record not found.');
        $details = $this->details($meta);
        $before = DB::table($source['table'])->where($source['key'], $id)->first();
        $logged = RecordLog::enabled($meta) ? $this->tree($source, $details, $id) : null;
        $deleted = RecordLog::saving(fn () => $this->write(fn () => DB::transaction(function () use ($source, $details, $id, $meta, $logged, $request) {
            foreach ($details as $detail) {
                $this->deleteLines($detail, DB::table($detail['source']['table'])->where($detail['foreign_key'], $id)->pluck($detail['source']['key'])->all());
            }
            $deleted = DB::table($source['table'])->where($source['key'], $id)->delete();
            if ($deleted) {
                Contents::forget($source['table'], $id);
            }
            if ($deleted && $logged !== null) {
                RecordLog::write($meta, $source['table'], $id, 'deleted', $logged, null, $details, $request->user());
            }

            return $deleted;
        })));

        abort_if(! $deleted, 404, 'Record not found.');
        Notifier::recordChanged($source['table'], 'deleted', $id, $request->user(), $before);

        return response()->json(null, 204);
    }

    /**
     * Leaves out records sitting in a workflow status the user may not see. Applies when the
     * table (or, for a query, its columns) has a status_id; records without a status stay.
     */
    private function withoutHidden($query, array $source, Request $request): mixed
    {
        $hasStatus = $source['table'] ? Schema::hasColumn($source['table'], 'status_id') : in_array('status_id', $source['columns'], true);
        $hidden = $hasStatus ? Access::hiddenStatuses($request->user()) : [];
        if ($hidden) {
            $query->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $hidden));
        }

        return $query;
    }

    /** A record with all its lines, as plain arrays, for the change log. */
    private function tree(array $source, array $details, int $id): array
    {
        return json_decode(json_encode($this->withDetails($this->find($source, $id), $details, $id)), true);
    }

    private function find(array $source, int $id): object
    {
        return DB::table($source['table'])->select($source['columns'])->where($source['key'], $id)->first();
    }

    /** How many levels of lines a form may have: lines, their lines, and theirs. */
    public const DETAIL_DEPTH = 3;

    /**
     * A form's detail params: data_type "detail", ref_meta_id = the form describing one line,
     * json_config.foreign_key = the line column pointing back at this record. Optionally
     * json_config.amount = [column, column] multiplied per line and summed into total_field.
     * json_config.one = true makes it one-to-one: at most one record points back, and it is
     * sent and returned as one object (or null) instead of a list of lines.
     * json_config.criteria (see applicable()) decides when a save takes it.
     * A line form can have details of its own (details), up to DETAIL_DEPTH levels;
     * $parents are the forms above, so a form can't be a line of itself.
     *
     * @param  list<int>  $parents
     * @return array<int, array{field: string, one: bool, criteria: ?string, source: array, foreign_key: string, amount: ?array, total_field: ?string, details: array}>
     */
    private function details(Meta $meta, array $parents = []): array
    {
        if (! in_array($meta->type?->code, ['form', 'report'], true)) {
            return [];
        }
        $parents[] = $meta->id;

        return MetaParam::where('main_meta_id', $meta->id)->where('data_type', 'detail')->whereNotNull('ref_meta_id')
            ->orderBy('order_number')->get()
            ->map(function (MetaParam $param) use ($parents) {
                $child = Meta::find($param->ref_meta_id);
                $fk = $param->json_config['foreign_key'] ?? null;
                abort_unless($child && is_string($fk), 422, __('The detail ":field" needs a line form and a foreign_key.', ['field' => $param->field_path]));
                abort_if(in_array($child->id, $parents, true), 422, __('The detail ":field" uses a form that is already above it.', ['field' => $param->field_path]));
                abort_if(count($parents) > self::DETAIL_DEPTH, 422, 'Lines can have lines only '.self::DETAIL_DEPTH.' levels deep.');
                $source = $this->source($child, true);
                abort_unless(Schema::hasColumn($source['table'], $fk), 422, __('The detail ":field" points at a column that does not exist.', ['field' => $param->field_path]));
                $amount = $param->json_config['amount'] ?? null;

                return [
                    'field' => $param->field_path,
                    'one' => ($param->json_config['one'] ?? false) === true,
                    'criteria' => is_string($param->json_config['criteria'] ?? null) ? $param->json_config['criteria'] : null,
                    'source' => $source,
                    'foreign_key' => $fk,
                    'amount' => self::amountColumns($amount, $source['columns']),
                    'total_field' => $param->json_config['total_field'] ?? null,
                    'details' => $this->details($child, $parents),
                ];
            })->all();
    }

    /**
     * The details a save takes: those whose json_config.criteria holds, e.g. "roleId == 1 ||
     * appMetaId == 2 || supplierTypeId == 3". The criteria sees the record (its saved values,
     * then what is sent), Criteria::userVars, and appMetaId: the app the form was opened in,
     * sent as _context.app_meta_id and kept only when the user may view that app. A detail
     * left out is neither checked nor changed, so its saved lines or record stay as they are.
     *
     * @return array<int, array>
     */
    private function applicable(array $details, Request $request, ?object $before = null): array
    {
        if (! array_filter(array_column($details, 'criteria'))) {
            return $details;
        }
        $user = $request->user();
        $app = (int) $request->input('_context.app_meta_id');
        $sent = array_filter($request->except('_context'), fn ($v) => ! is_array($v));
        $vars = $sent + (array) $before + Criteria::userVars($user)
            + ['appMetaId' => $app && Access::can($user, $app, 'view') ? $app : null];

        return array_values(array_filter($details, function (array $detail) use ($vars) {
            try {
                return Criteria::passes($detail['criteria'], $vars);
            } catch (InvalidArgumentException $e) {
                abort(422, __('The criteria of ":field": :error', ['field' => $detail['field'], 'error' => $e->getMessage()]));
            }
        }));
    }

    /**
     * A detail's json_config.amount: one column (summed, e.g. quantity) or two (multiplied per
     * line, then summed, e.g. quantity × unit_price). Null unless every column exists.
     *
     * @return list<string>|null
     */
    private static function amountColumns(mixed $amount, array $columns): ?array
    {
        if (! is_array($amount) || ! in_array(count($amount), [1, 2], true)) {
            return null;
        }

        return array_diff($amount, $columns) === [] ? array_values($amount) : null;
    }

    /** Puts each detail's lines under the row (a one-to-one's record, or null), and each line's own lines under it. */
    private function withDetails(object $row, array $details, int $id): object
    {
        foreach ($details as $detail) {
            $s = $detail['source'];
            $lines = DB::table($s['table'])->select($s['columns'])->where($detail['foreign_key'], $id)->orderBy($s['key'])->get();
            $this->withSubLines($lines, $detail);
            $row->{$detail['field']} = $detail['one'] ? $lines->first() : $lines;
        }

        return $row;
    }

    /** Puts each line's own lines under it: one query per detail level, not per line. */
    private function withSubLines(Collection $lines, array $detail): void
    {
        $key = $detail['source']['key'];
        foreach ($detail['details'] as $sub) {
            $s = $sub['source'];
            $fk = $sub['foreign_key'];
            $ids = $lines->pluck($key)->all();
            $all = $ids === [] ? collect() : DB::table($s['table'])->select(array_values(array_unique([...$s['columns'], $fk])))
                ->whereIn($fk, $ids)->orderBy($s['key'])->get();
            $this->withSubLines($all, $sub);
            $byParent = $all->groupBy(fn (object $line) => (string) $line->{$fk});
            if (! in_array($fk, $s['columns'], true)) {
                $all->each(function (object $line) use ($fk) {
                    unset($line->{$fk});
                });
            }
            $lines->each(function (object $line) use ($sub, $byParent, $key) {
                $own = $byParent->get((string) $line->{$key}, collect())->values();
                $line->{$sub['field']} = $sub['one'] ? $own->first() : $own;
            });
        }
    }

    /** Removes these lines of a detail, their own lines first. */
    private function deleteLines(array $detail, array $ids): void
    {
        if (! $ids) {
            return;
        }
        foreach ($detail['details'] as $sub) {
            $this->deleteLines($sub, DB::table($sub['source']['table'])->whereIn($sub['foreign_key'], $ids)->pluck($sub['source']['key'])->all());
        }
        DB::table($detail['source']['table'])->whereIn($detail['source']['key'], $ids)->delete();
    }

    /**
     * Updates lines that kept their id, adds new ones, removes the rest (with their own
     * lines), then fills totals. A line's own lines are saved under it the same way.
     */
    private function saveLines(array $source, array $details, array $lines, int $id): void
    {
        foreach ($details as $detail) {
            if (! array_key_exists($detail['field'], $lines)) {
                continue;
            }
            $s = $detail['source'];
            $fk = $detail['foreign_key'];
            $existing = DB::table($s['table'])->where($fk, $id)->pluck($s['key'])->map(fn ($k) => (int) $k)->all();
            $kept = [];
            $subFields = array_flip(array_column($detail['details'], 'field'));

            foreach ($lines[$detail['field']] as $line) {
                // A one-to-one sent without its id still updates the record it has.
                $lineId = isset($line[$s['key']]) ? (int) $line[$s['key']] : ($detail['one'] ? ($existing[0] ?? null) : null);
                unset($line[$s['key']]);
                $subLines = array_intersect_key($line, $subFields);
                $line = array_diff_key($line, $subFields);
                $line[$fk] = $id;

                if ($lineId && in_array($lineId, $existing, true)) {
                    DB::table($s['table'])->where($s['key'], $lineId)->update($line + $this->timestamps($s['table'], false));
                    $kept[] = $lineId;
                } else {
                    $lineId = (int) DB::table($s['table'])->insertGetId($line + $this->timestamps($s['table'], true));
                }
                $this->saveLines($s, $detail['details'], $subLines, $lineId);
            }

            $this->deleteLines($detail, array_values(array_diff($existing, $kept)));

            $total = $detail['total_field'];
            if ($detail['amount'] && is_string($total) && in_array($total, $source['columns'], true)) {
                $columns = $detail['amount'];
                $sum = DB::table($s['table'])->where($fk, $id)->get($columns)
                    ->sum(fn ($l) => array_product(array_map(fn ($c) => (float) $l->$c, $columns)));
                DB::table($source['table'])->where($source['key'], $id)->update([$total => round($sum, 2)]);
            }
        }
    }

    /** Keeps records overlapping [from, to). One with no end is treated as ending when it starts. */
    private function inRange($query, Meta $meta, array $columns, mixed $from, mixed $to): void
    {
        $start = $meta->json_config['start_field'] ?? null;
        if (! is_string($start) || ! in_array($start, $columns, true)) {
            return;
        }
        $end = $meta->json_config['end_field'] ?? null;
        $end = is_string($end) && in_array($end, $columns, true) ? $end : null;

        if (is_string($to) && strtotime($to) !== false) {
            $query->where($start, '<', date('Y-m-d', strtotime($to)));
        }
        if (is_string($from) && strtotime($from) !== false) {
            $from = date('Y-m-d', strtotime($from));
            $query->where(fn ($q) => $end
                ? $q->where($end, '>=', $from)->orWhere(fn ($q) => $q->whereNull($end)->where($start, '>=', $from))
                : $q->where($start, '>=', $from));
        }
    }

    /**
     * Works out where a meta's records come from, the readable columns and the primary key,
     * or stops with 422 if the meta can't hold data.
     *
     * Reading a meta with a query_string uses the query as written (builders are trusted
     * with it); its columns are the params' field paths. Writing always goes to the meta's
     * table_name, so $forWrite ignores the query.
     *
     * A datagrid that reuses another (GridReuse) reads its origin's table, query and
     * workflow, with the params merged along the way.
     *
     * @return array{query: ?string, table: ?string, columns: string[], key: string, params: Collection}
     */
    private function source(Meta $meta, bool $forWrite = false): array
    {
        $meta->loadMissing('type');
        abort_unless(in_array($meta->type?->code, self::DATA_TYPES, true), 422, 'Only datagrid, form, calendar, gantt, statement and report metas have data.');
        $origin = GridReuse::origin($meta);
        $own = $origin === $meta ? MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get() : GridReuse::params($meta);
        $sql = trim((string) $origin->query_string);
        // A meta with neither table nor query reads the query meta its ref_meta_id names (QueryDesign).
        if ($sql === '' && trim((string) $origin->table_name) === '') {
            $sql = QueryDesign::readFrom($origin) ?? '';
        }
        if ($sql !== '' && ! $forWrite) {
            // Only plain names can be columns: they end up in WHERE and ORDER BY of the outer query.
            $params = $own
                ->filter(fn (MetaParam $p) => ! in_array($p->data_type, ['detail', 'subgrid', 'singlefile', 'multifile', 'document'], true) && preg_match('/^[A-Za-z_]\w*$/', $p->field_path))
                ->values();
            $key = $params->firstWhere('is_primary', true)?->field_path ?? 'id';

            return ['query' => $sql, 'table' => null, 'columns' => $params->pluck('field_path')->push($key)->unique()->values()->all(), 'key' => $key, 'params' => $params];
        }

        $table = trim((string) $origin->table_name);
        abort_if($sql !== '' && $table === '', 422, 'This meta reads from a query, so it can only be changed through a form with a table.');
        abort_if($table === '', 422, 'This meta has no database table set.');
        abort_if(in_array(strtolower($table), self::BLOCKED_TABLES, true), 422, 'This table cannot be used by a meta.');
        abort_unless(Schema::hasTable($table), 422, __('The table ":table" does not exist.', ['table' => $table]));

        $existing = Schema::getColumnListing($table);
        $params = $own
            ->filter(fn (MetaParam $p) => in_array($p->field_path, $existing, true))
            ->values();

        $key = $params->firstWhere('is_primary', true)?->field_path ?? 'id';
        abort_unless(in_array($key, $existing, true), 422, 'This table has no primary key column.');

        $columns = $params->pluck('field_path')->push($key)->unique()->values()->all();

        // A grid run by a workflow (its ref_meta_id) also reads each record's status. Forms
        // never write it: only moves through the workflow do.
        if (! in_array('status_id', $columns, true) && in_array('status_id', $existing, true) && $origin->ref_meta_id
            && Meta::whereKey($origin->ref_meta_id)->whereHas('type', fn ($q) => $q->where('code', 'workflow'))->exists()) {
            $columns[] = 'status_id';
        }

        return ['query' => null, 'table' => $table, 'columns' => $columns, 'key' => $key, 'params' => $params];
    }

    /**
     * Checks the record and each detail's lines against their params.
     *
     * @return array{0: array, 1: array<string, array>} the record's values, and lines by detail field
     */
    private function validated(Request $request, array $source, array $details, ?int $id = null): array
    {
        $rules = ParamRules::for($source['params'], $source['table'], $source['key'], $id) + $this->lineRules($details, $request->all());

        $valid = $request->validate($rules);

        $data = array_intersect_key($valid, array_flip($source['columns']));
        $this->checkOptions($source['params'], $data);

        $lines = $this->checkLines($details, $valid);

        return [$data, $lines];
    }

    /**
     * Rules for each detail's lines ("lines.*.quantity"), and their own lines' ("lines.*.deliveries.*.date").
     * A one-to-one's fields have no "*" ("profile.phone") and are checked only when it is sent:
     * at the top level, when $values hold it as an object; under lines, always.
     */
    private function lineRules(array $details, array $values, string $prefix = ''): array
    {
        $rules = [];
        foreach ($details as $detail) {
            $s = $detail['source'];
            $field = $prefix.$detail['field'];
            if ($detail['one']) {
                $rules[$field] = ['sometimes', 'nullable', 'array'];
                if ($prefix === '' && ! is_array($values[$field] ?? null)) {
                    continue;
                }
                $at = "{$field}.";
            } else {
                $rules[$field] = ['sometimes', 'array'];
                $at = "{$field}.*.";
            }
            $rules["{$at}{$s['key']}"] = ['nullable', 'integer'];
            $rules += ParamRules::for($s['params'], $s['table'], $s['key'], null, $at, [$detail['foreign_key']]);
            $rules += $this->lineRules($detail['details'], $values, $at);
        }

        return $rules;
    }

    /**
     * The lines sent for each detail (re-indexed), with options checked at every level. A
     * one-to-one becomes a list of its one record, or of none when sent as null (removing it).
     *
     * @return array<string, array>
     */
    private function checkLines(array $details, array $values, string $prefix = ''): array
    {
        $lines = [];
        foreach ($details as $detail) {
            if (! array_key_exists($detail['field'], $values)) {
                continue;
            }
            $sent = $values[$detail['field']];
            $lines[$detail['field']] = $detail['one'] ? ($sent === null ? [] : [$sent]) : array_values($sent);
            foreach ($lines[$detail['field']] as $i => $line) {
                $at = $detail['one'] ? "{$prefix}{$detail['field']}." : "{$prefix}{$detail['field']}.{$i}.";
                $this->checkOptions($detail['source']['params'], $line, $at);
                $lines[$detail['field']][$i] = array_merge($line, $this->checkLines($detail['details'], $line, $at));
            }
        }

        return $lines;
    }

    /** Lists of allowed values live in json_config.options. */
    private function checkOptions(Collection $params, array $data, string $prefix = ''): void
    {
        foreach ($params as $param) {
            $options = $param->json_config['options'] ?? null;
            $value = $data[$param->field_path] ?? null;
            if (is_array($options) && $value !== null && $value !== '' && ! in_array($value, $options, true)) {
                abort(response()->json([
                    'message' => __('The given data was invalid.'),
                    'errors' => [$prefix.$param->field_path => ['Pick one of: '.implode(', ', $options).'.']],
                ], 422));
            }
        }
    }

    private function timestamps(string $table, bool $creating): array
    {
        $now = now();
        $values = [];
        if (Schema::hasColumn($table, 'updated_at')) {
            $values['updated_at'] = $now;
        }
        if ($creating && Schema::hasColumn($table, 'created_at')) {
            $values['created_at'] = $now;
        }

        return $values;
    }

    /** Statements and reports only read; their records change through forms. */
    private function requireWritable(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_if(in_array($meta->type?->code, ['statement', 'report'], true), 422, 'Statements and reports only read records.');
    }

    /** Turns database refusals (e.g. deleting a supplier that orders still use) into a readable 422. */
    private function write(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            $message = str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), 'FOREIGN KEY')
                ? 'Other records still use this one, so it cannot be changed or deleted.'
                : 'The database refused this change.';
            abort(422, $message);
        }
    }

    /** Runs a read; a query that fails says why, so whoever wrote it can fix it. */
    private function read(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            abort(422, 'The query could not run: '.($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'unknown error').'.');
        }
    }
}
