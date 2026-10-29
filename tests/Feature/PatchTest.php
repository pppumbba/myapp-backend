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

/** Patches released here, installed (here, standing in for another installation) and rolled back. */
class PatchTest extends TestCase
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

    /** A released patch of the metas, as its file. */
    private function release(string $code, array $metaCodes): array
    {
        $ids = array_map(fn ($c) => $this->meta($c)->id, $metaCodes);
        $patch = $this->postJson('/api/patches', ['code' => $code, 'name' => "Patch {$code}", 'meta_ids' => $ids])->assertCreated()->json();
        $this->postJson("/api/patches/{$patch['id']}/release")->assertOk()->assertJsonPath('status', 'released');
        $response = $this->get("/api/patches/{$patch['id']}/download")->assertOk();

        return json_decode($response->streamedContent(), true);
    }

    private function label(int $metaId): ?string
    {
        return DB::table('meta_params')->where('main_meta_id', $metaId)->orderBy('id')->value('label_name');
    }

    private function setLabel(int $metaId, string $label): void
    {
        DB::table('meta_params')->where('id', DB::table('meta_params')->where('main_meta_id', $metaId)->orderBy('id')->value('id'))->update(['label_name' => $label]);
    }

    public function test_a_released_patch_is_frozen_and_its_file_says_what_it_is(): void
    {
        $form = $this->meta('proc_order_form');
        $file = $this->release('p1', ['proc_order_form']);
        $this->assertSame(['p1', 'Patch p1'], [$file['patch']['code'], $file['patch']['name']]);
        $this->assertSame('proc_order_form', $file['metas'][0]['meta']['code']);

        $patch = $this->getJson('/api/patches')->assertOk()->json('0');
        $this->assertSame([$form->id], array_column($patch['metas'], 'id'));
        $this->putJson("/api/patches/{$patch['id']}", ['name' => 'Other'])->assertStatus(422);
        $this->postJson("/api/patches/{$patch['id']}/release")->assertStatus(422);
    }

    public function test_an_install_replaces_metas_is_logged_and_rolls_back(): void
    {
        $form = $this->meta('proc_order_form');
        $original = $this->label($form->id);
        $file = $this->release('p1', ['proc_order_form']);
        // This installation has its own change, which the patch overwrites.
        $this->setLabel($form->id, 'Changed here');

        $tried = $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace', 'dry_run' => true])->assertOk()->json();
        $this->assertSame([], $tried['installed_before']);
        $this->assertSame('Changed here', $this->label($form->id));

        $installed = $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace'])->assertCreated()->json();
        $this->assertSame($original, $this->label($form->id));
        $this->assertSame('Patch p1', $installed['install']['patch_name']);
        $this->assertCount(1, $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace', 'dry_run' => true])->json('installed_before'));

        $id = $installed['install']['id'];
        $this->postJson("/api/patch-installs/{$id}/rollback")->assertOk()->assertJsonPath('install.id', $id);
        $this->assertSame('Changed here', $this->label($form->id));
        $this->assertNotNull($this->getJson('/api/patch-installs')->json('0.rolled_back_at'));
        $this->postJson("/api/patch-installs/{$id}/rollback")->assertStatus(422);
    }

    public function test_rolling_back_deletes_the_metas_an_install_added(): void
    {
        $flow = $this->meta('proc_order_workflow');
        $file = $this->release('p1', ['proc_order_workflow']);
        $this->deleteJson("/api/metas/{$flow->id}")->assertSuccessful();

        $installed = $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace'])->assertCreated()->json();
        $this->assertSame('create', $installed['metas'][0]['action']);
        $this->assertTrue(Meta::where('code', 'proc_order_workflow')->exists());

        $this->postJson("/api/patch-installs/{$installed['install']['id']}/rollback")->assertOk();
        $this->assertFalse(Meta::where('code', 'proc_order_workflow')->exists());
    }

    public function test_newer_installs_of_the_same_metas_roll_back_first(): void
    {
        $form = $this->meta('proc_order_form');
        $first = $this->release('p1', ['proc_order_form']);
        $this->setLabel($form->id, 'Second');
        $second = $this->release('p2', ['proc_order_form']);

        $one = $this->postJson('/api/patch-installs', ['package' => $first, 'existing' => 'replace'])->assertCreated()->json('install.id');
        $two = $this->postJson('/api/patch-installs', ['package' => $second, 'existing' => 'replace'])->assertCreated()->json('install.id');
        $this->assertSame('Second', $this->label($form->id));

        $this->postJson("/api/patch-installs/{$one}/rollback")->assertStatus(422)->assertJsonFragment(['message' => 'Roll back Patch p2 first: it changed the same metas later.']);
        $this->postJson("/api/patch-installs/{$two}/rollback")->assertOk();
        $this->postJson("/api/patch-installs/{$one}/rollback")->assertOk();
    }

    public function test_changed_metas_are_the_ones_since_the_newest_release(): void
    {
        $this->travel(1)->minutes();
        $this->release('p1', ['proc_order_form']);
        $this->travel(1)->minutes();
        DB::table('metas')->where('code', 'proc_orders_grid')->update(['updated_at' => now()]);

        $this->assertSame(['proc_orders_grid'], array_column($this->getJson('/api/patches/changed')->assertOk()->json(), 'code'));
        $this->assertGreaterThan(1, count($this->getJson('/api/patches/changed?since=2000-01-01')->json()));
    }
}
