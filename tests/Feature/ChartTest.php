<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChartTest extends TestCase
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

    private function preview(string $code, array $config, array $range = [])
    {
        return $this->postJson("/api/metas/{$this->metaId($code)}/chart/preview", ['config' => $config] + $range);
    }

    public function test_kpi_sums_a_column_and_compares_with_the_period_before(): void
    {
        $this->getJson("/api/metas/{$this->metaId('proc_chart_spend')}/chart")
            ->assertOk()
            ->assertJsonPath('type', 'kpi')
            ->assertJsonPath('format', 'money')
            ->assertJsonPath('kpi.value', 16225000)
            ->assertJsonPath('kpi.previous', null);

        // Oct 9-16 holds two orders; the 8 days before hold the Oct 2 one.
        $this->getJson("/api/metas/{$this->metaId('proc_chart_spend')}/chart?from=2026-10-09&to=2026-10-16")
            ->assertOk()
            ->assertJsonPath('kpi.value', 11420000)
            ->assertJsonPath('kpi.previous', 605000);
    }

    public function test_groups_by_a_column_with_labels_from_a_lookup(): void
    {
        $this->getJson("/api/metas/{$this->metaId('proc_chart_spend_by_supplier')}/chart")
            ->assertOk()
            ->assertJsonCount(4, 'rows')
            ->assertJsonPath('series.0.label', 'Spend')
            ->assertJsonPath('rows.0.label', 'Northwind Electronics')
            ->assertJsonPath('rows.0.values.m0', 10180000);
    }

    public function test_buckets_dates_and_applies_filters(): void
    {
        $this->preview('proc_chart_spend_by_week', ['type' => 'line', 'x' => 'order_date', 'bucket' => 'month', 'measures' => [['agg' => 'count']]])
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.x', '2026-10')
            ->assertJsonPath('rows.0.values.m0', 4);

        $this->preview('proc_chart_spend_by_week', [
            'type' => 'column', 'x' => 'status', 'measures' => [['agg' => 'count']],
            'filters' => [['column' => 'total_amount', 'op' => '>', 'value' => 1000000]],
        ])->assertOk()->assertJsonCount(3, 'rows');
    }

    public function test_split_makes_a_series_per_value_and_pies_fold_the_rest(): void
    {
        $this->preview('proc_chart_requests_by_department', ['type' => 'column', 'x' => 'department', 'split' => 'status', 'measures' => [['agg' => 'count']]])
            ->assertOk()
            ->assertJsonCount(5, 'series')
            ->assertJsonCount(5, 'rows');

        $this->preview('proc_chart_requests_by_status', ['type' => 'pie', 'x' => 'status', 'limit' => 2, 'measures' => [['agg' => 'count']]])
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.1.label', 'Other')
            ->assertJsonPath('rows.1.values.m0', 4);
    }

    public function test_columns_list_the_table_with_kinds(): void
    {
        $columns = collect($this->getJson("/api/metas/{$this->metaId('proc_chart_spend')}/columns")->assertOk()->json())->pluck('kind', 'name');

        $this->assertSame('number', $columns['total_amount']);
        $this->assertSame('date', $columns['order_date']);
        $this->assertSame('text', $columns['po_number']);
    }

    public function test_unknown_columns_and_system_tables_are_refused(): void
    {
        $this->preview('proc_chart_spend', ['type' => 'column', 'x' => 'password', 'measures' => [['agg' => 'count']]])
            ->assertStatus(422);
        $this->preview('proc_chart_spend', ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'total_amount; DROP TABLE users']]])
            ->assertStatus(422);

        $chart = Meta::create(['code' => 'users_chart', 'name' => 'Users', 'type_id' => MetaType::where('code', 'chart')->value('id'), 'table_name' => 'users', 'json_config' => ['type' => 'kpi']]);
        $this->getJson("/api/metas/{$chart->id}/chart")->assertStatus(422);
    }

    public function test_only_admins_preview_or_list_columns(): void
    {
        $chart = $this->metaId('proc_chart_spend');
        Sanctum::actingAs($this->allow(User::factory()->create(), [$chart]));

        $this->getJson("/api/metas/{$chart}/chart")->assertOk();
        $this->postJson("/api/metas/{$chart}/chart/preview", ['config' => []])->assertForbidden();
        $this->getJson("/api/metas/{$chart}/columns")->assertForbidden();
    }
}
