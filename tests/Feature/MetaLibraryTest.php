<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Starred, Recent and Shared with me on the Meta studio pages. */
class MetaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Meta $grid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $type = MetaType::firstOrCreate(['code' => 'datagrid'], ['name' => 'Data grid']);
        $this->grid = Meta::create(['code' => 'lib_grid', 'name' => 'Library grid', 'type_id' => $type->id, 'created_by' => $this->admin->id]);
    }

    private function builder(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['code' => 'lib_builder'], ['name' => 'Builder', 'features' => ['build']])->id);

        return $user;
    }

    public function test_starring_and_opening_fill_starred_and_recent(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/metas/{$this->grid->id}/star")->assertNoContent();
        $this->putJson("/api/metas/{$this->grid->id}/star")->assertNoContent();
        $this->postJson("/api/metas/{$this->grid->id}/viewed")->assertNoContent();
        $this->postJson("/api/metas/{$this->grid->id}/viewed")->assertNoContent();

        $this->getJson('/api/metas/library')->assertOk()
            ->assertJsonCount(1, 'starred')->assertJsonPath('starred.0.meta_id', $this->grid->id)
            ->assertJsonCount(1, 'recent')->assertJsonPath('recent.0.meta_id', $this->grid->id)
            ->assertJsonCount(0, 'shared');

        $this->deleteJson("/api/metas/{$this->grid->id}/star")->assertNoContent();
        $this->getJson('/api/metas/library')->assertJsonCount(0, 'starred');
    }

    public function test_a_builder_cannot_star_a_meta_they_cannot_see(): void
    {
        Sanctum::actingAs($this->builder());
        $this->putJson("/api/metas/{$this->grid->id}/star")->assertNotFound();
    }

    public function test_sharing_lets_a_builder_see_and_change_the_meta_and_tells_them(): void
    {
        $builder = $this->builder();
        Sanctum::actingAs($builder);
        $this->assertNotContains($this->grid->id, collect($this->getJson('/api/metas')->json())->pluck('id'));

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/metas/{$this->grid->id}/shares")->assertOk()->assertJsonPath('people.0.id', $builder->id)->assertJsonCount(0, 'shared');
        $this->putJson("/api/metas/{$this->grid->id}/shares", ['user_ids' => [$builder->id]])->assertOk()->assertJsonPath('added', 1);
        $this->getJson("/api/metas/{$this->grid->id}/shares")->assertJsonPath('shared.0.id', $builder->id)->assertJsonPath('shared.0.by', $this->admin->name);
        $this->assertTrue(UserNotification::where('user_id', $builder->id)->where('link', "/metas/{$this->grid->id}")->exists());

        Sanctum::actingAs($builder);
        $this->getJson('/api/metas/library')->assertJsonPath('shared.0.meta_id', $this->grid->id)->assertJsonPath('shared.0.by.name', $this->admin->name);
        $this->assertContains($this->grid->id, collect($this->getJson('/api/metas')->json())->pluck('id'));
        $this->putJson("/api/metas/{$this->grid->id}", ['code' => 'lib_grid', 'name' => 'Renamed by builder', 'type_id' => $this->grid->type_id])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/metas/{$this->grid->id}/shares", ['user_ids' => []])->assertOk();
        Sanctum::actingAs($builder);
        $this->getJson('/api/metas/library')->assertJsonCount(0, 'shared');
    }

    public function test_only_admins_and_builders_can_be_shared_with_and_only_by_who_may_change_it(): void
    {
        Sanctum::actingAs($this->admin);
        $plain = User::factory()->create();
        $this->putJson("/api/metas/{$this->grid->id}/shares", ['user_ids' => [$plain->id]])->assertUnprocessable();

        Sanctum::actingAs($this->builder());
        $this->getJson("/api/metas/{$this->grid->id}/shares")->assertForbidden();
    }
}
