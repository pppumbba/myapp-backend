<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Public metas (is_public): anyone may open them and add records through them, signed in or not. */
class PublicMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
    }

    private function meta(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    private function makePublic(string ...$codes): void
    {
        Meta::whereIn('code', $codes)->update(['is_public' => true]);
    }

    public function test_guests_open_public_metas_only(): void
    {
        $grid = $this->meta('proc_items_grid')->id;

        $this->getJson("/api/metas/{$grid}")->assertUnauthorized();
        $this->getJson("/api/metas/{$grid}/data")->assertUnauthorized();
        $this->getJson('/api/metas')->assertUnauthorized();

        $this->makePublic('proc_items_grid');
        $this->getJson("/api/metas/{$grid}")->assertOk()->assertJsonPath('is_public', true);
        $this->getJson("/api/metas/{$grid}/params")->assertOk();
        $this->getJson("/api/metas/{$grid}/data")->assertOk()->assertJsonPath('total', DB::table('proc_items')->count());
        $this->getJson('/api/user/access')->assertOk()
            ->assertJsonPath('is_admin', false)
            ->assertJsonPath("metas.{$grid}", ['view' => true, 'create' => true, 'edit' => false, 'delete' => false]);

        // Never edit or delete, and nothing behind a login.
        $id = DB::table('proc_items')->value('id');
        $this->putJson("/api/metas/{$grid}/data/{$id}", ['name' => 'Mine'])->assertUnauthorized();
        $this->deleteJson("/api/metas/{$grid}/data/{$id}")->assertUnauthorized();
        $this->putJson("/api/metas/{$grid}", ['name' => 'Mine'])->assertUnauthorized();
    }

    public function test_guests_send_a_public_form_but_never_read_records_through_it(): void
    {
        $form = $this->meta('proc_item_form')->id;
        $values = ['code' => 'G-1', 'name' => 'From the portal', 'unit' => 'box', 'unit_price' => 1];

        $this->postJson("/api/metas/{$form}/data", $values)->assertUnauthorized();

        $this->makePublic('proc_item_form');
        $id = $this->postJson("/api/metas/{$form}/data", $values)->assertCreated()->json('id');
        $this->assertDatabaseHas('proc_items', ['id' => $id, 'name' => 'From the portal']);

        // What one visitor sent stays hidden from the next (and signing in would not show it either, without a role).
        $this->getJson("/api/metas/{$form}/data/{$id}")->assertUnauthorized();
        $this->getJson("/api/metas/{$form}/data")->assertUnauthorized();
        $this->getJson("/api/metas/{$form}/facets?fields[]=unit")->assertUnauthorized();

        // Those with a role that may view the form still read through it.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/metas/{$form}/data/{$id}")->assertOk();
    }

    public function test_signed_in_users_without_a_role_get_public_metas_too(): void
    {
        $grid = $this->meta('proc_items_grid')->id;
        $this->makePublic('proc_items_grid');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/metas/{$grid}/data")->assertOk();
        $this->assertContains($grid, collect($this->getJson('/api/metas')->assertOk()->json())->pluck('id'));
        $this->getJson('/api/user/access')->assertOk()->assertJsonPath("metas.{$grid}.view", true)->assertJsonPath("metas.{$grid}.edit", false);
    }

    public function test_only_admins_make_a_meta_public(): void
    {
        $grid = $this->meta('proc_items_grid');
        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/metas/{$grid->id}", ['is_public' => true])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/metas/{$grid->id}", ['is_public' => true])->assertOk()->assertJsonPath('is_public', true);
        $this->assertTrue($grid->fresh()->is_public);
    }

    public function test_guests_sending_too_much_are_slowed_down(): void
    {
        $form = $this->meta('proc_item_form')->id;
        $this->makePublic('proc_item_form');

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson("/api/metas/{$form}/data", ['code' => "G-{$i}", 'name' => "Item {$i}", 'unit' => 'box', 'unit_price' => 1])->assertCreated();
        }
        $this->postJson("/api/metas/{$form}/data", ['code' => 'G-21', 'name' => 'One too many', 'unit' => 'box', 'unit_price' => 1])->assertTooManyRequests();
    }
}
