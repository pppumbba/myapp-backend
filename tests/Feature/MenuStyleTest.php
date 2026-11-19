<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Menu style metas: listed for everyone, set by admins or the builder who made them, picked by apps. */
class MenuStyleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ProcurementDemoSeeder::class]);
        $this->admin = User::factory()->admin()->create();
    }

    private function typeId(string $code): int
    {
        return MetaType::where('code', $code)->value('id');
    }

    private function style(array $menu = [], array $extra = []): Meta
    {
        return Meta::create($extra + ['code' => 'menu_'.uniqid(), 'name' => 'Style '.uniqid(), 'type_id' => $this->typeId('menu_style'), 'is_active' => true, 'json_config' => ['menu' => $menu, 'kept' => 1]]);
    }

    private function builder(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::create(['code' => 'builder_'.uniqid(), 'name' => 'Builder', 'features' => ['build']])->id);

        return $user;
    }

    public function test_lists_active_menu_styles_filled_in(): void
    {
        $a = $this->style(['edge' => 'shadow']);
        $this->style([], ['is_active' => false]);
        Sanctum::actingAs(User::factory()->create());

        $styles = $this->getJson('/api/menu-styles')->assertOk()->json('styles');

        $this->assertCount(1, $styles);
        $this->assertSame($a->id, $styles[0]['id']);
        $this->assertSame([
            'background' => 'surface', 'edge' => 'shadow', 'radius' => 'none', 'gap' => 0, 'width' => 'default', 'item' => 'pill', 'frame' => false,
            'gradient' => ['from' => 'brand', 'to' => '#ec4899', 'angle' => 135], 'glass' => false, 'backdrop' => null,
        ], $styles[0]['menu']);
    }

    public function test_admin_saves_settings_and_hex_is_lowercased(): void
    {
        $style = $this->style();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => ['background' => '#1E293B', 'radius' => 'lg', 'gap' => 12]])
            ->assertOk()->assertJsonPath('radius', 'lg');

        $config = $style->fresh()->json_config;
        $this->assertSame('#1e293b', $config['menu']['background']);
        $this->assertSame(12, $config['menu']['gap']);
        $this->assertSame(1, $config['kept']);
    }

    public function test_unknown_values_are_refused(): void
    {
        $style = $this->style();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => ['radius' => 'huge']])->assertStatus(422);
        $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => ['gap' => 5]])->assertStatus(422);
        $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => ['background' => 'blue']])->assertStatus(422);
        $grid = Meta::where('code', 'proc_items_grid')->first();
        $this->putJson("/api/metas/{$grid->id}/menu-style", ['menu' => []])->assertStatus(422);
    }

    public function test_gradient_glass_and_backdrop_are_saved(): void
    {
        $style = $this->style();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => [
            'background' => 'gradient',
            'gradient' => ['from' => 'brand', 'to' => '#EC4899', 'angle' => 45],
            'glass' => 1,
            'backdrop' => ['from' => '#0EA5E9', 'to' => 'brand', 'angle' => 180],
        ]])->assertOk();

        $menu = $style->fresh()->json_config['menu'];
        $this->assertSame('gradient', $menu['background']);
        $this->assertSame(['from' => 'brand', 'to' => '#ec4899', 'angle' => 45], $menu['gradient']);
        $this->assertTrue($menu['glass']);
        $this->assertSame(['from' => '#0ea5e9', 'to' => 'brand', 'angle' => 180], $menu['backdrop']);
    }

    public function test_bad_gradients_are_refused(): void
    {
        $style = $this->style();
        Sanctum::actingAs($this->admin);

        foreach (['gradient', 'backdrop'] as $key) {
            $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => [$key => ['from' => 'brand', 'to' => '#ec4899', 'angle' => 30]]])->assertStatus(422);
            $this->putJson("/api/metas/{$style->id}/menu-style", ['menu' => [$key => ['from' => 'red', 'to' => '#ec4899', 'angle' => 45]]])->assertStatus(422);
        }
    }

    public function test_builder_saves_only_their_own(): void
    {
        $builder = $this->builder();
        $mine = $this->style([], ['created_by' => $builder->id]);
        $theirs = $this->style([], ['created_by' => $this->admin->id]);

        Sanctum::actingAs($builder);
        $this->putJson("/api/metas/{$mine->id}/menu-style", ['menu' => ['edge' => 'none']])->assertOk();
        $this->putJson("/api/metas/{$theirs->id}/menu-style", ['menu' => ['edge' => 'none']])->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/metas/{$mine->id}/menu-style", ['menu' => ['edge' => 'none']])->assertForbidden();
    }

    public function test_builder_cannot_pick_a_style_they_cannot_open(): void
    {
        $builder = $this->builder();
        $closed = $this->style([], ['created_by' => $this->admin->id]);
        $app = Meta::create(['code' => 'app_'.uniqid(), 'name' => 'My app', 'type_id' => $this->typeId('app'), 'is_active' => true, 'created_by' => $builder->id]);

        Sanctum::actingAs($builder);
        $this->putJson("/api/metas/{$app->id}", ['json_config' => ['menu_style_meta_id' => $closed->id]])->assertStatus(422);
    }

    public function test_guests_on_a_portal_get_the_list_too(): void
    {
        $style = $this->style(['background' => 'brand']);

        $this->getJson('/api/menu-styles')->assertOk()->assertJsonPath('styles.0.id', $style->id);
    }

    public function test_deleted_style_drops_out_of_the_list(): void
    {
        $style = $this->style();
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/metas/{$style->id}")->assertNoContent();

        $this->assertSame([], $this->getJson('/api/menu-styles')->assertOk()->json('styles'));
    }
}
