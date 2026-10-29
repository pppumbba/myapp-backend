<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use App\Models\Widget;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PageBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function widget(string $code): int
    {
        return Widget::where('code', $code)->value('id');
    }

    private function newPage(): Meta
    {
        return Meta::create(['code' => 'test_page', 'name' => 'Test page', 'type_id' => MetaType::where('code', 'page')->value('id')]);
    }

    public function test_library_lists_the_built_in_widgets(): void
    {
        $this->getJson('/api/widgets')
            ->assertOk()
            ->assertJsonFragment(['code' => 'heading', 'renderer' => 'heading'])
            ->assertJsonFragment(['code' => 'datagrid', 'renderer' => 'meta']);
    }

    public function test_saving_a_page_makes_widget_metas_and_params(): void
    {
        $page = $this->newPage();

        $items = $this->putJson("/api/metas/{$page->id}/page", ['items' => [
            ['widget_id' => $this->widget('heading'), 'config' => ['text' => 'Hello'], 'w' => 12],
            ['widget_id' => $this->widget('section'), 'config' => ['title' => 'Box'], 'w' => 8, 'children' => [
                ['widget_id' => $this->widget('text'), 'config' => ['text' => 'Inside'], 'w' => 6],
            ]],
        ]])->assertOk()->json('items');

        $this->assertCount(2, $items);
        $this->assertSame('Inside', $items[1]['children'][0]['meta']['json_config']['text']);
        $this->assertSame(8, $items[1]['w']);

        $heading = Meta::find($items[0]['meta']['id']);
        $this->assertSame($this->widget('heading'), $heading->widget_id);
        $this->assertSame('widget', $heading->type->code);
        $this->assertSame($page->id, $heading->ref_meta_id);
        $this->assertSame($items[1]['param_id'], MetaParam::where('ref_meta_id', $items[1]['children'][0]['meta']['id'])->value('parent_id'));
    }

    public function test_resaving_keeps_updated_widgets_and_removes_dropped_ones(): void
    {
        $page = $this->newPage();
        $items = $this->putJson("/api/metas/{$page->id}/page", ['items' => [
            ['widget_id' => $this->widget('heading'), 'config' => ['text' => 'One']],
            ['widget_id' => $this->widget('text'), 'config' => ['text' => 'Two']],
        ]])->json('items');

        $this->putJson("/api/metas/{$page->id}/page", ['items' => [
            ['param_id' => $items[0]['param_id'], 'meta_id' => $items[0]['meta']['id'], 'widget_id' => $this->widget('heading'), 'config' => ['text' => 'Changed']],
        ]])->assertOk()->assertJsonCount(1, 'items');

        $this->assertSame('Changed', Meta::find($items[0]['meta']['id'])->json_config['text']);
        $this->assertDatabaseMissing('metas', ['id' => $items[1]['meta']['id']]);
        $this->assertSame(1, MetaParam::where('main_meta_id', $page->id)->count());
    }

    public function test_a_shared_widget_survives_being_dropped_from_a_page(): void
    {
        $page = $this->newPage();
        $shared = Meta::create(['code' => 'shared_banner', 'name' => 'Shared banner', 'type_id' => MetaType::where('code', 'widget')->value('id'), 'widget_id' => $this->widget('hero'), 'json_config' => ['title' => 'Hi']]);

        $this->putJson("/api/metas/{$page->id}/page", ['items' => [['meta_id' => $shared->id, 'widget_id' => $this->widget('hero'), 'config' => ['title' => 'Hi']]]])->assertOk();
        $this->putJson("/api/metas/{$page->id}/page", ['items' => []])->assertOk();

        $this->assertDatabaseHas('metas', ['id' => $shared->id]);
    }

    public function test_only_sections_hold_widgets(): void
    {
        $page = $this->newPage();

        $this->putJson("/api/metas/{$page->id}/page", ['items' => [
            ['widget_id' => $this->widget('text'), 'config' => [], 'children' => [['widget_id' => $this->widget('text'), 'config' => []]]],
        ]])->assertUnprocessable();
        $this->assertSame(0, MetaParam::where('main_meta_id', $page->id)->count());
    }

    public function test_only_admins_build_pages_and_change_the_library(): void
    {
        $page = $this->newPage();
        Sanctum::actingAs($this->allow(User::factory()->create(), [$page->id]));

        $this->getJson("/api/metas/{$page->id}/page")->assertOk();
        $this->putJson("/api/metas/{$page->id}/page", ['items' => []])->assertForbidden();
        $this->postJson('/api/widgets', ['code' => 'x', 'name' => 'X', 'renderer' => 'text'])->assertForbidden();
    }

    public function test_admins_add_library_entries_but_cannot_delete_used_ones(): void
    {
        $this->postJson('/api/widgets', [
            'code' => 'big_heading', 'name' => 'Big heading', 'renderer' => 'heading',
            'props' => [['key' => 'text', 'label' => 'Text', 'type' => 'text', 'default' => 'Big']],
        ])->assertCreated();
        $this->postJson('/api/widgets', ['code' => 'bad', 'name' => 'Bad', 'renderer' => 'marquee'])->assertUnprocessable();

        $this->deleteJson('/api/widgets/'.$this->widget('chart'))->assertUnprocessable();
        $this->deleteJson('/api/widgets/'.$this->widget('big_heading'))->assertNoContent();
    }

    public function test_a_composite_widget_gets_a_template_for_its_parts(): void
    {
        $widget = $this->postJson('/api/widgets', [
            'code' => 'promo', 'name' => 'Promo', 'renderer' => 'composite',
            'props' => [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
        ])->assertCreated()->json();

        $template = Meta::find($widget['template_meta_id']);
        $this->assertSame('widget_template', $template->type->code);

        // Its parts are laid out like a page, with {{title}} filled in when it is drawn.
        $this->putJson("/api/metas/{$template->id}/page", ['items' => [
            ['widget_id' => $this->widget('heading'), 'config' => ['text' => '{{title}}']],
        ]])->assertOk()->assertJsonPath('items.0.meta.json_config.text', '{{title}}');

        // A composite can't be one of its own parts.
        $this->putJson("/api/metas/{$template->id}/page", ['items' => [['widget_id' => $widget['id'], 'config' => []]]])
            ->assertUnprocessable();

        $part = Meta::where('ref_meta_id', $template->id)->value('id');
        $this->deleteJson("/api/widgets/{$widget['id']}")->assertNoContent();
        $this->assertDatabaseMissing('metas', ['id' => $template->id]);
        $this->assertDatabaseMissing('metas', ['id' => $part]);
    }

    public function test_the_demo_contact_card_is_a_composite_with_parts(): void
    {
        $card = Widget::where('code', 'contact_card')->first();

        $this->assertSame('composite', $card->renderer);
        $this->getJson("/api/metas/{$card->template_meta_id}/page")
            ->assertOk()
            ->assertJsonPath('items.0.widget.renderer', 'section')
            ->assertJsonPath('items.0.children.0.meta.json_config.text', '{{name}}');
    }

    public function test_the_demo_welcome_page_reads_as_a_tree(): void
    {
        $page = Meta::where('code', 'proc_home_page')->value('id');

        $this->getJson("/api/metas/{$page}/page")
            ->assertOk()
            ->assertJsonPath('items.0.widget.renderer', 'hero')
            ->assertJsonPath('items.1.widget.renderer', 'section')
            ->assertJsonCount(4, 'items.1.children');
    }
}
