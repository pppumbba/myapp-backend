<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use App\Models\Widget;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
    }

    private function newMeta(string $type, string $code = 'test_meta'): Meta
    {
        $typeId = MetaType::where('code', $type)->value('id');

        return Meta::create(['code' => $code, 'name' => 'Test', 'type_id' => $typeId, 'table_name' => 'things']);
    }

    public function test_saving_a_menu_makes_nested_params_in_order(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->newMeta('app');
        $grid = $this->newMeta('datagrid', 'test_grid');

        $tree = $this->putJson("/api/metas/{$app->id}/structure", ['items' => [
            ['field_path' => 'home', 'label_name' => 'Home', 'data_type' => 'menu', 'ref_meta_id' => $grid->id, 'icon_cls' => 'home'],
            ['field_path' => 'sales', 'label_name' => 'Sales', 'data_type' => 'menu', 'children' => [
                ['field_path' => 'orders', 'label_name' => 'Orders', 'data_type' => 'menu', 'ref_meta_id' => $grid->id],
            ]],
        ]])->assertOk()->json();

        $this->assertCount(2, $tree);
        $this->assertSame('orders', $tree[1]['children'][0]['field_path']);
        $this->assertSame($tree[1]['id'], $tree[1]['children'][0]['parent_id']);
        $this->assertSame([1, 2, 3], MetaParam::where('main_meta_id', $app->id)->orderBy('order_number')->pluck('order_number')->all());
    }

    public function test_kept_items_are_updated_moved_and_left_out_ones_removed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $form = $this->newMeta('form');
        $name = MetaParam::create(['main_meta_id' => $form->id, 'field_path' => 'name', 'param_name' => 'name', 'data_type' => 'string', 'order_number' => 1, 'default_value' => 'x']);
        $gone = MetaParam::create(['main_meta_id' => $form->id, 'field_path' => 'old', 'param_name' => 'old', 'data_type' => 'string', 'order_number' => 2]);

        $this->putJson("/api/metas/{$form->id}/structure", ['items' => [
            ['field_path' => 'qty', 'data_type' => 'integer', 'is_required' => true],
            ['id' => $name->id, 'label_name' => 'Full name'],
        ]])->assertOk();

        $name->refresh();
        $this->assertSame('Full name', $name->label_name);
        $this->assertSame('x', $name->default_value);
        $this->assertSame(2, $name->order_number);
        $this->assertNull(MetaParam::find($gone->id));
        $this->assertTrue(MetaParam::where('main_meta_id', $form->id)->where('field_path', 'qty')->value('is_required'));
    }

    public function test_bad_structures_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $tabs = $this->newMeta('tabs');
        $page = $this->newMeta('page', 'test_page');

        $this->putJson("/api/metas/{$tabs->id}/structure", ['items' => [['label_name' => 'No name']]])->assertStatus(422);
        $this->putJson("/api/metas/{$tabs->id}/structure", ['items' => [['field_path' => 'self', 'ref_meta_id' => $tabs->id]]])->assertStatus(422);
        $this->putJson("/api/metas/{$page->id}/structure", ['items' => []])->assertStatus(422);
        $this->assertSame(0, MetaParam::where('main_meta_id', $tabs->id)->count());
    }

    public function test_only_admins_save_structures(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $form = $this->newMeta('form');

        $this->putJson("/api/metas/{$form->id}/structure", ['items' => []])->assertForbidden();
        $this->putJson("/api/metas/{$this->newMeta('app', 'some_app')->id}/app", ['items' => []])->assertForbidden();
    }

    public function test_an_app_makes_its_new_screens_with_their_fields_and_buttons(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->newMeta('app', 'shop_app');
        $this->newMeta('form', 'orders_grid');

        $res = $this->putJson("/api/metas/{$app->id}/app", [
            'meta' => ['name' => 'Shop'],
            'items' => [
                ['field_path' => 'orders', 'label_name' => 'Orders', 'data_type' => 'menu', 'ref_key' => 'g'],
            ],
            'metas' => [
                ['key' => 'g', 'type' => 'datagrid', 'name' => 'Orders', 'table_name' => 'orders', 'items' => [
                    ['field_path' => 'number', 'label_name' => 'Number'],
                    ['field_path' => 'total', 'data_type' => 'decimal'],
                ], 'actions' => [
                    ['action' => 'add', 'label' => 'Add', 'form_key' => 'f'],
                    ['action' => 'edit', 'label' => 'Edit', 'form_key' => 'f', 'needs_row' => true, 'json_config' => ['params' => ['id' => 'id']]],
                ]],
                ['key' => 'f', 'type' => 'form', 'name' => 'Order', 'table_name' => 'orders', 'items' => [
                    ['field_path' => 'number', 'is_required' => true],
                ]],
            ],
        ])->assertOk()->json();

        $this->assertSame('Shop', $app->fresh()->name);
        $grid = Meta::where('name', 'Orders')->firstOrFail();
        $form = Meta::where('name', 'Order')->firstOrFail();
        // orders_grid is taken, so the made code moves on.
        $this->assertSame('orders_grid_2', $grid->code);
        $this->assertSame('datagrid', $grid->type->code);
        $this->assertSame($grid->id, $res['items'][0]['ref_meta_id']);
        $this->assertSame(['number', 'total'], MetaParam::where('main_meta_id', $grid->id)->orderBy('order_number')->pluck('field_path')->all());
        $this->assertSame([$form->id, $form->id], MetaAction::where('grid_meta_id', $grid->id)->orderBy('order_number')->pluck('form_meta_id')->all());
        $this->assertSame('g', $res['metas'][0]['key']);
        $this->assertCount(2, $res['metas'][0]['actions']);

        // Saved again by id: the edit button goes, a column is renamed, the form is left alone.
        $this->putJson("/api/metas/{$app->id}/app", [
            'items' => [['id' => $res['items'][0]['id'], 'ref_meta_id' => $grid->id]],
            'metas' => [
                ['key' => 'g', 'id' => $grid->id, 'items' => [
                    ['id' => $res['metas'][0]['items'][0]['id'], 'label_name' => 'No.'],
                ], 'actions' => [
                    ['id' => $res['metas'][0]['actions'][0]['id'], 'action' => 'add', 'label' => 'New order', 'form_meta_id' => $form->id],
                ]],
            ],
        ])->assertOk();

        $this->assertSame(['No.'], MetaParam::where('main_meta_id', $grid->id)->pluck('label_name')->all());
        $this->assertSame(['New order'], MetaAction::where('grid_meta_id', $grid->id)->pluck('label')->all());
        $this->assertSame(1, MetaParam::where('main_meta_id', $form->id)->count());
    }

    public function test_an_app_makes_a_new_page_with_its_widgets(): void
    {
        $this->seed(WidgetSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->newMeta('app', 'shop_app');
        $section = Widget::where('code', 'section')->value('id');
        $heading = Widget::where('code', 'heading')->value('id');

        $res = $this->putJson("/api/metas/{$app->id}/app", [
            'items' => [['field_path' => 'home', 'label_name' => 'Home', 'ref_key' => 'p']],
            'metas' => [
                ['key' => 'p', 'type' => 'page', 'name' => 'Home', 'items' => [
                    ['widget_id' => $section, 'config' => [], 'w' => 12, 'children' => [
                        ['widget_id' => $heading, 'config' => ['text' => 'Welcome'], 'w' => 6],
                    ]],
                ]],
            ],
        ])->assertOk()->json();

        $page = Meta::where('name', 'Home')->whereHas('type', fn ($q) => $q->where('code', 'page'))->firstOrFail();
        $this->assertSame($page->id, $res['items'][0]['ref_meta_id']);
        // The page's items come back as the page builder reads them.
        $item = $res['metas'][0]['items'][0];
        $this->assertSame('section', $item['widget']['code']);
        $this->assertSame(6, $item['children'][0]['w']);
        $this->assertSame('Welcome', $item['children'][0]['meta']['json_config']['text']);
        $this->assertSame($page->id, Meta::find($item['children'][0]['meta']['id'])->ref_meta_id);

        // A page has no buttons.
        $this->putJson("/api/metas/{$app->id}/app", [
            'items' => [],
            'metas' => [['key' => 'p', 'id' => $page->id, 'items' => [], 'actions' => []]],
        ])->assertStatus(422);
    }

    public function test_an_app_makes_a_new_dashboard_of_charts(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->newMeta('app', 'shop_app');
        $chart = $this->newMeta('chart', 'sales_chart');

        $res = $this->putJson("/api/metas/{$app->id}/app", [
            'items' => [['field_path' => 'overview', 'label_name' => 'Overview', 'ref_key' => 'd']],
            'metas' => [
                ['key' => 'd', 'type' => 'dashboard', 'name' => 'Overview', 'items' => [
                    ['field_path' => 'sales_chart', 'data_type' => 'chart', 'ref_meta_id' => $chart->id, 'json_config' => ['w' => 12, 'h' => 3]],
                ]],
            ],
        ])->assertOk()->json();

        $dashboard = Meta::findOrFail($res['items'][0]['ref_meta_id']);
        $this->assertSame('dashboard', $dashboard->type->code);
        $placed = MetaParam::where('main_meta_id', $dashboard->id)->sole();
        $this->assertSame($chart->id, $placed->ref_meta_id);
        $this->assertSame(['w' => 12, 'h' => 3], $placed->json_config);
    }

    public function test_a_bad_app_save_changes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->newMeta('app', 'shop_app');
        $grid = $this->newMeta('datagrid', 'some_grid');
        $count = Meta::count();

        // A button that opens a grid, not a form: refused, and the new screen is not kept.
        $this->putJson("/api/metas/{$app->id}/app", [
            'items' => [['field_path' => 'x', 'ref_key' => 'g']],
            'metas' => [['key' => 'g', 'type' => 'datagrid', 'name' => 'X', 'items' => [], 'actions' => [
                ['action' => 'add', 'label' => 'Add', 'form_meta_id' => $grid->id],
            ]]],
        ])->assertStatus(422);
        $this->assertSame($count, Meta::count());
        $this->assertSame(0, MetaParam::where('main_meta_id', $app->id)->count());

        $this->putJson("/api/metas/{$app->id}/app", ['items' => [['field_path' => 'x', 'ref_key' => 'nope']]])->assertStatus(422);
        $this->putJson("/api/metas/{$grid->id}/app", ['items' => []])->assertStatus(422);
    }
}
