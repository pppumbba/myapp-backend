<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaDataTest extends TestCase
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

    public function test_grid_lists_only_its_columns_and_searches_criteria(): void
    {
        $grid = $this->metaId('proc_suppliers_grid');

        $this->getJson("/api/metas/{$grid}/data")
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonMissingPath('data.0.phone');

        $this->getJson("/api/metas/{$grid}/data?search=Northwind")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Northwind Electronics');
    }

    public function test_form_creates_updates_and_deletes_a_record(): void
    {
        $form = $this->metaId('proc_item_form');

        $id = $this->postJson("/api/metas/{$form}/data", [
            'code' => 'TS-001', 'name' => 'Test item', 'unit' => 'box', 'unit_price' => 1500,
        ])->assertCreated()->json('id');

        $this->putJson("/api/metas/{$form}/data/{$id}", ['code' => 'TS-001', 'name' => 'Renamed', 'unit' => 'box', 'unit_price' => 2000])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');

        $this->deleteJson("/api/metas/{$form}/data/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('proc_items', ['id' => $id]);
    }

    public function test_form_validates_from_its_params(): void
    {
        $form = $this->metaId('proc_request_form');

        $this->postJson("/api/metas/{$form}/data", [
            'request_no' => 'PR-2026-001', // already taken
            'item_id' => 999999,           // not an item
            'quantity' => 0,               // below min_value 1
            'status' => 'maybe',           // not in options
        ])->assertUnprocessable()->assertJsonValidationErrors(['request_no', 'title', 'item_id', 'quantity']);

        $this->postJson("/api/metas/{$form}/data", [
            'request_no' => 'PR-2026-900', 'title' => 'Test', 'item_id' => 1, 'quantity' => 1, 'status' => 'maybe',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_system_tables_are_refused(): void
    {
        $meta = Meta::create([
            'code' => 'sneaky', 'name' => 'Sneaky', 'table_name' => 'users',
            'type_id' => MetaType::where('code', 'datagrid')->value('id'),
        ]);

        $this->getJson("/api/metas/{$meta->id}/data")->assertStatus(422);
    }

    public function test_order_saves_with_its_lines_and_totals_them(): void
    {
        $form = $this->metaId('proc_order_form');
        $supplier = \DB::table('proc_suppliers')->value('id');
        $item = \DB::table('proc_items')->pluck('id', 'code');

        $order = $this->postJson("/api/metas/{$form}/data", [
            'po_number' => 'PO-TEST-1', 'supplier_id' => $supplier, 'order_date' => '2026-10-20', 'status' => 'draft',
            'lines' => [
                ['item_id' => $item['IT-002'], 'quantity' => 2, 'unit_price' => 780000],
                ['item_id' => $item['OF-002'], 'description' => 'Blue', 'quantity' => 10, 'unit_price' => 8500],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('total_amount', fn ($v) => (float) $v === 1645000.0)
            ->json();

        // Keep the first line with a new quantity, drop the second, add a third.
        $first = $order['lines'][0]['id'];
        $this->putJson("/api/metas/{$form}/data/{$order['id']}", [
            'lines' => [
                ['id' => $first, 'item_id' => $item['IT-002'], 'quantity' => 3, 'unit_price' => 780000],
                ['item_id' => $item['CL-001'], 'quantity' => 5, 'unit_price' => 12000],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.0.id', $first)
            ->assertJsonPath('total_amount', fn ($v) => (float) $v === 2400000.0);

        $this->getJson("/api/metas/{$form}/data/{$order['id']}")->assertOk()->assertJsonCount(2, 'lines');

        $this->deleteJson("/api/metas/{$form}/data/{$order['id']}")->assertNoContent();
        $this->assertDatabaseMissing('proc_purchase_order_lines', ['purchase_order_id' => $order['id']]);
    }

    public function test_order_lines_are_validated(): void
    {
        $form = $this->metaId('proc_order_form');
        $supplier = \DB::table('proc_suppliers')->value('id');

        $this->postJson("/api/metas/{$form}/data", [
            'po_number' => 'PO-TEST-2', 'supplier_id' => $supplier, 'order_date' => '2026-10-20', 'status' => 'draft',
            'lines' => [['item_id' => 999999, 'quantity' => 0, 'unit_price' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.item_id', 'lines.0.quantity']);

        $this->assertDatabaseMissing('proc_purchase_orders', ['po_number' => 'PO-TEST-2']);
    }

    public function test_deleting_a_record_still_in_use_is_refused(): void
    {
        $form = $this->metaId('proc_supplier_form');
        $usedSupplier = \DB::table('proc_purchase_orders')->value('supplier_id');

        $this->deleteJson("/api/metas/{$form}/data/{$usedSupplier}")->assertStatus(422);
    }
}
