<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Builders: roles with features make metas, change their own, and stay inside config/builders.php. */
class BuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    /** @param  string[]  $features */
    private function builder(array $features = ['build']): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::create(['code' => 'builder_'.count($features).uniqid(), 'name' => 'Builder', 'features' => $features])->id);

        return $user;
    }

    private function typeId(string $code): int
    {
        return MetaType::where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    private function grid(array $extra = []): array
    {
        return $extra + ['code' => 'my_grid_'.uniqid(), 'name' => 'My items', 'type_id' => $this->typeId('datagrid'), 'table_name' => 'proc_items'];
    }

    public function test_a_builder_makes_a_meta_and_may_do_everything_with_it(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/metas', $this->grid())->assertForbidden();

        $builder = $this->builder();
        Sanctum::actingAs($builder);
        $id = $this->postJson('/api/metas', $this->grid())->assertCreated()->assertJsonPath('created_by', $builder->id)->json('id');

        $this->getJson('/api/user/access')->assertOk()
            ->assertJsonPath('features', ['build'])
            ->assertJsonPath("metas.{$id}", ['view' => true, 'create' => true, 'edit' => true, 'delete' => true]);
        $this->assertContains($id, collect($this->getJson('/api/metas')->json())->pluck('id'));
        $this->postJson("/api/metas/{$id}/params", ['field_path' => 'name', 'param_name' => 'name', 'data_type' => 'string', 'order_number' => 1])->assertCreated();
        $this->getJson("/api/metas/{$id}/data")->assertOk();
        $this->putJson("/api/metas/{$id}", ['name' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/metas/{$id}")->assertNoContent();
    }

    public function test_a_builder_cannot_change_metas_made_by_others(): void
    {
        $grid = Meta::where('code', 'proc_items_grid')->first();
        $builder = $this->allow($this->builder(), [$grid->id], ['view', 'create', 'edit', 'delete']);
        Sanctum::actingAs($builder);

        $this->putJson("/api/metas/{$grid->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/metas/{$grid->id}")->assertForbidden();
        $this->postJson("/api/metas/{$grid->id}/params", ['field_path' => 'x', 'param_name' => 'x'])->assertForbidden();
        $this->putJson("/api/params/{$grid->params()->value('id')}", ['label_name' => 'X'])->assertForbidden();
        $this->postJson("/api/metas/{$grid->id}/actions", ['action' => 'add', 'label' => 'X', 'form_meta_id' => $grid->id])->assertForbidden();
    }

    public function test_a_builders_meta_stays_off_system_tables_sql_and_admin_only_types(): void
    {
        Sanctum::actingAs($this->builder());

        foreach (['users', 'personal_access_tokens', 'meta_params', 'market_bids', 'other_db.users', 'proc_items; drop table users'] as $table) {
            $this->postJson('/api/metas', $this->grid(['table_name' => $table]))->assertUnprocessable()->assertJsonValidationErrors('table_name');
        }
        $this->postJson('/api/metas', $this->grid(['query_string' => 'select * from users']))->assertUnprocessable()->assertJsonValidationErrors('query_string');
        $this->postJson('/api/metas', $this->grid(['service_url' => 'https://example.com/api']))->assertUnprocessable()->assertJsonValidationErrors('service_url');
        $this->postJson('/api/metas', $this->grid(['before_save_expression_string' => 'alert(1)']))->assertUnprocessable();
        $this->postJson('/api/metas', $this->grid(['is_public' => true]))->assertUnprocessable()->assertJsonValidationErrors('is_public');
        $this->postJson('/api/metas', $this->grid(['type_id' => $this->typeId('stage_query')]))->assertUnprocessable()->assertJsonValidationErrors('type_id');

        $id = $this->postJson('/api/metas', $this->grid(['is_public' => false, 'query_string' => null]))->assertCreated()->json('id');
        $this->putJson("/api/metas/{$id}", ['table_name' => 'users'])->assertUnprocessable();
        $this->postJson("/api/metas/{$id}/params", ['field_path' => 'x', 'param_name' => 'x', 'expression_string' => '(select password from users)'])
            ->assertUnprocessable()->assertJsonValidationErrors('expression_string');
    }

    public function test_a_builder_links_only_to_metas_they_may_open(): void
    {
        $other = Meta::where('code', 'proc_items_grid')->value('id');
        $form = Meta::where('code', 'proc_item_form')->value('id');
        $builder = $this->builder();
        Sanctum::actingAs($builder);
        $id = $this->postJson('/api/metas', $this->grid())->assertCreated()->json('id');

        $this->putJson("/api/metas/{$id}", ['ref_meta_id' => $other])->assertUnprocessable()->assertJsonValidationErrors('ref_meta_id');
        $this->putJson("/api/metas/{$id}", ['json_config' => ['drilldown' => ['meta_id' => $other, 'params' => []]]])->assertUnprocessable();
        $this->postJson("/api/metas/{$id}/params", ['field_path' => 'x', 'param_name' => 'x', 'ref_meta_id' => $other])->assertUnprocessable();
        $this->postJson("/api/metas/{$id}/actions", ['action' => 'add', 'label' => 'Add', 'form_meta_id' => $form])->assertUnprocessable();

        $this->allow($builder, [$other, $form]);
        $builder->forgetRoles();
        $this->putJson("/api/metas/{$id}", ['ref_meta_id' => $other])->assertOk();
        $this->postJson("/api/metas/{$id}/actions", ['action' => 'add', 'label' => 'Add', 'form_meta_id' => $form])->assertCreated();
    }

    public function test_the_studio_needs_the_studio_feature_and_keeps_the_same_rules(): void
    {
        $theirs = Meta::where('code', 'proc_items_grid')->value('id');
        $app = ['code' => 'my_app_'.uniqid(), 'name' => 'My app', 'type_id' => $this->typeId('app')];
        $screen = ['key' => 'a', 'type' => 'datagrid', 'name' => 'Things', 'table_name' => 'proc_items', 'items' => [['field_path' => 'name']]];

        Sanctum::actingAs($this->builder(['build']));
        $appId = $this->postJson('/api/metas', $app)->assertCreated()->json('id');
        $this->putJson("/api/metas/{$appId}/app", ['items' => [], 'metas' => [$screen]])->assertForbidden();

        Sanctum::actingAs($this->builder(['build', 'studio']));
        $appId = $this->postJson('/api/metas', ['code' => 'my_app_'.uniqid()] + $app)->assertCreated()->json('id');
        $this->putJson("/api/metas/{$appId}/app", ['items' => [['field_path' => 'things', 'ref_key' => 'a']], 'metas' => [$screen]])->assertOk();
        $this->putJson("/api/metas/{$appId}/app", ['items' => [], 'metas' => [['table_name' => 'users'] + $screen]])->assertUnprocessable();
        $this->putJson("/api/metas/{$appId}/app", ['items' => [], 'metas' => [['id' => $theirs] + $screen]])->assertForbidden();
    }

    public function test_admins_give_roles_features(): void
    {
        $role = Role::create(['code' => 'makers', 'name' => 'Makers']);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/roles/{$role->id}", ['features' => ['build', 'studio']])->assertOk()->assertJsonPath('features', ['build', 'studio']);
        $this->putJson("/api/roles/{$role->id}", ['features' => ['everything']])->assertUnprocessable();
    }
}
