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

/** The order form's lines have lines of their own: each order line's deliveries. */
class NestedDetailsTest extends TestCase
{
    use RefreshDatabase;

    private int $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_order_form')->value('id');
    }

    private function order(array $lines): array
    {
        return ['supplier_id' => DB::table('proc_suppliers')->value('id'), 'order_date' => '2026-10-21', 'lines' => $lines];
    }

    private function line(string $item, int $quantity, array $deliveries = []): array
    {
        return ['item_id' => DB::table('proc_items')->where('code', $item)->value('id'), 'quantity' => $quantity, 'unit_price' => 1000, 'deliveries' => $deliveries];
    }

    public function test_lines_save_and_load_with_their_own_lines_and_totals(): void
    {
        $order = $this->postJson("/api/metas/{$this->form}/data", $this->order([
            $this->line('IT-002', 5, [['delivery_date' => '2026-10-25', 'quantity' => 2], ['delivery_date' => '2026-10-28', 'quantity' => 1, 'note' => 'Last box']]),
            $this->line('OF-002', 10),
        ]))->assertCreated()
            ->assertJsonCount(2, 'lines.0.deliveries')
            ->assertJsonPath('lines.0.deliveries.1.note', 'Last box')
            ->assertJsonPath('lines.0.delivered_quantity', 3)
            ->assertJsonPath('lines.1.deliveries', [])
            ->assertJsonPath('total_amount', fn ($v) => (float) $v === 15000.0)
            ->json();

        $this->getJson("/api/metas/{$this->form}/data/{$order['id']}")->assertOk()->assertJsonCount(2, 'lines.0.deliveries');
    }

    public function test_lines_of_lines_load_in_one_query_however_many_lines(): void
    {
        $lines = array_map(fn (int $n) => $this->line('IT-002', $n, [['delivery_date' => '2026-10-25', 'quantity' => 1]]), range(1, 6));
        $id = $this->postJson("/api/metas/{$this->form}/data", $this->order($lines))->assertCreated()->json('id');

        DB::enableQueryLog();
        $this->getJson("/api/metas/{$this->form}/data/{$id}")->assertOk()
            ->assertJsonCount(6, 'lines')
            ->assertJsonPath('lines.5.deliveries.0.quantity', fn ($v) => (float) $v === 1.0);
        $deliveryQueries = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from ["`]?proc_order_line_deliveries/', $q['query']));

        $this->assertCount(1, $deliveryQueries);
    }

    public function test_changing_lines_keeps_changes_and_removes_dropped_lines_with_their_own(): void
    {
        $order = $this->postJson("/api/metas/{$this->form}/data", $this->order([
            $this->line('IT-002', 5, [['delivery_date' => '2026-10-25', 'quantity' => 2], ['delivery_date' => '2026-10-28', 'quantity' => 1]]),
            $this->line('OF-002', 10, [['delivery_date' => '2026-10-26', 'quantity' => 10]]),
        ]))->json();
        $first = $order['lines'][0];
        $droppedLine = $order['lines'][1]['id'];

        // Keep line 1 and its first delivery (now 4), add a delivery, drop line 2.
        $this->putJson("/api/metas/{$this->form}/data/{$order['id']}", ['lines' => [
            ['id' => $first['id']] + $this->line('IT-002', 5, [
                ['id' => $first['deliveries'][0]['id'], 'delivery_date' => '2026-10-25', 'quantity' => 4],
                ['delivery_date' => '2026-10-30', 'quantity' => 1],
            ]),
        ]])->assertOk()
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.deliveries.0.id', $first['deliveries'][0]['id'])
            ->assertJsonPath('lines.0.delivered_quantity', 5);

        $this->assertDatabaseMissing('proc_order_line_deliveries', ['id' => $first['deliveries'][1]['id']]);
        $this->assertSame(0, DB::table('proc_order_line_deliveries')->where('order_line_id', $droppedLine)->count());
    }

    public function test_lines_of_lines_are_validated_where_they_sit(): void
    {
        $this->postJson("/api/metas/{$this->form}/data", $this->order([
            $this->line('IT-002', 5, [['quantity' => 0]]),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['lines.0.deliveries.0.delivery_date', 'lines.0.deliveries.0.quantity']);
    }

    public function test_deleting_the_record_removes_every_level_of_lines(): void
    {
        $order = $this->postJson("/api/metas/{$this->form}/data", $this->order([
            $this->line('IT-002', 5, [['delivery_date' => '2026-10-25', 'quantity' => 2]]),
        ]))->json();

        $this->deleteJson("/api/metas/{$this->form}/data/{$order['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('proc_order_line_deliveries')->count());
    }

    public function test_a_form_cannot_be_a_line_of_itself(): void
    {
        $lineForm = Meta::where('code', 'proc_order_line_form')->value('id');
        MetaParam::create([
            'main_meta_id' => Meta::where('code', 'proc_order_line_delivery_form')->value('id'), 'param_name' => 'back', 'field_path' => 'back',
            'data_type' => 'detail', 'ref_meta_id' => $lineForm, 'order_number' => 9, 'json_config' => ['foreign_key' => 'order_line_id'],
        ]);

        $this->getJson("/api/metas/{$this->form}/data/".DB::table('proc_purchase_orders')->value('id'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The detail "back" uses a form that is already above it.');
    }
}
