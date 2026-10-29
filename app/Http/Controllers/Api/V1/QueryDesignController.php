<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\NamedSql;
use App\Support\QueryDesign;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The query builder (see QueryDesign): the tables and views to drag in, a query meta's
 * design, running it to see its rows, and making a grid that reads it.
 */
class QueryDesignController extends Controller
{
    /** A preview shows up to this many rows; a query meta opened on its own, RUN_ROWS. */
    public const PREVIEW_ROWS = 100;

    public const RUN_ROWS = 500;

    /** GET /query-builder/tables — every table and view a query can read. */
    public function tables(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(QueryDesign::tables());
    }

    /** GET /query-builder/tables/{table} — its columns, and its foreign keys to join with. */
    public function table(Request $request, string $table): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(['name' => $table, 'columns' => QueryDesign::columns($table), 'foreign_keys' => QueryDesign::foreignKeys($table)]);
    }

    /** GET /query-builder/sources — the metas whose stored SELECT can be a sub-query. */
    public function sources(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(QueryDesign::sources());
    }

    /** GET /query-builder/sources/{meta} — a stored query's columns and the :named parameters it takes. */
    public function source(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(['id' => $meta->id, 'name' => $meta->name, 'columns' => QueryDesign::describe($meta), 'params' => QueryDesign::namedParams((string) $meta->query_string)]);
    }

    /** GET /metas/{meta}/query-design — the design, its SQL and the :named parameters it takes. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->requireQuery($meta);
        $sql = (string) $meta->query_string;

        return response()->json(['design' => QueryDesign::load($meta), 'sql' => $sql, 'params' => QueryDesign::namedParams($sql)]);
    }

    /** PUT /metas/{meta}/query-design {design} — keeps the design as params and its SQL as query_string. */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireQuery($meta);
        $request->validate(['design' => ['required', 'array']]);

        $saved = QueryDesign::save($meta, $request->input('design'), $request->user()->id);

        return response()->json($saved + ['params' => QueryDesign::namedParams($saved['sql'])]);
    }

    /**
     * POST /metas/{meta}/query-design/preview {design, params} — runs a design, saved or not,
     * and returns its SQL and first PREVIEW_ROWS rows. Nothing is changed.
     */
    public function preview(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireQuery($meta);
        $request->validate(['design' => ['required', 'array'], 'params' => ['nullable', 'array']]);

        $sql = QueryDesign::compile(QueryDesign::normalize($request->input('design'), $meta));

        return response()->json($this->run($request, $sql, (array) $request->input('params', []), self::PREVIEW_ROWS));
    }

    /** GET /metas/{meta}/query-design/rows?params[name]= — the saved query's first RUN_ROWS rows. */
    public function rows(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->requireQuery($meta);
        $sql = trim((string) $meta->query_string);
        abort_if($sql === '', 422, 'This query has no design yet.');

        return response()->json($this->run($request, $sql, (array) $request->query('params', []), self::RUN_ROWS));
    }

    /**
     * POST /metas/{meta}/query-design/grid — makes a datagrid that reads this query (its
     * ref_meta_id): a column for each selected column, and a hidden filter for each :name.
     */
    public function grid(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireQuery($meta);
        abort_if(trim((string) $meta->query_string) === '', 422, 'Save the design before making a grid from it.');

        return response()->json(QueryDesign::makeGrid($meta, $request->user()->id)->load('type'), 201);
    }

    /**
     * Runs SQL with :named values (and the built-ins) and returns up to $limit rows.
     *
     * @return array{sql: string, columns: list<string>, rows: list<object>, truncated: bool, params: list<string>}
     */
    private function run(Request $request, string $sql, array $values, int $limit): array
    {
        $values = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : null, $values);
        [$bound, $bindings] = NamedSql::bind($sql, $values + NamedSql::builtins($request->user()));

        // A query only reads, but it runs in a transaction that is always undone all the same.
        DB::beginTransaction();
        try {
            $rows = DB::select('SELECT * FROM ('.$bound.') AS preview LIMIT '.($limit + 1), $bindings);
        } catch (QueryException $e) {
            abort(422, 'The database refused this query: '.Str::limit($e->getPrevious()?->getMessage() ?? $e->getMessage(), 500));
        } finally {
            DB::rollBack();
        }

        return [
            'sql' => $sql,
            'columns' => $rows ? array_keys((array) $rows[0]) : [],
            'rows' => array_slice($rows, 0, $limit),
            'truncated' => count($rows) > $limit,
            'params' => QueryDesign::namedParams($sql),
        ];
    }

    private function requireQuery(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'query', 422, 'Only query metas have a query design.');
    }
}
