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

/** An app's own pictures (icon and cover): uploaded, served to anyone with the name, replaced and removed. */
class AppImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
        Storage::fake('app-images');
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function meta(string $type): Meta
    {
        return Meta::create(['code' => $type.'_one', 'name' => 'One', 'type_id' => MetaType::where('code', $type)->value('id')]);
    }

    /** A real PNG without GD (as in AvatarTest). */
    private function png(int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat(chr(0).str_repeat(chr(0x33).chr(0x66).chr(0x99), $width), $height);

        return UploadedFile::fake()->createWithContent('cover.png', chr(0x89).'PNG'.chr(13).chr(10).chr(26).chr(10)
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($rows))
            .$chunk('IEND', ''));
    }

    public function test_an_app_picture_is_kept_served_replaced_and_removed(): void
    {
        $app = $this->meta('app');

        $firstPath = $this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(128, 72)])
            ->assertOk()->json('image_path');
        $this->assertMatchesRegularExpression('/^app-images\/'.$app->id.'-[A-Za-z0-9]{24}\.png$/', $firstPath);
        $this->assertSame($firstPath, $app->fresh()->image_path);
        $first = basename($firstPath);
        Storage::disk('app-images')->assertExists($first);
        $this->get("/api/app-images/{$first}")->assertOk();

        $second = basename($this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(96, 64)])
            ->assertOk()->json('image_path'));
        Storage::disk('app-images')->assertMissing($first);
        $this->assertSame('app-images/'.$second, $app->fresh()->image_path);

        $this->deleteJson("/api/metas/{$app->id}/app-image")->assertOk()->assertJsonPath('image_path', null);
        $this->assertNull($app->fresh()->image_path);
        Storage::disk('app-images')->assertMissing($second);
        $this->get("/api/app-images/{$second}")->assertNotFound();
    }

    public function test_the_cover_is_kept_apart_from_the_icon(): void
    {
        $app = $this->meta('app');
        $icon = $this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(96, 96)])->json('image_path');

        $cover = $this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(160, 70), 'kind' => 'cover'])
            ->assertOk()->assertJsonPath('image_path', $icon)->json('cover_path');
        $this->assertMatchesRegularExpression('/^app-images\/'.$app->id.'-[A-Za-z0-9]{24}\.png$/', $cover);
        $this->assertNotSame($icon, $cover);
        $this->assertSame($cover, $app->fresh()->cover_path);

        $this->deleteJson("/api/metas/{$app->id}/app-image?kind=cover")->assertOk()
            ->assertJsonPath('cover_path', null)->assertJsonPath('image_path', $icon);
        Storage::disk('app-images')->assertMissing(basename($cover));
        Storage::disk('app-images')->assertExists(basename($icon));

        $this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(96, 96), 'kind' => 'banner'], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_a_picture_a_copy_still_uses_is_kept(): void
    {
        $app = $this->meta('app');
        $path = $this->post("/api/metas/{$app->id}/app-image", ['image' => $this->png(128, 72)])->json('image_path');
        Meta::create(['code' => 'app_copy', 'name' => 'Copy', 'type_id' => $app->type_id, 'image_path' => $path]);

        $this->deleteJson("/api/metas/{$app->id}/app-image")->assertOk();

        Storage::disk('app-images')->assertExists(basename($path));
    }

    public function test_only_apps_take_a_picture_and_a_save_cannot_point_it_elsewhere(): void
    {
        $grid = $this->meta('datagrid');
        $this->post("/api/metas/{$grid->id}/app-image", ['image' => $this->png(128, 72)])->assertStatus(422);

        $app = $this->meta('app');
        // image_path is not part of a meta save, so it stays as it was.
        $this->putJson("/api/metas/{$app->id}", ['image_path' => 'app-images/1-'.str_repeat('a', 24).'.png', 'cover_path' => 'app-images/1-'.str_repeat('b', 24).'.png', 'name' => 'Renamed'])->assertOk();
        $this->assertNull($app->fresh()->image_path);
        $this->assertNull($app->fresh()->cover_path);
        $this->assertSame('Renamed', $app->fresh()->name);
    }
}
