<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $requestForm;

    private int $orderForm;

    private int $itemForm;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->travelTo('2026-10-21 09:00:00');

        $this->requestForm = Meta::where('code', 'proc_request_form')->value('id');
        $this->orderForm = Meta::where('code', 'proc_order_form')->value('id');
        $this->itemForm = Meta::where('code', 'proc_item_form')->value('id');
        $this->supplier = DB::table('proc_suppliers')->value('id');
    }

    /** A request makes an order; an order whose notes mention chairs also makes an item. */
    private function saveFlow(array $orderCriteria = []): void
    {
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [
                ['key' => 'order', 'process_id' => $this->orderForm, 'behaviours' => [
                    ['source_path' => "={$this->supplier}", 'target_path' => 'supplier_id'],
                    ['source_path' => 'needed_by', 'target_path' => 'order_date'],
                    ['source_path' => 'title', 'target_path' => 'notes'],
                ]],
                ['key' => 'item', 'process_id' => $this->itemForm, 'behaviours' => [
                    ['source_path' => 'po_number', 'target_path' => 'code'],
                    ['source_path' => 'main.title', 'target_path' => 'name'],
                    ['source_path' => '=0', 'target_path' => 'unit_price'],
                ]],
            ],
            'transitions' => [
                ['source' => null, 'target' => 'order', 'criteria' => $orderCriteria],
                ['source' => 'order', 'target' => 'item', 'criteria' => [['field' => 'notes', 'op' => 'contains', 'value' => 'chair']]],
            ],
        ])->assertOk()->assertJsonCount(2, 'steps')->assertJsonCount(2, 'transitions');
    }

    private function request(string $title, array $values = []): TestResponse
    {
        return $this->postJson("/api/metas/{$this->requestForm}/data", $values + [
            'request_no' => 'PR-F-'.substr(md5($title), 0, 6), 'title' => $title, 'item_id' => DB::table('proc_items')->value('id'),
            'quantity' => 1, 'status' => 'draft', 'needed_by' => '2026-11-01',
        ]);
    }

    public function test_saving_the_main_form_runs_the_next_forms_with_their_values(): void
    {
        $this->saveFlow();

        $response = $this->request('Office chairs')->assertCreated()
            ->assertJsonPath('_processes.0.process', 'Purchase order form')
            ->assertJsonPath('_processes.1.process', 'Item form');

        $order = DB::table('proc_purchase_orders')->find($response->json('_processes.0.id'));
        $this->assertSame('PO-261021001', $order->po_number);
        $this->assertSame($this->supplier, $order->supplier_id);
        $this->assertSame('2026-11-01', $order->order_date);
        $this->assertSame('Office chairs', $order->notes);

        $item = DB::table('proc_items')->find($response->json('_processes.1.id'));
        $this->assertSame('PO-261021001', $item->code);
        $this->assertSame('Office chairs', $item->name);
    }

    public function test_a_transition_whose_criteria_fail_stops_there(): void
    {
        $this->saveFlow([['field' => 'department', 'op' => '=', 'value' => 'IT']]);

        $this->request('Laptops', ['department' => 'HR'])->assertCreated()->assertJsonMissingPath('_processes');
        $this->assertSame(0, DB::table('proc_purchase_orders')->where('notes', 'Laptops')->count());

        // The order runs, but its notes don't mention chairs, so no item is made.
        $this->request('Laptops for IT', ['department' => 'IT'])->assertCreated()->assertJsonCount(1, '_processes');
    }

    public function test_a_step_that_refuses_undoes_the_whole_save(): void
    {
        $this->saveFlow();
        $items = DB::table('proc_items')->count();

        $this->request('No date', ['needed_by' => null])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Purchase order form: '));

        $this->assertSame(0, DB::table('proc_purchase_requests')->where('title', 'No date')->count());
        $this->assertSame($items, DB::table('proc_items')->count());
    }

    public function test_a_get_step_reads_a_record_and_later_steps_take_values_from_any_earlier_step(): void
    {
        $get = Meta::where('code', 'proc_order_get')->value('id');
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'main_at' => ['x' => 20, 'y' => 40],
            'steps' => [
                ['key' => 'order', 'process_id' => $this->orderForm, 'x' => 300, 'y' => 40, 'behaviours' => [
                    ['source_path' => "={$this->supplier}", 'target_path' => 'supplier_id'],
                    ['source_path' => 'main.needed_by', 'target_path' => 'order_date'],
                    ['source_path' => 'main.title', 'target_path' => 'notes'],
                ]],
                ['key' => 'load', 'process_id' => $get, 'behaviours' => [['source_path' => 'step:order.id', 'target_path' => 'id']]],
                ['key' => 'item', 'process_id' => $this->itemForm, 'behaviours' => [
                    ['source_path' => 'step:load.po_number', 'target_path' => 'code'],
                    ['source_path' => 'step:order.notes', 'target_path' => 'name'],
                    ['source_path' => '=0', 'target_path' => 'unit_price'],
                ]],
            ],
            'transitions' => [['source' => 'order', 'target' => 'load'], ['source' => 'load', 'target' => 'item']],
        ])->assertOk()
            ->assertJsonPath('main_at', ['x' => 20, 'y' => 40])
            ->assertJsonPath('steps.0.pos_x', 300)
            ->assertJsonPath('steps.1.process.type.code', 'get')
            ->assertJsonPath('steps.2.behaviours.0.source_path', 'step1.po_number');

        $response = $this->request('Desks')->assertCreated()->assertJsonCount(3, '_processes');

        $item = DB::table('proc_items')->find($response->json('_processes.2.id'));
        $this->assertSame('PO-261021001', $item->code);
        $this->assertSame('Desks', $item->name);
    }

    public function test_ports_list_what_each_meta_takes_in_and_passes_on(): void
    {
        $get = Meta::where('code', 'proc_order_get')->value('id');

        $ports = $this->getJson("/api/metas/{$this->requestForm}/process-flow/ports?ids={$this->orderForm},{$get}")->assertOk()->json();

        $fields = fn (array $list) => array_column($list, 'field');
        $this->assertContains('title', $fields($ports[$this->requestForm]['outputs']));
        $this->assertContains('id', $fields($ports[$this->orderForm]['outputs']));
        $this->assertNotContains('id', $fields($ports[$this->orderForm]['inputs']));
        $this->assertContains('supplier_id', $fields($ports[$this->orderForm]['inputs']));
        $this->assertSame(['id'], $fields($ports[$get]['inputs']));
        $this->assertContains('po_number', $fields($ports[$get]['outputs']));
    }

    public function test_the_builder_refuses_a_value_from_a_step_not_in_the_flow(): void
    {
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $this->orderForm, 'behaviours' => [['source_path' => 'step:ghost.id', 'target_path' => 'notes']]]],
            'transitions' => [],
        ])->assertUnprocessable();
    }

    public function test_the_builder_refuses_a_form_running_itself_or_a_loose_transition(): void
    {
        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $this->requestForm]],
            'transitions' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('steps.0.process_id');

        $this->putJson("/api/metas/{$this->requestForm}/process-flow", [
            'steps' => [['key' => 'a', 'process_id' => $this->orderForm]],
            'transitions' => [['source' => 'a', 'target' => 'nowhere']],
        ])->assertUnprocessable();
    }
}
