<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\User;
use App\Support\Documents;
use Database\Seeders\ProcurementDemoSeeder;
use Database\Seeders\ProcurementReportsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A form's document field: its report filled in on the form, and the PDF its last save printed. */
class DocumentFieldTest extends TestCase
{
    use RefreshDatabase;

    private Meta $form;

    private Meta $report;

    private MetaParam $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ProcurementDemoSeeder::class, ProcurementReportsSeeder::class]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_order_form')->firstOrFail();
        $this->report = Meta::where('code', 'proc_order_report')->firstOrFail();
        $this->report->update(['json_config' => array_merge($this->report->json_config ?? [], ['layout' => Documents::normalize([
            'sections' => [
                ['kind' => 'free', 'id' => 'top', 'height' => 20, 'elements' => [
                    ['id' => 'supplier', 'type' => 'field', 'field' => 'supplier_id', 'x' => 0, 'y' => 0, 'w' => 80, 'h' => 6],
                    ['id' => 'total', 'type' => 'text', 'text' => 'Total {lines.sum.amount|money}', 'x' => 0, 'y' => 8, 'w' => 80, 'h' => 6],
                ]],
                ['kind' => 'table', 'id' => 'items', 'detail' => 'lines', 'columns' => [
                    ['field' => 'item_id', 'label' => 'Item'],
                    ['field' => 'amount', 'label' => 'Amount', 'format' => 'number', 'total' => true],
                ]],
            ],
        ])])]);
        $this->document = MetaParam::create([
            'main_meta_id' => $this->form->id, 'order_number' => 50, 'field_path' => 'order_document', 'param_name' => 'order_document',
            'label_name' => 'Order document', 'data_type' => 'document', 'ref_meta_id' => $this->report->id, 'is_show' => true,
        ]);
    }

    private function order(array $more = []): array
    {
        return array_merge([
            'supplier_id' => DB::table('proc_suppliers')->value('id'),
            'order_date' => '2026-10-26',
            'lines' => [['item_id' => DB::table('proc_items')->where('code', 'IT-002')->value('id'), 'quantity' => 3, 'unit_price' => 1250]],
        ], $more);
    }

    public function test_the_form_sees_its_unsaved_values_on_the_page_as_they_will_print(): void
    {
        $shown = $this->postJson("/api/metas/{$this->form->id}/documents/{$this->document->id}", ['values' => $this->order()])
            ->assertOk()
            ->assertJsonPath('report_meta_id', $this->report->id)
            ->json('shown');

        $this->assertSame(DB::table('proc_suppliers')->value('name'), $shown['supplier']);
        $this->assertSame('Total 3,750.00 ₮', $shown['total']);
        $this->assertSame([[DB::table('proc_items')->where('code', 'IT-002')->value('name'), '3,750.00']], $shown['items']['rows']);
        $this->assertSame([null, '3,750.00'], $shown['items']['totals']);

        $other = MetaParam::where('main_meta_id', $this->form->id)->where('field_path', 'notes')->firstOrFail();
        $this->postJson("/api/metas/{$this->form->id}/documents/{$other->id}", ['values' => []])->assertNotFound();
        // A user who may only view the form cannot fill it in.
        $role = Role::create(['code' => 'viewers', 'name' => 'Viewers']);
        MetaPermission::create(['meta_id' => $this->form->id, 'role_id' => $role->id, 'can_view' => true]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/metas/{$this->form->id}/documents/{$this->document->id}", ['values' => []])->assertForbidden();
    }

    public function test_each_save_keeps_the_pdf_it_prints_in_place_of_the_last(): void
    {
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['order_document' => 999]))->assertCreated()->json();
        $first = $order['order_document'];
        $this->assertSame("Purchase order {$order['po_number']}.pdf", $first['file_name']);
        $this->assertStringStartsWith('%PDF', $this->get("/api/contents/{$first['id']}")->assertOk()->streamedContent());

        $second = $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order(['notes' => 'Again']))->assertOk()->json('order_document');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseMissing('contents', ['id' => $first['id']]);
        $this->assertSame(1, DB::table('content_map')->where('param_id', $this->document->id)->count());

        $this->document->update(['json_config' => ['keep_pdf' => false]]);
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order())->assertOk()->assertJsonPath('order_document.id', $second['id']);
    }
}
