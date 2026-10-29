<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use App\Support\Xlsx;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Saves through metas with is_log on are kept in record_log: fields, lines, who and how. */
class RecordLogTest extends TestCase
{
    use RefreshDatabase;

    private Meta $form;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->user = User::factory()->admin()->create(['name' => 'Bat']);
        Sanctum::actingAs($this->user);
        $this->form = Meta::where('code', 'proc_order_form')->firstOrFail();
        $this->form->update(['is_log' => true]);
    }

    private function line(string $item, int $quantity, array $deliveries = []): array
    {
        return ['item_id' => DB::table('proc_items')->where('code', $item)->value('id'), 'quantity' => $quantity, 'unit_price' => 1000, 'deliveries' => $deliveries];
    }

    private function order(array $lines): array
    {
        return ['supplier_id' => DB::table('proc_suppliers')->value('id'), 'order_date' => '2026-10-21', 'lines' => $lines];
    }

    public function test_a_records_saves_are_logged_with_their_lines_and_who_made_them(): void
    {
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order([$this->line('IT-002', 5), $this->line('OF-002', 10)]))->assertCreated()->json();
        $first = $order['lines'][0];
        $second = $order['lines'][1];

        // One line's quantity changes and it gets a delivery; the other goes; a new one comes.
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order([
            ['id' => $first['id']] + $this->line('IT-002', 8, [['delivery_date' => '2026-10-25', 'quantity' => 2]]),
            $this->line('OF-001', 1),
        ]))->assertOk();

        $log = $this->getJson("/api/metas/{$this->form->id}/data/{$order['id']}/log")->assertOk()->json('data');
        $this->assertSame(['updated', 'created'], array_column($log, 'action'));
        $this->assertSame('Bat', $log[0]['user_name']);
        $this->assertSame('form', $log[0]['source']);

        $created = $log[1]['changes'];
        $this->assertCount(2, $created['lines']['lines']['added']);
        $this->assertSame([null, '2026-10-21'], $created['fields']['order_date']);

        $lines = $log[0]['changes']['lines']['lines'];
        $this->assertSame($first['id'], $lines['changed'][0]['id']);
        $this->assertSame([5, 8], array_map('intval', $lines['changed'][0]['fields']['quantity']));
        $this->assertCount(1, $lines['changed'][0]['lines']['deliveries']['added']);
        $this->assertSame($second['id'], $lines['removed'][0]['id']);
        $this->assertCount(1, $lines['added']);
        $this->assertArrayHasKey('total_amount', $log[0]['changes']['fields']);
    }

    public function test_a_save_that_changes_nothing_is_not_logged_and_a_delete_keeps_the_whole_record(): void
    {
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order([$this->line('IT-002', 5)]))->assertCreated()->json();
        $same = $this->order([['id' => $order['lines'][0]['id']] + $this->line('IT-002', 5)]);
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $same)->assertOk();
        $this->assertSame(1, DB::table('record_log')->count());

        $this->deleteJson("/api/metas/{$this->form->id}/data/{$order['id']}")->assertNoContent();
        $deleted = $this->getJson("/api/metas/{$this->form->id}/data/{$order['id']}/log")->json('data.0');
        $this->assertSame('deleted', $deleted['action']);
        $this->assertSame($order['id'], $deleted['snapshot']['id']);
        $this->assertCount(1, $deleted['snapshot']['lines']);
    }

    public function test_nothing_is_logged_without_is_log(): void
    {
        $this->form->update(['is_log' => false]);
        $this->postJson("/api/metas/{$this->form->id}/data", $this->order([$this->line('IT-002', 5)]))->assertCreated();

        $this->assertSame(0, DB::table('record_log')->count());
    }

    public function test_imports_are_logged_row_by_row_as_one_batch(): void
    {
        $import = Meta::where('code', 'proc_items_import')->firstOrFail();
        $import->update(['is_log' => true]);
        $old = DB::table('proc_items')->where('code', 'IT-001')->first();
        $file = UploadedFile::fake()->createWithContent('rows.xlsx', Xlsx::write('Sheet1', ['Item code', 'Name', 'unit price', 'Unit'], [
            ['IT-001', 'Laptop 15"', 2600000, 'PCS'],
            ['NEW-01', 'Stapler', 15000, null],
        ], ['string', 'string', 'decimal', 'string']));

        $this->post("/api/metas/{$import->id}/import", ['file' => $file])->assertOk();

        $rows = DB::table('record_log')->where('table_name', 'proc_items')->orderBy('id')->get();
        $this->assertSame(['updated', 'created'], $rows->pluck('action')->all());
        $this->assertSame(['import'], $rows->pluck('source')->unique()->values()->all());
        $this->assertCount(1, $rows->pluck('batch_id')->unique());
        $this->assertSame([$old->name, 'Laptop 15"'], json_decode($rows[0]->changes, true)['fields']['name']);
    }
}
