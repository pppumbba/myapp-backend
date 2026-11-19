<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use App\Support\Consolidate;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Picked purchase requests become one purchase order: a line per item, quantities added up. */
class ConsolidateTest extends TestCase
{
    use RefreshDatabase;

    private Meta $grid;

    private int $form;

    /** @var list<int> */
    private array $requests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->grid = Meta::where('code', 'proc_requests_grid')->first();
        $this->form = Meta::where('code', 'proc_order_form')->value('id');
        $lineForm = Meta::where('code', 'proc_order_line_form')->value('id');

        MetaParam::where('main_meta_id', $lineForm)->where('field_path', 'item_id')->update(['group_type' => 'group']);
        MetaParam::where('main_meta_id', $lineForm)->where('field_path', 'quantity')->update(['group_type' => 'sum']);
        MetaParam::where('main_meta_id', $lineForm)->where('field_path', 'description')->update(['group_type' => 'concat']);
        MetaParam::where('main_meta_id', $this->form)->where('field_path', 'expected_date')->update(['group_type' => 'min']);
        MetaParam::where('main_meta_id', $this->form)->where('field_path', 'notes')->update(['group_type' => 'concat']);

        $items = DB::table('proc_items')->orderBy('id')->limit(2)->pluck('id');
        $this->requests = collect([
            ['Chairs for IT', 'IT', $items[0], 2, '2026-12-10'],
            ['Chairs for HR', 'HR', $items[0], 3, '2026-12-01'],
            ['Paper', 'IT', $items[1], 5, '2026-12-20'],
        ])->map(fn ($r, $i) => DB::table('proc_purchase_requests')->insertGetId([
            'request_no' => 'CR-'.$i, 'title' => $r[0], 'department' => $r[1], 'item_id' => $r[2], 'quantity' => $r[3], 'needed_by' => $r[4], 'status' => 'approved',
        ]))->all();
    }

    private function button(?int $get = null): MetaAction
    {
        return MetaAction::create(['grid_meta_id' => $this->grid->id, 'action' => 'consolidate', 'label' => 'Make order', 'form_meta_id' => $this->form, 'get_meta_id' => $get, 'needs_row' => true]);
    }

    public function test_the_picked_rows_merge_into_lines_by_their_group_fields(): void
    {
        $action = $this->button();

        $record = $this->postJson("/api/metas/{$this->grid->id}/consolidate/{$action->id}", ['ids' => array_map('strval', $this->requests)])
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->json();

        $this->assertSame(5, $record['lines'][0]['quantity']);
        $this->assertSame(5, $record['lines'][1]['quantity']);
        $this->assertArrayNotHasKey('id', $record);
        $this->assertArrayNotHasKey('id', $record['lines'][0]);
    }

    public function test_a_get_gives_the_rows_for_each_picked_record(): void
    {
        $get = Meta::create(['code' => 'cr_get', 'name' => 'Request as order', 'type_id' => MetaType::where('code', 'get')->value('id'), 'is_active' => true,
            'query_string' => 'SELECT title AS description, item_id, quantity, needed_by AS expected_date, department AS notes FROM proc_purchase_requests WHERE id = :id']);
        $action = $this->button($get->id);

        $this->postJson("/api/metas/{$this->grid->id}/consolidate/{$action->id}", ['ids' => [(string) $this->requests[0], (string) $this->requests[1]]])
            ->assertOk()
            ->assertJsonPath('expected_date', '2026-12-01')
            ->assertJsonPath('notes', 'IT, HR')
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.quantity', 5)
            ->assertJsonPath('lines.0.description', 'Chairs for IT, Chairs for HR');
    }

    public function test_only_this_grids_consolidate_buttons_and_found_records_count(): void
    {
        $edit = MetaAction::where('grid_meta_id', $this->grid->id)->where('action', 'edit')->first();
        $this->postJson("/api/metas/{$this->grid->id}/consolidate/{$edit->id}", ['ids' => array_map('strval', $this->requests)])->assertNotFound();

        $action = $this->button();
        $this->postJson("/api/metas/{$this->grid->id}/consolidate/{$action->id}", ['ids' => ['999999']])->assertNotFound();
        $this->postJson("/api/metas/{$this->grid->id}/consolidate/{$action->id}", ['ids' => []])->assertUnprocessable();
    }

    public function test_group_types_add_up_values(): void
    {
        $this->assertSame(6, Consolidate::aggregate('sum', ['1', 2, '', null, '3']));
        $this->assertSame(2.0, Consolidate::aggregate('avg', [1, 3]));
        $this->assertSame(46666.67, Consolidate::aggregate('avg', [45000, 45000, 50000]));
        $this->assertSame('2026-01-02', Consolidate::aggregate('max', ['2026-01-01', '2026-01-02']));
        $this->assertSame(2, Consolidate::aggregate('count', ['a', '', 'b']));
        $this->assertSame('b', Consolidate::aggregate('last', ['a', 'b', null]));
    }
}
