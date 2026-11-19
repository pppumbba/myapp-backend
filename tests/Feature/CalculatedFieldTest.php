<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The order form works out each line's total and the order's total with tax, whatever is sent. */
class CalculatedFieldTest extends TestCase
{
    use RefreshDatabase;

    private int $form;

    private int $lineForm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_order_form')->value('id');
        $this->lineForm = Meta::where('code', 'proc_order_line_form')->value('id');

        Schema::table('proc_purchase_order_lines', fn (Blueprint $t) => $t->decimal('line_total', 14, 2)->nullable());
        Schema::table('proc_purchase_orders', fn (Blueprint $t) => $t->decimal('with_tax', 14, 2)->nullable());
        MetaParam::create(['main_meta_id' => $this->lineForm, 'field_path' => 'line_total', 'param_name' => 'line_total', 'label_name' => 'Line total', 'data_type' => 'decimal', 'is_show' => true, 'order_number' => 90, 'expression_string' => 'quantity * unit_price']);
        MetaParam::create(['main_meta_id' => $this->form, 'field_path' => 'with_tax', 'param_name' => 'with_tax', 'label_name' => 'Total with tax', 'data_type' => 'decimal', 'is_show' => true, 'order_number' => 90, 'expression_string' => 'round(sum(lines.line_total) * 1.1, 2)']);
    }

    private function line(int $quantity, float $price, array $extra = []): array
    {
        return ['item_id' => DB::table('proc_items')->value('id'), 'quantity' => $quantity, 'unit_price' => $price] + $extra;
    }

    public function test_a_save_works_out_lines_then_the_record_and_ignores_sent_values(): void
    {
        $order = $this->postJson("/api/metas/{$this->form}/data", [
            'supplier_id' => DB::table('proc_suppliers')->value('id'),
            'order_date' => '2026-11-15',
            'with_tax' => 1,
            'lines' => [$this->line(2, 1000, ['line_total' => 999]), $this->line(3, 500)],
        ])->assertCreated()
            ->assertJsonPath('lines.0.line_total', fn ($v) => (float) $v === 2000.0)
            ->assertJsonPath('lines.1.line_total', fn ($v) => (float) $v === 1500.0)
            ->assertJsonPath('with_tax', fn ($v) => (float) $v === 3850.0)
            ->json();

        // Changing only the record adds up the lines it already has.
        $this->putJson("/api/metas/{$this->form}/data/{$order['id']}", ['notes' => 'Call first'])
            ->assertOk()
            ->assertJsonPath('with_tax', fn ($v) => (float) $v === 3850.0);

        // Sending lines works them out again.
        $this->putJson("/api/metas/{$this->form}/data/{$order['id']}", ['lines' => [['id' => $order['lines'][0]['id']] + $this->line(10, 1000)]])
            ->assertOk()
            ->assertJsonPath('lines.0.line_total', fn ($v) => (float) $v === 10000.0)
            ->assertJsonPath('with_tax', fn ($v) => (float) $v === 11000.0);
    }

    public function test_a_formula_is_checked_when_the_field_is_saved(): void
    {
        $param = MetaParam::where('main_meta_id', $this->form)->where('field_path', 'with_tax')->first();

        $this->patchJson("/api/params/{$param->id}", ['expression_string' => 'sum(lines.cost)'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.expression_string.0', 'The lines of "lines" have no "cost".');
        $this->patchJson("/api/params/{$param->id}", ['expression_string' => 'sum(lines.line_total) - 100'])->assertOk();

        $line = MetaParam::where('main_meta_id', $this->lineForm)->where('field_path', 'line_total')->first();
        $this->patchJson("/api/params/{$line->id}", ['expression_string' => 'quantity * unit_price * parent.exchange'])->assertOk();
    }
}
