<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The delivery schedule reuses the purchase orders grid (ref_meta_id): its query, workflow,
 * buttons and footer, with its own params changing columns. Also the grid footer itself.
 */
class GridReuseTest extends TestCase
{
    use RefreshDatabase;

    private int $orders;

    private int $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->orders = Meta::where('code', 'proc_orders_grid')->value('id');
        $this->schedule = Meta::where('code', 'proc_order_schedule_grid')->value('id');
    }

    public function test_the_footer_adds_up_every_matching_row_not_just_the_page(): void
    {
        $poNumber = MetaParam::where('main_meta_id', $this->orders)->where('field_path', 'po_number')->firstOrFail();
        $poNumber->update(['json_config' => [...($poNumber->json_config ?? []), 'aggregate' => 'count']]);
        $count = DB::table('proc_purchase_orders')->count();
        $sum = (float) DB::table('proc_purchase_orders')->sum('total_amount');

        $this->getJson("/api/metas/{$this->orders}/data?per_page=1")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('footer.po_number', $count)
            ->assertJsonPath('footer.total_amount', fn ($v) => abs((float) $v - $sum) < 0.01);

        $this->getJson("/api/metas/{$this->orders}/data?filter[po_number]=PO-2026-0102")->assertOk()->assertJsonPath('footer.po_number', 1);
    }

    public function test_a_reusing_grid_reads_its_base_records_with_its_own_columns(): void
    {
        $base = $this->getJson("/api/metas/{$this->orders}/data?per_page=100")->json();

        $this->getJson("/api/metas/{$this->schedule}/data?per_page=100")->assertOk()
            ->assertJsonPath('total', $base['total'])
            ->assertJsonPath('footer.total_amount', $base['footer']['total_amount'])
            ->assertJsonPath('data.0.status_id', $base['data'][0]['status_id']);

        // Its own filter: the expected date became "Due" and a filter.
        $due = DB::table('proc_purchase_orders')->value('expected_date');
        $this->getJson("/api/metas/{$this->schedule}/data?filter[expected_date]={$due}")->assertOk()
            ->assertJsonPath('total', DB::table('proc_purchase_orders')->whereDate('expected_date', $due)->count());
    }

    public function test_effective_params_and_actions_merge_while_the_builder_sees_its_own(): void
    {
        $effective = collect($this->getJson("/api/metas/{$this->schedule}/params?effective=1")->assertOk()->json())->keyBy('field_path');
        $this->assertSame('Due', $effective['expected_date']['label_name']);
        $this->assertFalse($effective['order_date']['is_show']);
        $this->assertSame('PO number', $effective['po_number']['label_name']);
        $this->assertSame('sum', $effective['total_amount']['json_config']['aggregate']);

        $this->assertCount(2, $this->getJson("/api/metas/{$this->schedule}/params")->json());
        $this->assertSame([], $this->getJson("/api/metas/{$this->schedule}/actions")->json());
        $this->assertSame(
            collect($this->getJson("/api/metas/{$this->orders}/actions")->json())->pluck('id')->all(),
            collect($this->getJson("/api/metas/{$this->schedule}/actions?effective=1")->json())->pluck('id')->all(),
        );
    }

    public function test_a_change_to_the_base_reaches_the_grids_that_reuse_it(): void
    {
        MetaParam::where('main_meta_id', $this->orders)->where('field_path', 'supplier_name')->update(['label_name' => 'Vendor']);

        $effective = collect($this->getJson("/api/metas/{$this->schedule}/params?effective=1")->json())->keyBy('field_path');
        $this->assertSame('Vendor', $effective['supplier_name']['label_name']);
    }

    public function test_grids_that_reuse_each_other_are_refused(): void
    {
        Meta::whereKey($this->orders)->update(['ref_meta_id' => $this->schedule]);

        $this->getJson("/api/metas/{$this->schedule}/data")->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'reuses it back'));
    }
}
