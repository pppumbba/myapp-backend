<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** A PNG of the given size: just its signature, header and end (GD isn't needed to read a size). */
    private function png(string $name, int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $bytes);
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

    public function test_an_app_icon_uploads_shows_to_guests_and_goes_back_to_none(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->admin()->create());
        $app = $this->app(['enabled' => true, 'package_id' => 'mn.wand.assets']);

        $this->post("/api/metas/{$app->id}/mobile-icon", ['icon' => $this->png('wide.png', 512, 256)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('icon');
        $this->post("/api/metas/{$app->id}/mobile-icon", ['icon' => $this->png('tiny.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('icon');

        $first = $this->post("/api/metas/{$app->id}/mobile-icon", ['icon' => $this->png('logo.png', 512, 512)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('package_id', 'mn.wand.assets')->json('icon');
        $this->assertMatchesRegularExpression('/^\d+-\d+\.png$/', $first);
        $this->assertSame($first, $this->getJson("/api/mobile/{$app->id}")->json('icon'));

        // A plain meta save keeps it, and can't point it at another file.
        $this->putJson("/api/metas/{$app->id}", ['json_config' => ['mobile' => ['enabled' => true, 'package_id' => 'mn.wand.assets', 'icon' => $first]]])->assertOk();
        $this->putJson("/api/metas/{$app->id}", ['json_config' => ['mobile' => ['enabled' => true, 'package_id' => 'mn.wand.assets', 'icon' => '1-1.png']]])
            ->assertUnprocessable()->assertJsonValidationErrors('json_config.mobile.icon');

        $this->get("/api/mobile/{$app->id}/icon")->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->deleteJson("/api/metas/{$app->id}/mobile-icon")->assertOk()->assertJsonPath('icon', null);
        $this->get("/api/mobile/{$app->id}/icon")->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('mobile-icons'));
    }
}
