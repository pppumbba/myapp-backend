<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The query builder: query metas designed from tables, kept as params, compiled and read by grids. */
class QueryDesignTest extends TestCase
{
    use RefreshDatabase;

    private Meta $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ProcurementDemoSeeder::class]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->query = $this->queryMeta('supplier_totals_query', 'Supplier totals');
    }

    private function queryMeta(string $code, string $name): Meta
    {
        return Meta::create(['code' => $code, 'name' => $name, 'type_id' => MetaType::where('code', 'query')->value('id'), 'is_active' => true]);
    }

    /** Orders per supplier: joined, grouped, counted, summed, filtered and sorted. */
    private function design(array $extra = []): array
    {
        return $extra + [
            'tables' => [
                ['key' => 'o', 'name' => 'proc_purchase_orders', 'x' => 20, 'y' => 20],
                ['key' => 's', 'name' => 'proc_suppliers', 'x' => 320, 'y' => 20, 'join' => ['type' => 'inner', 'on' => [
                    ['left' => ['table' => 'o', 'column' => 'supplier_id'], 'op' => '=', 'right' => ['table' => 's', 'column' => 'id']],
                ]]],
            ],
            'columns' => [
                ['expr' => ['table' => 's', 'column' => 'name'], 'alias' => 'supplier', 'label' => 'Supplier', 'show' => true],
                ['expr' => ['table' => 'o', 'column' => 'id'], 'alias' => 'orders', 'aggregate' => 'count', 'data_type' => 'integer', 'show' => true],
                ['expr' => ['table' => 'o', 'column' => 'total_amount'], 'alias' => 'total', 'aggregate' => 'sum', 'data_type' => 'decimal', 'show' => true, 'sort' => 'desc', 'sort_order' => 1],
            ],
            'where' => ['logic' => 'and', 'items' => [
                ['type' => 'condition', 'left' => ['table' => 'o', 'column' => 'total_amount'], 'op' => '>', 'value_type' => 'value', 'value' => '1000'],
                ['type' => 'condition', 'left' => ['table' => 'o', 'column' => 'order_date'], 'op' => '>=', 'value_type' => 'param', 'value' => 'from_date'],
            ]],
            'having' => ['logic' => 'and', 'items' => [
                ['type' => 'condition', 'left' => ['table' => 'o', 'column' => 'id'], 'aggregate' => 'count', 'op' => '>=', 'value_type' => 'value', 'value' => '1'],
            ]],
        ];
    }

    /** What the design should give, worked out with Laravel's query builder. */
    private function expected(?string $fromDate = null): array
    {
        return DB::table('proc_purchase_orders as o')->join('proc_suppliers as s', 'o.supplier_id', '=', 's.id')
            ->where('o.total_amount', '>', 1000)->when($fromDate, fn ($q) => $q->where('o.order_date', '>=', $fromDate))
            ->groupBy('s.name')->selectRaw('s.name AS supplier, COUNT(o.id) AS orders, SUM(o.total_amount) AS total')
            ->orderByDesc('total')->get()->map(fn ($r) => [$r->supplier, (int) $r->orders, round((float) $r->total, 2)])->all();
    }

    private function rows(array $rows): array
    {
        return array_map(fn ($r) => [$r['supplier'], (int) $r['orders'], round((float) $r['total'], 2)], $rows);
    }

    public function test_it_lists_tables_and_views_with_their_columns_and_foreign_keys_but_not_system_tables(): void
    {
        $names = collect($this->getJson('/api/query-builder/tables')->assertOk()->json())->pluck('name');
        $this->assertContains('proc_purchase_orders', $names);
        $this->assertNotContains('users', $names);
        $this->assertNotContains('meta_params', $names);
        $this->assertNotContains('meta_tag_map', $names);
        $this->assertSame($names->count(), $names->unique()->count());

        $table = $this->getJson('/api/query-builder/tables/proc_purchase_orders')->assertOk()->json();
        $this->assertSame('decimal', collect($table['columns'])->firstWhere('name', 'total_amount')['data_type']);
        $this->assertContains('proc_suppliers', array_column($table['foreign_keys'], 'foreign_table'));

        $this->getJson('/api/query-builder/tables/users')->assertUnprocessable();
    }

    public function test_a_design_is_kept_as_params_and_compiled_to_sql_that_runs(): void
    {
        $saved = $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $this->design()])->assertOk()->json();

        $this->assertSame(['from_date'], $saved['params']);
        $this->assertStringContainsString('GROUP BY', $saved['sql']);
        $this->assertStringContainsString('HAVING COUNT(', $saved['sql']);
        $this->assertSame($saved['sql'], $this->query->fresh()->query_string);
        $this->assertSame(2, MetaParam::where('main_meta_id', $this->query->id)->where('data_type', 'table')->count());
        $this->assertSame(3, MetaParam::where('main_meta_id', $this->query->id)->whereNull('parent_id')->whereNotIn('data_type', ['table', 'where', 'having'])->count());

        // Read back as it was drawn.
        $design = $this->getJson("/api/metas/{$this->query->id}/query-design")->assertOk()->json('design');
        $this->assertSame('s', $design['tables'][1]['key']);
        $this->assertSame('inner', $design['tables'][1]['join']['type']);
        $this->assertSame(320, $design['tables'][1]['x']);
        $this->assertSame(['supplier', 'orders', 'total'], array_column($design['columns'], 'alias'));
        $this->assertSame('from_date', $design['where']['items'][1]['value']);
        $this->assertSame('count', $design['having']['items'][0]['aggregate']);

        // An empty parameter leaves its condition out; a filled one applies.
        $all = $this->getJson("/api/metas/{$this->query->id}/query-design/rows")->assertOk()->json('rows');
        $this->assertSame($this->expected(), $this->rows($all));
        $from = DB::table('proc_purchase_orders')->orderBy('order_date')->skip(2)->value('order_date');
        $some = $this->getJson("/api/metas/{$this->query->id}/query-design/rows?params[from_date]={$from}")->assertOk()->json('rows');
        $this->assertSame($this->expected($from), $this->rows($some));
    }

    public function test_preview_runs_an_unsaved_design_and_changes_nothing(): void
    {
        $design = $this->design(['distinct' => true, 'limit' => 2]);
        $preview = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $design])->assertOk()->json();

        $this->assertSame(['supplier', 'orders', 'total'], $preview['columns']);
        $this->assertSame(array_slice($this->expected(), 0, 2), $this->rows($preview['rows']));
        $this->assertNull($this->query->fresh()->query_string);
        $this->assertSame(0, MetaParam::where('main_meta_id', $this->query->id)->count());
    }

    public function test_it_refuses_unknown_columns_system_tables_and_unjoined_tables(): void
    {
        $bad = $this->design();
        $bad['columns'][0]['expr']['column'] = 'nope';
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $bad])->assertUnprocessable()->assertJsonPath('message', '"s" has no column "nope".');

        $bad = $this->design();
        $bad['tables'][0]['name'] = 'users';
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $bad])->assertUnprocessable();

        $bad = $this->design();
        $bad['tables'][1]['join']['on'] = [];
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $bad])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Connect "s"'));

        $this->assertNull($this->query->fresh()->query_string);
    }

    public function test_a_query_can_read_another_as_a_sub_query_and_add_its_rows_but_not_read_itself(): void
    {
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $this->design()])->assertOk();

        $big = $this->queryMeta('big_suppliers_query', 'Big suppliers');
        $design = [
            'tables' => [['key' => 't', 'source' => 'query', 'meta_id' => $this->query->id]],
            'columns' => [
                ['expr' => ['table' => 't', 'column' => 'supplier'], 'alias' => 'supplier', 'show' => true],
                ['expr' => ['table' => 't', 'column' => 'orders'], 'alias' => 'orders', 'show' => true],
                ['expr' => ['table' => 't', 'column' => 'total'], 'alias' => 'total', 'show' => true],
            ],
            'unions' => [['meta_id' => $this->query->id, 'all' => true]],
        ];
        $rows = $this->putJson("/api/metas/{$big->id}/query-design", ['design' => $design])->assertOk();
        $rows = $this->getJson("/api/metas/{$big->id}/query-design/rows")->assertOk()->json('rows');
        $this->assertCount(2 * count($this->expected()), $rows);

        // The first query reading the second, which reads it, would go round for ever.
        $loop = $this->design();
        $loop['tables'][] = ['key' => 'b', 'source' => 'query', 'meta_id' => $big->id, 'join' => ['type' => 'cross']];
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $loop])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'reads this query'));
    }

    public function test_any_meta_with_a_stored_select_is_a_source_with_its_columns_and_parameters(): void
    {
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $this->design()])->assertOk();
        $sources = collect($this->getJson('/api/query-builder/sources')->assertOk()->json())->keyBy('code');

        $this->assertSame('datagrid', $sources['proc_supplier_orders_grid']['type']);
        $this->assertSame(['supplier_id'], $sources['proc_supplier_orders_grid']['params']);
        $this->assertSame('query', $sources['supplier_totals_query']['type']);
        $this->assertFalse($sources->has('proc_close_overdue_drafts'));

        $grid = $sources['proc_supplier_orders_grid']['id'];
        $columns = collect($this->getJson("/api/query-builder/sources/{$grid}")->assertOk()->json('columns'))->keyBy('name');
        $this->assertSame(['id', 'po_number', 'order_date', 'expected_date', 'total_amount', 'status_id'], $columns->keys()->all());
        $this->assertSame('decimal', $columns['total_amount']['data_type']);
    }

    public function test_a_stored_query_in_from_takes_values_and_parameters_but_not_columns(): void
    {
        $grid = Meta::where('code', 'proc_supplier_orders_grid')->value('id');
        $supplier = DB::table('proc_purchase_orders')->value('supplier_id');
        $design = fn (array $bind) => [
            'tables' => [['key' => 'so', 'source' => 'query', 'meta_id' => $grid, 'bind' => $bind]],
            'columns' => [['expr' => ['table' => 'so', 'column' => 'po_number'], 'alias' => 'po_number', 'show' => true, 'sort' => 'asc']],
        ];

        $rows = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $design(['supplier_id' => ['value' => (string) $supplier]])])->assertOk()->json('rows');
        $this->assertSame(DB::table('proc_purchase_orders')->where('supplier_id', $supplier)->orderBy('po_number')->pluck('po_number')->all(), array_column($rows, 'po_number'));

        // Passed on as this query's own parameter, which a grid's filter fills.
        $saved = $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $design(['supplier_id' => ['param' => 'vendor']])])->assertOk()->json();
        $this->assertSame(['vendor'], $saved['params']);
        $this->assertSame(['param' => 'vendor'], (array) $saved['design']['tables'][0]['bind']['supplier_id']);

        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $design(['supplier_id' => ['table' => 'so', 'column' => 'id']])])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'takes a value or a parameter'));
    }

    public function test_stored_queries_as_correlated_columns_in_lists_and_exists(): void
    {
        $count = $this->queryMeta('order_count_query', 'Order count');
        $this->putJson("/api/metas/{$count->id}/query-design", ['design' => [
            'tables' => [['key' => 'o', 'name' => 'proc_purchase_orders']],
            'columns' => [['expr' => ['table' => 'o', 'column' => 'id'], 'alias' => 'n', 'aggregate' => 'count', 'show' => true]],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'left' => ['table' => 'o', 'column' => 'supplier_id'], 'op' => '=', 'value_type' => 'param', 'value' => 'supplier_id']]],
        ]])->assertOk();
        $grid = Meta::where('code', 'proc_supplier_orders_grid')->value('id');
        $sid = ['table' => 's', 'column' => 'id'];
        $suppliers = fn (array $where, array $columns = []) => ['design' => [
            'tables' => [['key' => 's', 'name' => 'proc_suppliers']],
            'columns' => [['expr' => ['table' => 's', 'column' => 'name'], 'alias' => 'name', 'show' => true, 'sort' => 'asc'], ...$columns],
            'where' => ['logic' => 'and', 'items' => $where],
        ]];
        $names = fn ($q) => $q->orderBy('name')->pluck('name')->all();

        // Each supplier's order count, from a stored query filled with the row's own id.
        $rows = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers([], [
            ['expr' => ['subquery' => $count->id, 'bind' => ['supplier_id' => $sid]], 'alias' => 'orders', 'show' => true],
        ]))->assertOk()->json('rows');
        $expected = DB::table('proc_suppliers as s')->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->name => DB::table('proc_purchase_orders')->where('supplier_id', $s->id)->count()])->all();
        $this->assertSame($expected, collect($rows)->mapWithKeys(fn ($r) => [$r['name'] => (int) $r['orders']])->all());

        // Suppliers with orders (EXISTS) and without (NOT EXISTS), from the supplier-orders grid's SQL.
        $exists = fn (string $op) => [['type' => 'condition', 'op' => $op, 'right' => ['subquery' => $grid, 'bind' => ['supplier_id' => $sid]]]];
        $with = array_column($this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers($exists('exists')))->assertOk()->json('rows'), 'name');
        $without = array_column($this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers($exists('not_exists')))->assertOk()->json('rows'), 'name');
        $this->assertSame($names(DB::table('proc_suppliers')->whereIn('id', DB::table('proc_purchase_orders')->select('supplier_id'))), $with);
        $this->assertSame($names(DB::table('proc_suppliers')->whereNotIn('id', DB::table('proc_purchase_orders')->select('supplier_id'))), $without);

        // IN a stored query's column, its parameter given a value.
        $supplier = DB::table('proc_purchase_orders')->value('supplier_id');
        $in = [['type' => 'condition', 'left' => $sid, 'op' => 'in', 'value_type' => 'subquery', 'right' => ['subquery' => $this->supplierIdsQuery()->id, 'column' => 'id', 'bind' => ['only' => ['value' => (string) $supplier]]]]];
        $rows = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers($in))->assertOk()->json('rows');
        $this->assertSame([DB::table('proc_suppliers')->where('id', $supplier)->value('name')], array_column($rows, 'name'));

        // One column of a query meta, per row: it is compiled selecting just that column.
        $named = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers([], [
            ['expr' => ['subquery' => $this->supplierIdsQuery()->id, 'column' => 'name', 'bind' => ['only' => $sid]], 'alias' => 'same', 'show' => true],
        ]))->assertOk()->json();
        $this->assertStringNotContainsString('AS "sq"', $named['sql']);
        $this->assertSame(array_column($named['rows'], 'name'), array_column($named['rows'], 'same'));

        // A raw stored SELECT can't give one of its columns per row (MySQL can't see the row in a derived table).
        $this->postJson("/api/metas/{$this->query->id}/query-design/preview", $suppliers([], [
            ['expr' => ['subquery' => $grid, 'column' => 'po_number', 'bind' => ['supplier_id' => $sid]], 'alias' => 'po', 'show' => true],
        ]))->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'from each row'));

        // The grid's raw SQL calls its orders "o" too, so this query's "o" can't be put in it.
        $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => [
            'tables' => [['key' => 'o', 'name' => 'proc_purchase_orders']],
            'columns' => [['expr' => ['table' => 'o', 'column' => 'id'], 'alias' => 'id', 'show' => true]],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'op' => 'exists', 'right' => ['subquery' => $grid, 'bind' => ['supplier_id' => ['table' => 'o', 'column' => 'supplier_id']]]]]],
        ]])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'uses the alias "o" too'));
    }

    public function test_a_stored_query_reading_this_one_through_a_sub_query_is_refused(): void
    {
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $this->design()])->assertOk();
        $outer = $this->queryMeta('outer_query', 'Outer');
        $this->putJson("/api/metas/{$outer->id}/query-design", ['design' => [
            'tables' => [['key' => 's', 'name' => 'proc_suppliers']],
            'columns' => [['expr' => ['subquery' => $this->query->id, 'column' => 'total'], 'alias' => 'x', 'show' => true]],
            'limit' => 1,
        ]])->assertOk();

        $loop = $this->design();
        $loop['columns'][] = ['expr' => ['subquery' => $outer->id], 'alias' => 'y', 'show' => true];
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $loop])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'reads this query'));
    }

    /** Each supplier's orders as an inline sub-query: correlated on the outer s.id, no meta of its own. */
    private function inlineOrders(array $extra = []): array
    {
        return $extra + ['key' => 'orders_of', 'label' => 'Orders of the supplier', 'design' => [
            'tables' => [['key' => 'io', 'name' => 'proc_purchase_orders']],
            'columns' => [
                ['expr' => ['table' => 'io', 'column' => 'id'], 'alias' => 'n', 'aggregate' => 'count', 'show' => true],
                ['expr' => ['table' => 'io', 'column' => 'total_amount'], 'alias' => 'spent', 'aggregate' => 'sum', 'show' => true],
            ],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'left' => ['table' => 'io', 'column' => 'supplier_id'], 'op' => '=', 'value_type' => 'column', 'right' => ['table' => 's', 'column' => 'id']]]],
        ]];
    }

    public function test_inline_sub_queries_are_kept_inside_the_query_and_used_as_columns_and_conditions(): void
    {
        $metas = Meta::count();
        $design = [
            'tables' => [['key' => 's', 'name' => 'proc_suppliers']],
            'columns' => [
                ['expr' => ['table' => 's', 'column' => 'name'], 'alias' => 'name', 'show' => true, 'sort' => 'asc'],
                ['expr' => ['inline' => 'orders_of', 'column' => 'n'], 'alias' => 'orders', 'show' => true],
            ],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'op' => 'exists', 'right' => ['inline' => 'orders_with_lines']]]],
            'subqueries' => [
                $this->inlineOrders(),
                ['key' => 'orders_with_lines', 'design' => [
                    'tables' => [['key' => 'eo', 'name' => 'proc_purchase_orders']],
                    'columns' => [['expr' => ['table' => 'eo', 'column' => 'id'], 'alias' => 'id', 'show' => true]],
                    'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'left' => ['table' => 'eo', 'column' => 'supplier_id'], 'op' => '=', 'value_type' => 'column', 'right' => ['table' => 's', 'column' => 'id']]]],
                ]],
            ],
        ];
        $saved = $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $design])->assertOk()->json();

        // Its tables, columns and conditions are params under a subquery param; no new meta.
        $this->assertSame($metas, Meta::count());
        $sub = MetaParam::where('main_meta_id', $this->query->id)->where('data_type', 'subquery')->where('param_name', 'orders_of')->firstOrFail();
        $this->assertSame('proc_purchase_orders', MetaParam::where('parent_id', $sub->id)->where('data_type', 'table')->value('column_path'));
        $this->assertSame(['orders_of', 'orders_with_lines'], array_column($saved['design']['subqueries'], 'key'));
        $this->assertSame('Orders of the supplier', $saved['design']['subqueries'][0]['label']);
        $this->assertSame(['n', 'spent'], array_column($saved['design']['subqueries'][0]['design']['columns'], 'alias'));
        $this->assertSame('s', $saved['design']['subqueries'][0]['design']['where']['items'][0]['right']['table']);

        $rows = $this->getJson("/api/metas/{$this->query->id}/query-design/rows")->assertOk()->json('rows');
        $expected = DB::table('proc_suppliers as s')->whereExists(fn ($q) => $q->from('proc_purchase_orders')->whereColumn('supplier_id', 's.id'))->orderBy('name')->get()
            ->mapWithKeys(fn ($s) => [$s->name => DB::table('proc_purchase_orders')->where('supplier_id', $s->id)->count()])->all();
        $this->assertSame($expected, collect($rows)->mapWithKeys(fn ($r) => [$r['name'] => (int) $r['orders']])->all());

        // Read inside another query, its aliases are renamed with the rest, so they still don't clash.
        $outer = $this->queryMeta('outer_query', 'Outer');
        $read = $this->postJson("/api/metas/{$outer->id}/query-design/preview", ['design' => [
            'tables' => [['key' => 's', 'name' => 'proc_suppliers']],
            'columns' => [['expr' => ['table' => 's', 'column' => 'id'], 'alias' => 'id', 'show' => true]],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'left' => ['table' => 's', 'column' => 'name'], 'op' => 'in', 'value_type' => 'subquery', 'right' => ['subquery' => $this->query->id, 'column' => 'name']]]],
        ]])->assertOk()->json();
        $this->assertCount(count($expected), $read['rows']);
        $this->assertStringContainsString('"eo_'.$this->query->id.'"."supplier_id" = "s_'.$this->query->id, $read['sql']);
    }

    public function test_an_inline_sub_query_can_be_a_table_or_a_union_but_then_not_see_the_outer_row(): void
    {
        $totals = ['key' => 'totals', 'design' => [
            'tables' => [['key' => 'to', 'name' => 'proc_purchase_orders']],
            'columns' => [
                ['expr' => ['table' => 'to', 'column' => 'supplier_id'], 'alias' => 'supplier_id', 'aggregate' => 'group', 'show' => true],
                ['expr' => ['table' => 'to', 'column' => 'total_amount'], 'alias' => 'spent', 'aggregate' => 'sum', 'show' => true],
            ],
        ]];
        $design = [
            'tables' => [
                ['key' => 's', 'name' => 'proc_suppliers'],
                ['key' => 't', 'source' => 'inline', 'name' => 'totals', 'join' => ['type' => 'inner', 'on' => [
                    ['left' => ['table' => 's', 'column' => 'id'], 'op' => '=', 'right' => ['table' => 't', 'column' => 'supplier_id']],
                ]]],
            ],
            'columns' => [
                ['expr' => ['table' => 's', 'column' => 'name'], 'alias' => 'name', 'show' => true],
                ['expr' => ['table' => 't', 'column' => 'spent'], 'alias' => 'spent', 'show' => true],
            ],
            'subqueries' => [$totals],
        ];
        $rows = $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $design])->assertOk()->json('rows');
        $this->assertCount(DB::table('proc_purchase_orders')->distinct()->count('supplier_id'), $rows);

        // As a table it can't use the outer row's s.id.
        $bad = $design;
        $bad['subqueries'] = [$this->inlineOrders()];
        $bad['tables'][1]['name'] = 'orders_of';
        $bad['tables'][1]['join']['on'][0]['right']['column'] = 'n';
        $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $bad])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'is used as a table'));

        // Aliases are unique across the query and its sub-queries; sub-queries can't use each other in a circle.
        $clash = $design;
        $clash['subqueries'][0]['design']['tables'][0]['key'] = 's';
        $clash['subqueries'][0]['design']['columns'][0]['expr']['table'] = 's';
        $clash['subqueries'][0]['design']['columns'][1]['expr']['table'] = 's';
        $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $clash])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Two tables are both called "s"'));

        $circle = $design;
        $circle['subqueries'][0]['design']['where'] = ['logic' => 'and', 'items' => [['type' => 'condition', 'op' => 'exists', 'right' => ['inline' => 'totals']]]];
        $this->postJson("/api/metas/{$this->query->id}/query-design/preview", ['design' => $circle])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'in a circle'));
    }

    /** A query of supplier ids, optionally just :only. */
    private function supplierIdsQuery(): Meta
    {
        if ($made = Meta::where('code', 'supplier_ids_query')->first()) {
            return $made;
        }
        $ids = $this->queryMeta('supplier_ids_query', 'Supplier ids');
        $this->putJson("/api/metas/{$ids->id}/query-design", ['design' => [
            'tables' => [['key' => 's', 'name' => 'proc_suppliers']],
            'columns' => [['expr' => ['table' => 's', 'column' => 'id'], 'alias' => 'id', 'show' => true], ['expr' => ['table' => 's', 'column' => 'name'], 'alias' => 'name', 'show' => true]],
            'where' => ['logic' => 'and', 'items' => [['type' => 'condition', 'left' => ['table' => 's', 'column' => 'id'], 'op' => '=', 'value_type' => 'param', 'value' => 'only']]],
        ]])->assertOk();

        return $ids;
    }

    public function test_a_grid_made_from_a_query_reads_it_and_filters_by_its_parameters(): void
    {
        $this->putJson("/api/metas/{$this->query->id}/query-design", ['design' => $this->design()])->assertOk();

        $grid = $this->postJson("/api/metas/{$this->query->id}/query-design/grid")->assertCreated()->json();
        $this->assertSame($this->query->id, $grid['ref_meta_id']);
        $params = MetaParam::where('main_meta_id', $grid['id'])->get()->keyBy('field_path');
        $this->assertSame(['supplier', 'orders', 'total', 'from_date'], $params->keys()->all());
        $this->assertFalse($params['from_date']->is_show);
        $this->assertTrue($params['from_date']->is_criteria);

        $data = $this->getJson("/api/metas/{$grid['id']}/data?per_page=100")->assertOk()->json('data');
        $this->assertSame($this->expected(), $this->rows($data));

        $from = DB::table('proc_purchase_orders')->orderBy('order_date')->skip(2)->value('order_date');
        $data = $this->getJson("/api/metas/{$grid['id']}/data?per_page=100&filter[from_date]={$from}")->assertOk()->json('data');
        $this->assertSame($this->expected($from), $this->rows($data));
    }
}
