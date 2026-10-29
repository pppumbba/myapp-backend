<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaVersion;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A meta copied with all its parts, replaced with another, or made into a meta of another type. */
class MetaCopyTest extends TestCase
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

    private function params(int $metaId): array
    {
        return DB::table('meta_params')->where('main_meta_id', $metaId)->orderBy('order_number')->orderBy('id')->pluck('field_path')->all();
    }

    public function test_a_copy_has_every_part_pointing_at_its_own_copies(): void
    {
        // Nested params: the copies' parents are copies too.
        $app = $this->meta('proc_app');
        $copy = $this->postJson("/api/metas/{$app->id}/copy")->assertCreated()->json();
        $this->assertSame('proc_app_copy', $copy['code']);
        $this->assertSame("{$app->name} (copy)", $copy['name']);
        $this->assertSame($this->params($app->id), $this->params($copy['id']));
        $parents = DB::table('meta_params')->where('main_meta_id', $copy['id'])->whereNotNull('parent_id')->pluck('parent_id')->unique();
        $this->assertNotEmpty($parents);
        $this->assertSame(0, DB::table('meta_params')->whereIn('id', $parents)->where('main_meta_id', '<>', $copy['id'])->count());
        $this->assertSame('proc_app_copy_2', $this->postJson("/api/metas/{$app->id}/copy")->assertCreated()->json('code'));

        // A workflow: its transitions go between its copied statuses.
        $flow = $this->meta('proc_order_workflow');
        $copy = $this->postJson("/api/metas/{$flow->id}/copy", ['code' => 'flow_two', 'name' => 'Flow two'])->assertCreated()->json();
        $statuses = DB::table('wfm_status')->where('workflow_meta_id', $copy['id'])->pluck('id');
        $this->assertCount(DB::table('wfm_status')->where('workflow_meta_id', $flow->id)->count(), $statuses);
        $transitions = DB::table('wfm_transition')->where('workflow_meta_id', $copy['id'])->get();
        $this->assertSame(DB::table('wfm_transition')->where('workflow_meta_id', $flow->id)->count(), $transitions->count());
        foreach ($transitions as $t) {
            $this->assertContains($t->next_status_id, $statuses);
        }

        // A grid: its buttons and links; links from its params name the copied params.
        $grid = $this->meta('proc_orders_grid');
        $copy = $this->postJson("/api/metas/{$grid->id}/copy")->assertCreated()->json();
        $this->assertSame(DB::table('meta_actions')->where('grid_meta_id', $grid->id)->count(), DB::table('meta_actions')->where('grid_meta_id', $copy['id'])->count());
        $links = DB::table('meta_meta_map')->where('src_meta_id', $copy['id'])->get();
        $this->assertSame(DB::table('meta_meta_map')->where('src_meta_id', $grid->id)->count(), $links->count());
        foreach ($links->whereNotNull('src_param_id') as $link) {
            $this->assertSame($copy['id'], DB::table('meta_params')->where('id', $link->src_param_id)->value('main_meta_id'));
        }
        $this->getJson("/api/metas/{$copy['id']}/data")->assertOk();

        // A page: its own widget metas are copied too, and the copy's sections show the copies.
        $page = $this->meta('proc_home_page');
        $widgets = DB::table('metas')->where('ref_meta_id', $page->id)->pluck('id');
        $copy = $this->postJson("/api/metas/{$page->id}/copy")->assertCreated()->json();
        $copied = DB::table('metas')->where('ref_meta_id', $copy['id'])->pluck('id');
        $this->assertCount($widgets->count(), $copied);
        $shown = DB::table('meta_params')->where('main_meta_id', $copy['id'])->whereIn('ref_meta_id', [...$widgets, ...$copied])->pluck('ref_meta_id');
        $this->assertTrue($shown->every(fn ($id) => $copied->contains($id)));

        $this->postJson("/api/metas/{$grid->id}/copy", ['code' => 'proc_orders_grid'])->assertUnprocessable();
    }

    public function test_replace_makes_a_meta_the_same_as_another_but_keeps_its_id_code_and_name(): void
    {
        $items = $this->meta('proc_items_grid');
        $suppliers = $this->meta('proc_suppliers_grid');
        $before = $this->params($items->id);

        $result = $this->postJson("/api/metas/{$items->id}/replace", ['source_meta_id' => $suppliers->id])->assertOk()->json();
        $this->assertSame($items->id, $result['meta']['id']);
        $this->assertSame('proc_items_grid', $result['meta']['code']);
        $this->assertSame($items->name, $result['meta']['name']);
        $this->assertSame('proc_suppliers', $result['meta']['table_name']);
        $this->assertSame($this->params($suppliers->id), $this->params($items->id));
        $this->assertSame(DB::table('meta_actions')->where('grid_meta_id', $suppliers->id)->count(), DB::table('meta_actions')->where('grid_meta_id', $items->id)->count());
        $this->assertSame(7, count($this->params($suppliers->id)), 'The source is left as it was.');

        // What it was is kept as a version, so it can be put back.
        $version = MetaVersion::where('meta_id', $items->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Before replacing', $version->note);
        $this->postJson("/api/meta-versions/{$version->id}/restore")->assertOk();
        $this->assertSame($before, $this->params($items->id));

        $this->postJson("/api/metas/{$items->id}/replace", ['source_meta_id' => $items->id])->assertUnprocessable();
    }

    public function test_a_meta_is_made_into_metas_of_other_types(): void
    {
        $grid = $this->meta('proc_suppliers_grid');
        $targets = array_column($this->getJson("/api/metas/{$grid->id}/derive")->assertOk()->json(), 'code');
        foreach (['form', 'matrix', 'query', 'excel_import', 'chart'] as $code) {
            $this->assertContains($code, $targets);
        }
        $this->assertNotContains('datagrid', $targets);
        $this->assertNotContains('workspace', $targets);

        // A form on the same table, with the grid's fields.
        $form = $this->postJson("/api/metas/{$grid->id}/derive", ['type' => 'form', 'name' => 'Supplier'])->assertCreated()->json();
        $this->assertSame('form', $form['type']['code']);
        $this->assertSame('proc_suppliers_form', $form['code']);
        $this->assertSame('proc_suppliers', $form['table_name']);
        // Its plain columns only: the orders sub-grid isn't a field.
        $this->assertSame(DB::table('meta_params')->where('main_meta_id', $grid->id)->where('data_type', '<>', 'subgrid')->orderBy('order_number')->orderBy('id')->pluck('field_path')->all(), $this->params($form['id']));

        // A query of its table, which runs.
        $query = $this->postJson("/api/metas/{$grid->id}/derive", ['type' => 'query'])->assertCreated()->json();
        $this->assertStringContainsString('proc_suppliers', $query['query_string']);
        $rows = $this->getJson("/api/metas/{$query['id']}/query-design/rows")->assertOk()->json('rows');
        $this->assertCount(DB::table('proc_suppliers')->count(), $rows);

        // A matrix whose rows are the grid's.
        $matrix = $this->postJson("/api/metas/{$grid->id}/derive", ['type' => 'matrix'])->assertCreated()->json();
        $this->assertSame($grid->id, $matrix['json_config']['rows']['meta_id']);

        // A workspace for the form's records; a grid from the query.
        $workspace = $this->postJson("/api/metas/{$form['id']}/derive", ['type' => 'workspace'])->assertCreated()->json();
        $this->assertSame($form['id'], $workspace['ref_meta_id']);
        $queryGrid = $this->postJson("/api/metas/{$query['id']}/derive", ['type' => 'datagrid', 'name' => 'Suppliers again'])->assertCreated()->json();
        $this->assertSame($query['id'], $queryGrid['ref_meta_id']);
        $this->assertSame('Suppliers again', $queryGrid['name']);
        $this->assertCount(DB::table('proc_suppliers')->count(), $this->getJson("/api/metas/{$queryGrid['id']}/data")->assertOk()->json('data'));

        $this->postJson("/api/metas/{$grid->id}/derive", ['type' => 'workspace'])->assertUnprocessable();
    }

    public function test_only_admins_may_copy_replace_or_derive(): void
    {
        $grid = $this->meta('proc_suppliers_grid');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/metas/{$grid->id}/copy")->assertForbidden();
        $this->postJson("/api/metas/{$grid->id}/replace", ['source_meta_id' => $grid->id])->assertForbidden();
        $this->postJson("/api/metas/{$grid->id}/derive", ['type' => 'form'])->assertForbidden();
    }
}
