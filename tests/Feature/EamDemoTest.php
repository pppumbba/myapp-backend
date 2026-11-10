<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\EamDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EamDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EamDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    private function assetId(string $name): int
    {
        return DB::table('eam_assets')->where('name', $name)->value('id');
    }

    public function test_seeding_again_keeps_the_records_and_rebuilds_the_metas(): void
    {
        $assets = DB::table('eam_assets')->count();
        $this->seed(EamDemoSeeder::class);

        $this->assertSame($assets, DB::table('eam_assets')->count());
        $this->assertSame(1, Meta::where('code', 'eam_app')->count());
        $this->assertSame(1, DB::table('meta_process_workflow')->where('main_process_id', $this->metaId('eam_transfer_form'))->count());
    }

    public function test_a_work_order_gets_a_number_and_its_parts_add_up_to_its_cost(): void
    {
        $res = $this->postJson('/api/metas/'.$this->metaId('eam_work_order_form').'/data', [
            'asset_id' => $this->assetId('Forklift'),
            'title' => 'Hydraulic leak',
            'type' => 'corrective',
            'priority' => 'high',
            'status' => 'open',
            'labor_cost' => 90000,
            'parts' => [
                ['part_name' => 'Hose', 'quantity' => 2, 'unit_cost' => 50000],
                ['part_name' => 'Seal kit', 'quantity' => 1, 'unit_cost' => 30000],
            ],
        ])->assertCreated();

        $order = DB::table('eam_work_orders')->find($res->json('id'));
        $this->assertMatchesRegularExpression('/^WO-\d{4}\d{4}$/', $order->wo_no);
        $this->assertEquals(130000, $order->parts_cost);
        $this->assertEquals(220000, $order->total_cost);
        // Not raised from a plan, so no step ran.
        $this->assertSame([], $res->json('_processes') ?? []);
    }

    public function test_a_work_order_keeps_its_photos_and_the_grid_lists_them(): void
    {
        Storage::fake('local');
        $form = $this->metaId('eam_work_order_form');
        $param = MetaParam::where('main_meta_id', $form)->where('field_path', 'attachments')->firstOrFail();
        $upload = fn (string $name) => $this->post("/api/metas/{$form}/files", ['param_id' => $param->id, 'file' => UploadedFile::fake()->create($name, 200)], ['Accept' => 'application/json']);

        $upload('notes.txt')->assertJsonValidationErrors('file');
        $photo = $upload('leak.jpg')->assertCreated()->json();

        $res = $this->postJson("/api/metas/{$form}/data", [
            'asset_id' => $this->assetId('Forklift'),
            'title' => 'Hydraulic leak',
            'type' => 'corrective',
            'priority' => 'high',
            'status' => 'open',
            'attachments' => [['id' => $photo['id'], 'description' => 'Under the mast']],
        ])->assertCreated();

        $row = collect($this->getJson('/api/metas/'.$this->metaId('eam_work_orders_grid').'/data?per_page=200')->json('data'))
            ->firstWhere('id', $res->json('id'));
        $this->assertSame('leak.jpg', $row['attachments'][0]['file_name']);
        $this->assertSame('Under the mast', $row['attachments'][0]['description']);
    }

    public function test_an_asset_audit_is_in_the_menu_and_saves_its_counted_assets_as_lines(): void
    {
        $grid = $this->metaId('eam_audits_grid');
        $assetsGroup = DB::table('meta_params')->where('main_meta_id', $this->metaId('eam_app'))->whereNull('parent_id')->where('field_path', 'assets')->value('id');
        $this->assertSame($assetsGroup, DB::table('meta_params')->where('ref_meta_id', $grid)->where('field_path', 'asset_audits')->value('parent_id'));
        // The line's asset is a lookup, so the lines get a Choose button that picks many.
        $this->assertSame($this->metaId('eam_assets_lookup'), (int) DB::table('meta_params')->where('main_meta_id', $this->metaId('eam_audit_line_form'))->where('field_path', 'asset_id')->where('is_select', true)->value('ref_meta_id'));

        $res = $this->postJson('/api/metas/'.$this->metaId('eam_audit_form').'/data', [
            'audit_date' => '2026-11-10',
            'location_id' => DB::table('eam_locations')->where('code', 'PLT-1')->value('id'),
            'status' => 'open',
            'lines' => [
                ['asset_id' => $this->assetId('Forklift'), 'found' => true, 'condition' => 'good'],
                ['asset_id' => $this->assetId('Core switch'), 'found' => false],
            ],
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^AUD-\d{4}\d{3}$/', DB::table('eam_audits')->where('id', $res->json('id'))->value('audit_no'));
        $this->assertSame(2, DB::table('eam_audit_lines')->where('audit_id', $res->json('id'))->count());
        $this->assertSame(1, DB::table('eam_audit_lines')->where('audit_id', $res->json('id'))->where('found', false)->count());
    }

    public function test_saving_a_transfer_moves_the_asset(): void
    {
        $laptop = $this->assetId('Laptop – finance lead');
        $plant = DB::table('eam_locations')->where('code', 'PLT-1')->value('id');

        $this->postJson('/api/metas/'.$this->metaId('eam_transfer_form').'/data', [
            'asset_id' => $laptop,
            'to_location_id' => $plant,
            'moved_on' => now()->toDateString(),
            'assigned_to' => 'Enkhbold',
        ])->assertCreated();

        $asset = DB::table('eam_assets')->find($laptop);
        $this->assertSame($plant, (int) $asset->location_id);
        $this->assertSame('Enkhbold', $asset->assigned_to);
    }

    public function test_pm_due_lists_plans_due_by_today_without_open_work(): void
    {
        $titles = collect($this->getJson('/api/metas/'.$this->metaId('eam_pm_due_grid').'/data?per_page=100')->assertOk()->json('data'))->pluck('title')->all();

        // Due two and ten days ago, and today; the packaging line has open work and is due later anyway.
        $this->assertEqualsCanonicalizing(['Oil and filter change', 'Filter and separator', 'Battery and mast check'], $titles);
    }

    public function test_completing_a_work_order_closes_it_and_puts_the_asset_back_in_service(): void
    {
        $truck = $this->assetId('Delivery truck 2');
        $order = DB::table('eam_work_orders')->where('asset_id', $truck)->where('status', 'in progress')->value('id');

        $this->postJson('/api/metas/'.$this->metaId('eam_complete_work_order').'/run', ['params' => ['work_order_id' => $order]])->assertOk();

        $this->assertSame('completed', DB::table('eam_work_orders')->where('id', $order)->value('status'));
        $this->assertSame(now()->toDateString(), DB::table('eam_work_orders')->where('id', $order)->value('completed_on'));
        $this->assertSame('in service', DB::table('eam_assets')->where('id', $truck)->value('status'));
    }

    public function test_the_asset_workspace_sections_keep_to_one_asset(): void
    {
        $truck = $this->assetId('Delivery truck 1');
        $grid = $this->metaId('eam_asset_work_orders_grid');

        $rows = $this->getJson("/api/metas/{$grid}/data?scope[asset_id]={$truck}&per_page=100")->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame([$truck], array_values(array_unique(array_map('intval', array_column($rows, 'asset_id')))));
    }

    public function test_the_overview_charts_read(): void
    {
        $this->getJson('/api/metas/'.$this->metaId('eam_chart_in_service').'/chart')->assertOk();
        $this->getJson('/api/metas/'.$this->metaId('eam_chart_work_by_status').'/chart')->assertOk();
        $this->getJson('/api/metas/'.$this->metaId('eam_chart_cost_by_asset').'/chart')->assertOk();
    }
}
