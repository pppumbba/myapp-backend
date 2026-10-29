<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueryGridTest extends TestCase
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

    public function test_query_grid_binds_named_parameters_from_filters(): void
    {
        $grid = $this->metaId('proc_orders_grid');

        $this->getJson("/api/metas/{$grid}/data")
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonStructure(['data' => [['po_number', 'supplier_name', 'status_id']]]);

        $sent = DB::table('wfm_status')->where('code', 'sent')->value('id');
        $this->getJson("/api/metas/{$grid}/data?filter[status_id]={$sent}")
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson("/api/metas/{$grid}/data?filter[status_id]={$sent}&filter[supplier_name]=northwind")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.po_number', 'PO-2026-0102')
            ->assertJsonPath('data.0.supplier_name', 'Northwind Electronics');

        $this->getJson("/api/metas/{$grid}/data?filter[from_date]=2026-10-12&filter[to_date]=2026-10-16")
            ->assertOk()
            ->assertJsonPath('total', 2);

        // supplier_name is a column, not a named parameter: it filters with "contains".
        $this->getJson("/api/metas/{$grid}/data?filter[supplier_name]=baltic")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_named_parameter_values_cannot_change_the_query(): void
    {
        $grid = $this->metaId('proc_orders_grid');

        $this->getJson("/api/metas/{$grid}/data?filter[status_id]=".urlencode('1 OR 1=1'))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_subgrid_waits_for_its_mandatory_parameter(): void
    {
        $grid = $this->metaId('proc_supplier_orders_grid');
        $supplier = DB::table('proc_suppliers')->where('name', 'Northwind Electronics')->value('id');

        $this->getJson("/api/metas/{$grid}/data")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('missing_filters.0', 'Supplier');

        $this->getJson("/api/metas/{$grid}/data?filter[supplier_id]={$supplier}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissingPath('missing_filters');
    }

    public function test_get_meta_fills_a_form(): void
    {
        $order = DB::table('proc_purchase_orders')->where('po_number', 'PO-2026-0103')->value('id');

        $this->getJson("/api/metas/{$this->metaId('proc_order_get')}/fill?params[id]={$order}")
            ->assertOk()
            ->assertJsonPath('po_number', 'PO-2026-0103');

        $this->getJson("/api/metas/{$this->metaId('proc_order_new_get')}/fill")
            ->assertOk()
            ->assertJsonPath('order_date', now()->toDateString());

        $this->getJson("/api/metas/{$this->metaId('proc_orders_grid')}/fill")->assertStatus(422);
    }

    public function test_broken_query_says_why(): void
    {
        $grid = Meta::find($this->metaId('proc_orders_grid'));
        $grid->update(['query_string' => 'SELECT * FROM no_such_table']);

        $this->getJson("/api/metas/{$grid->id}/data")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'The query could not run:'));
    }

    public function test_grid_toolbar_actions_can_be_managed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $grid = $this->metaId('proc_items_grid');
        $form = $this->metaId('proc_item_form');

        $this->getJson("/api/metas/{$grid}/actions")
            ->assertOk()
            // Add, Edit, Delete, and Import from ProcurementImportSeeder.
            ->assertJsonCount(4)
            ->assertJsonPath('0.action', 'add')
            ->assertJsonPath('1.form.id', $form);

        $id = $this->postJson("/api/metas/{$grid}/actions", [
            'action' => 'custom', 'label' => 'Duplicate', 'form_meta_id' => $form, 'needs_row' => true,
            'json_config' => ['params' => ['id' => 'id']],
        ])->assertCreated()->assertJsonPath('order_number', 11)->assertJsonPath('show_in_toolbar', true)->json('id');

        $this->putJson("/api/actions/{$id}", ['label' => 'Copy'])->assertOk()->assertJsonPath('label', 'Copy');
        // Only on each row, not in the toolbar.
        $this->putJson("/api/actions/{$id}", ['show_in_toolbar' => false, 'show_in_row' => true])
            ->assertOk()->assertJsonPath('show_in_toolbar', false)->assertJsonPath('show_in_row', true);
        $this->postJson("/api/metas/{$grid}/actions", ['action' => 'explode', 'label' => 'X', 'form_meta_id' => $form])
            ->assertUnprocessable()->assertJsonValidationErrors(['action']);

        $this->deleteJson("/api/actions/{$id}")->assertNoContent();
        $this->assertSame(4, MetaAction::where('grid_meta_id', $grid)->count());
    }
}
