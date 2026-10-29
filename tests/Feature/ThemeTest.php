<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Theme metas: listed for everyone, set by admins (one default, one theme per app), picked by each user. */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ThemeSeeder::class, ProcurementDemoSeeder::class]);
    }

    private function theme(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    public function test_everyone_sees_the_themes_filled_in_with_the_default_marked(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/themes')->assertOk();

        $response->assertJsonPath('default_id', $this->theme('theme_indigo')->id);
        $ocean = collect($response->json('themes'))->firstWhere('code', 'theme_ocean');
        $this->assertSame(['brand' => '#0284c7', 'gray' => 'slate', 'radius' => 'large', 'font' => 'inter', 'density' => 'default', 'mode' => 'any', 'default' => false, 'apps' => []], $ocean['theme']);
    }

    public function test_a_new_default_or_app_is_taken_from_the_other_themes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = Meta::where('code', 'proc_app')->firstOrFail();
        $this->putJson('/api/metas/'.$this->theme('theme_ocean')->id.'/theme', ['theme' => ['brand' => '#0284C7', 'apps' => [$app->id]]])->assertOk();

        $this->putJson('/api/metas/'.$this->theme('theme_forest')->id.'/theme', ['theme' => ['brand' => '#15803d', 'default' => true, 'apps' => [$app->id]]])
            ->assertOk()
            ->assertJsonPath('default', true);

        $all = $this->getJson('/api/themes')->json();
        $this->assertSame($this->theme('theme_forest')->id, $all['default_id']);
        $this->assertSame([(string) $app->id => $this->theme('theme_forest')->id], $all['apps']);
        $this->assertFalse($this->theme('theme_indigo')->json_config['theme']['default']);
        $this->assertSame([], $this->theme('theme_ocean')->json_config['theme']['apps']);
        $this->assertSame('#0284c7', $this->theme('theme_ocean')->json_config['theme']['brand']);
    }

    public function test_bad_settings_and_non_admins_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $ocean = $this->theme('theme_ocean');
        $this->putJson("/api/metas/{$ocean->id}/theme", ['theme' => ['brand' => 'blue']])->assertStatus(422);
        $this->putJson("/api/metas/{$ocean->id}/theme", ['theme' => ['radius' => 'huge']])->assertStatus(422);
        $grid = Meta::where('code', 'proc_items_grid')->firstOrFail();
        $this->putJson("/api/metas/{$ocean->id}/theme", ['theme' => ['apps' => [$grid->id]]])->assertStatus(422);
        $this->putJson("/api/metas/{$grid->id}/theme", ['theme' => []])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/metas/{$ocean->id}/theme", ['theme' => ['brand' => '#000000']])->assertForbidden();
    }

    public function test_a_user_picks_their_mode_and_theme(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $midnight = $this->theme('theme_midnight');

        $this->putJson('/api/user/theme', ['mode' => 'dark', 'theme_meta_id' => $midnight->id])
            ->assertOk()
            ->assertJsonPath('theme_mode', 'dark')
            ->assertJsonPath('theme_meta_id', $midnight->id);
        $this->getJson('/api/user')->assertJsonPath('theme_meta_id', $midnight->id);

        $grid = Meta::where('code', 'proc_items_grid')->firstOrFail();
        $this->putJson('/api/user/theme', ['mode' => 'purple', 'theme_meta_id' => $grid->id])->assertJsonValidationErrors(['mode', 'theme_meta_id']);

        $this->putJson('/api/user/theme', ['mode' => null, 'theme_meta_id' => null])->assertOk()->assertJsonPath('theme_mode', null);
    }

    public function test_a_user_picks_the_size_of_the_app(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/user/theme', ['mode' => 'dark', 'theme_meta_id' => null, 'size' => 'lg'])->assertOk()->assertJsonPath('ui_size', 'lg');
        $this->putJson('/api/user/theme', ['mode' => 'light', 'theme_meta_id' => null])->assertOk()->assertJsonPath('ui_size', 'lg');
        $this->putJson('/api/user/theme', ['mode' => null, 'theme_meta_id' => null, 'size' => 'md'])->assertOk()->assertJsonPath('ui_size', null);
        $this->putJson('/api/user/theme', ['mode' => null, 'theme_meta_id' => null, 'size' => 'xl'])->assertJsonValidationErrors('size');
    }
}
