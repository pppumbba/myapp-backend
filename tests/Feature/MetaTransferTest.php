<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Metas exported to a file and imported back: new, over the same codes, as copies, or only tried. */
class MetaTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ProcurementDemoSeeder::class]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function meta(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    private function export(array $codes, bool $withUsed = false): array
    {
        $ids = array_map(fn ($code) => $this->meta($code)->id, $codes);
        $response = $this->get('/api/meta-transfer/export?'.http_build_query(['ids' => $ids, 'with_used' => $withUsed ? 1 : 0]))->assertOk();
        $this->assertStringContainsString('.metas.json', $response->headers->get('Content-Disposition'));

        return json_decode($response->streamedContent(), true);
    }

    private function import(array $package, string $existing, bool $dryRun = false): array
    {
        return $this->postJson('/api/meta-transfer/import', ['package' => $package, 'existing' => $existing, 'dry_run' => $dryRun])->assertOk()->json();
    }

    public function test_a_deleted_meta_comes_back_from_its_file_with_its_parts_pointing_at_each_other(): void
    {
        $flow = $this->meta('proc_order_workflow');
        $statuses = DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->orderBy('id')->pluck('code')->all();
        $package = $this->export(['proc_order_workflow']);
        $this->assertSame('myapp-metas', $package['format']);
        $this->assertCount(1, $package['metas']);
        $this->deleteJson("/api/metas/{$flow->id}")->assertSuccessful();

        $result = $this->import($package, 'replace');
        $this->assertSame('create', $result['metas'][0]['action']);
        $new = $result['metas'][0]['id'];
        $this->assertNotSame($flow->id, $new);
        $this->assertSame($statuses, DB::table('wfm_status')->where('workflow_meta_id', $new)->orderBy('id')->pluck('code')->all());
        $ids = DB::table('wfm_status')->where('workflow_meta_id', $new)->pluck('id');
        foreach (DB::table('wfm_transition')->where('workflow_meta_id', $new)->get() as $transition) {
            $this->assertContains($transition->next_status_id, $ids);
        }
    }

    public function test_metas_with_what_they_use_import_as_copies_that_point_at_each_other(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $package = $this->export(['proc_orders_grid'], withUsed: true);
        $codes = array_column(array_column($package['metas'], 'meta'), 'code');
        $this->assertSame('proc_orders_grid', $codes[0]);
        $this->assertContains('proc_order_form', $codes);
        $before = DB::table('metas')->count();

        // A dry run says what would happen and keeps nothing.
        $tried = $this->import($package, 'copy', dryRun: true);
        $this->assertSame(array_fill(0, count($codes), 'copy'), array_column($tried['metas'], 'action'));
        $this->assertSame($before, DB::table('metas')->count());

        $result = $this->import($package, 'copy');
        $this->assertSame($before + count($codes), DB::table('metas')->count());
        $copy = $this->meta('proc_orders_grid_copy');
        $form = $this->meta('proc_order_form_copy');
        $this->assertSame(DB::table('meta_params')->where('main_meta_id', $grid->id)->count(), DB::table('meta_params')->where('main_meta_id', $copy->id)->count());
        // Its buttons open the copied form, and its links name its own copied fields.
        $this->assertContains($form->id, DB::table('meta_actions')->where('grid_meta_id', $copy->id)->pluck('form_meta_id')->map(fn ($id) => (int) $id)->all());
        foreach (DB::table('meta_meta_map')->where('src_meta_id', $copy->id)->whereNotNull('src_param_id')->get() as $link) {
            $this->assertSame($copy->id, (int) DB::table('meta_params')->where('id', $link->src_param_id)->value('main_meta_id'));
        }
        $this->assertContains('proc_orders_grid_copy', array_column($result['metas'], 'code'));
        $this->getJson("/api/metas/{$copy->id}/data")->assertOk();
    }

    public function test_replacing_keeps_ids_and_a_version_and_skipping_changes_nothing(): void
    {
        $form = $this->meta('proc_order_form');
        $package = $this->export(['proc_order_form']);
        $fields = DB::table('meta_params')->where('main_meta_id', $form->id)->orderBy('order_number')->orderBy('id')->pluck('field_path')->all();
        DB::table('meta_params')->where('main_meta_id', $form->id)->where('field_path', $fields[0])->update(['label_name' => 'Changed here']);

        $this->import($package, 'skip');
        $this->assertSame(1, DB::table('meta_params')->where('main_meta_id', $form->id)->where('label_name', 'Changed here')->count());

        $result = $this->import($package, 'replace');
        $this->assertSame(['replace', $form->id], [$result['metas'][0]['action'], $result['metas'][0]['id']]);
        $this->assertSame($fields, DB::table('meta_params')->where('main_meta_id', $form->id)->orderBy('order_number')->orderBy('id')->pluck('field_path')->all());
        $this->assertSame(0, DB::table('meta_params')->where('main_meta_id', $form->id)->where('label_name', 'Changed here')->count());
        $this->assertSame('Before importing it from a file', DB::table('meta_versions')->where('meta_id', $form->id)->orderByDesc('version_no')->value('note'));
    }

    public function test_replacing_a_workflow_reuses_the_statuses_records_have_been_in(): void
    {
        $flow = $this->meta('proc_order_workflow');
        $used = DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->orderBy('id')->first();
        DB::table('wfm_status_log')->insert(['workflow_meta_id' => $flow->id, 'record_id' => 1, 'status_id' => $used->id, 'created_at' => now()]);
        $count = DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->count();

        $this->import($this->export(['proc_order_workflow']), 'replace');
        $this->assertSame($count, DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->count());
        $this->assertSame($used->id, DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->where('code', $used->code)->value('id'));
    }

    public function test_what_this_app_lacks_is_reported_and_other_files_are_refused(): void
    {
        $package = $this->export(['proc_orders_grid']);
        $package['metas'][0]['parts']['permissions'][] = ['id' => 999999, 'meta_id' => $package['metas'][0]['meta']['id'], 'role_id' => 999999, 'can_view' => 1, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
        $package['refs']['roles'][999999] = 'no_such_role';
        $package['metas'][0]['meta']['table_name'] = 'no_such_table';

        $warnings = implode(' ', $this->import($package, 'copy')['warnings']);
        $this->assertStringContainsString('access for a role is left out', $warnings);
        $this->assertStringContainsString('no_such_table', $warnings);

        $this->postJson('/api/meta-transfer/import', ['package' => ['metas' => []], 'existing' => 'copy'])->assertStatus(422);
    }
}
