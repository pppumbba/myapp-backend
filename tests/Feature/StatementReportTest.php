<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatementReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    public function test_statement_orders_rows_by_group_and_totals_every_row(): void
    {
        $response = $this->getJson("/api/metas/{$this->metaId('proc_orders_by_supplier')}/statement")
            ->assertOk()
            ->assertJsonCount(4, 'rows')
            ->assertJsonPath('group_by', ['supplier_name'])
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('totals.po_number', 4);

        $this->assertEquals(16225000, $response->json('totals.total_amount'));
        $suppliers = array_column($response->json('rows'), 'supplier_name');
        $this->assertSame($suppliers, collect($suppliers)->sort()->values()->all());
    }

    public function test_statement_filters_fill_its_named_parameters(): void
    {
        $response = $this->getJson("/api/metas/{$this->metaId('proc_orders_by_supplier')}/statement?filter[from_date]=2026-10-12&filter[to_date]=2026-10-16")
            ->assertOk()
            ->assertJsonCount(2, 'rows');

        $this->assertEquals(11420000, $response->json('totals.total_amount'));
    }

    public function test_report_reads_one_record_with_its_lines(): void
    {
        $order = DB::table('proc_purchase_orders')->where('po_number', 'PO-2026-0102')->value('id');

        $this->getJson("/api/metas/{$this->metaId('proc_order_report')}/data/{$order}")
            ->assertOk()
            ->assertJsonPath('po_number', 'PO-2026-0102')
            ->assertJsonCount(2, 'lines');
    }

    public function test_statements_and_reports_only_read(): void
    {
        $order = DB::table('proc_purchase_orders')->value('id');

        $this->putJson("/api/metas/{$this->metaId('proc_order_report')}/data/{$order}", ['notes' => 'changed'])->assertStatus(422);
        $this->deleteJson("/api/metas/{$this->metaId('proc_orders_by_supplier')}/data/{$order}")->assertStatus(422);
        $this->assertNotSame('changed', DB::table('proc_purchase_orders')->where('id', $order)->value('notes'));
    }

    public function test_drilldowns_point_at_the_report(): void
    {
        $report = $this->metaId('proc_order_report');

        $this->assertSame($report, Meta::find($this->metaId('proc_orders_grid'))->json_config['drilldown']['meta_id']);
        $this->assertSame($report, Meta::find($this->metaId('proc_orders_by_supplier'))->json_config['drilldown']['meta_id']);
    }
}
