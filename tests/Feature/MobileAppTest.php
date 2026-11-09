<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
    }

    private function app(array $mobile = [], array $extra = []): Meta
    {
        $type = MetaType::where('code', 'app')->value('id');

        return Meta::create(['code' => 'app_'.uniqid(), 'name' => 'Assets', 'type_id' => $type, 'is_active' => true, 'json_config' => $mobile ? ['mobile' => $mobile] : null, ...$extra]);
    }

    public function test_a_phone_app_shows_its_name_and_colour_to_guests(): void
    {
        $app = $this->app(['enabled' => true, 'name' => 'Asset field', 'package_id' => 'mn.wand.assets', 'color' => '#4f39f6']);

        $this->getJson("/api/mobile/{$app->id}")->assertOk()
            ->assertJsonPath('name', 'Asset field')
            ->assertJsonPath('package_id', 'mn.wand.assets')
            ->assertJsonPath('color', '#4f39f6');

        // Without its own name it uses the app's.
        $plain = $this->app(['enabled' => true]);
        $this->getJson("/api/mobile/{$plain->id}")->assertOk()->assertJsonPath('name', 'Assets');
    }

    public function test_only_active_apps_set_up_for_phones_answer(): void
    {
        $this->getJson('/api/mobile/'.$this->app()->id)->assertNotFound();
        $this->getJson('/api/mobile/'.$this->app(['enabled' => false])->id)->assertNotFound();
        $this->getJson('/api/mobile/'.$this->app(['enabled' => true], ['is_active' => false])->id)->assertNotFound();

        $grid = Meta::create(['code' => 'grid_'.uniqid(), 'name' => 'Grid', 'type_id' => MetaType::where('code', 'datagrid')->value('id'), 'json_config' => ['mobile' => ['enabled' => true]]]);
        $this->getJson("/api/mobile/{$grid->id}")->assertNotFound();
    }

    public function test_package_ids_must_be_valid_and_unused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $first = $this->app(['enabled' => true, 'package_id' => 'mn.wand.assets']);
        $second = $this->app();

        $this->putJson("/api/metas/{$second->id}", ['json_config' => ['mobile' => ['enabled' => true, 'package_id' => 'Not Valid']]])
            ->assertUnprocessable()->assertJsonValidationErrors('json_config.mobile.package_id');
        $this->putJson("/api/metas/{$second->id}", ['json_config' => ['mobile' => ['enabled' => true, 'package_id' => 'mn.wand.assets']]])
            ->assertUnprocessable()->assertJsonValidationErrors('json_config.mobile.package_id');
        $this->putJson("/api/metas/{$second->id}", ['json_config' => ['mobile' => ['enabled' => true, 'color' => 'blue']]])
            ->assertUnprocessable()->assertJsonValidationErrors('json_config.mobile.color');

        $this->putJson("/api/metas/{$second->id}", ['json_config' => ['menu_style' => 'floating', 'mobile' => ['enabled' => true, 'package_id' => 'mn.wand.pos', 'color' => '#0b8a5f']]])
            ->assertOk()->assertJsonPath('json_config.mobile.package_id', 'mn.wand.pos')->assertJsonPath('json_config.menu_style', 'floating');
        // Saving an app again keeps its own package id.
        $this->putJson("/api/metas/{$first->id}", ['json_config' => ['mobile' => ['enabled' => true, 'package_id' => 'mn.wand.assets']]])->assertOk();
    }
}
