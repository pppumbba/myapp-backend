<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\MetaVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\WfmStatus;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** One role's workspace: its people, its abilities on metas and statuses, and its log. */
class RoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Role $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->clerk = Role::firstOrCreate(['code' => 'clerk'], ['name' => 'Clerk']);
        Sanctum::actingAs($this->admin);
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    private function statusId(string $code): int
    {
        return WfmStatus::where('workflow_meta_id', $this->metaId('proc_order_workflow'))->where('code', $code)->value('id');
    }

    /** @return list<array<string, mixed>> */
    private function log(): array
    {
        return $this->getJson("/api/roles/{$this->clerk->id}/log")->assertOk()->json('data');
    }

    public function test_the_workspace_shows_the_roles_people_metas_and_statuses(): void
    {
        $grid = $this->metaId('proc_items_grid');
        $person = User::factory()->create(['name' => 'Bat']);
        $person->roles()->attach($this->clerk->id);
        MetaPermission::create(['meta_id' => $grid, 'role_id' => $this->clerk->id, 'can_view' => true]);

        $ws = $this->getJson("/api/roles/{$this->clerk->id}/workspace")->assertOk();

        $ws->assertJsonPath('role.users_count', 1)->assertJsonPath('users.0.name', 'Bat')->assertJsonPath('is_admin_role', false);
        $row = collect($ws->json('metas'))->firstWhere('id', $grid);
        $this->assertSame([true, false, true], [$row['can_view'], $row['can_edit'], $row['guarded']]);
        $this->assertNotContains('widget', collect($ws->json('metas'))->pluck('type.code'));
        $workflow = collect($ws->json('workflows'))->firstWhere('code', 'proc_order_workflow');
        $draft = collect($workflow['statuses'])->firstWhere('code', 'draft');
        $this->assertSame([true, true, false], [$draft['enter_open'], $draft['see_open'], $draft['can_see']]);

        Sanctum::actingAs($person);
        $this->getJson("/api/roles/{$this->clerk->id}/workspace")->assertForbidden();
        $this->putJson("/api/roles/{$this->clerk->id}/metas", ['rows' => []])->assertForbidden();
    }

    public function test_people_are_given_and_taken_the_role_and_it_is_logged(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $a->roles()->attach($this->clerk->id);

        $this->putJson("/api/roles/{$this->clerk->id}/users", ['user_ids' => [$b->id]])->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $b->id);
        $this->assertSame([$this->clerk->id], $b->roles()->pluck('roles.id')->all());
        $this->assertSame([], $a->roles()->pluck('roles.id')->all());

        $entry = $this->log()[0];
        $this->assertSame('users', $entry['action']);
        $this->assertSame([$b->id], array_column($entry['changes']['added'], 'id'));
        $this->assertSame([$a->id], array_column($entry['changes']['removed'], 'id'));
        $this->assertSame($this->admin->name, $entry['user_name']);

        $admin = Role::where('code', Role::ADMIN)->firstOrFail();
        $this->putJson("/api/roles/{$admin->id}/users", ['user_ids' => []])->assertUnprocessable();
    }

    public function test_abilities_on_metas_are_set_for_the_role_with_versions_and_log(): void
    {
        $grid = $this->metaId('proc_items_grid');
        $person = User::factory()->create();
        $person->roles()->attach($this->clerk->id);

        $this->putJson("/api/roles/{$this->clerk->id}/metas", ['rows' => [['meta_id' => $grid, 'can_view' => true, 'can_create' => true]]])->assertOk();
        $this->assertDatabaseHas('meta_permissions', ['meta_id' => $grid, 'role_id' => $this->clerk->id, 'can_view' => true, 'can_create' => true, 'can_edit' => false]);
        $this->assertSame(1, MetaVersion::where('meta_id', $grid)->count());

        $entry = $this->log()[0];
        $this->assertSame('metas', $entry['action']);
        $this->assertSame('proc_items_grid', $entry['changes'][0]['code']);
        $this->assertFalse($entry['changes'][0]['before']['view']);
        $this->assertTrue($entry['changes'][0]['after']['create']);

        Sanctum::actingAs($person);
        $this->getJson("/api/metas/{$grid}/data")->assertOk();

        // None left: the row goes, and the meta is for admins only again.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/roles/{$this->clerk->id}/metas", ['rows' => [['meta_id' => $grid]]])->assertOk();
        $this->assertDatabaseMissing('meta_permissions', ['meta_id' => $grid, 'role_id' => $this->clerk->id]);

        $admin = Role::where('code', Role::ADMIN)->firstOrFail();
        $this->putJson("/api/roles/{$admin->id}/metas", ['rows' => [['meta_id' => $grid, 'can_view' => true]]])->assertUnprocessable();
    }

    public function test_abilities_on_statuses_are_set_for_the_role(): void
    {
        $draft = $this->statusId('draft');

        $ws = $this->putJson("/api/roles/{$this->clerk->id}/statuses", ['rows' => [['status_id' => $draft, 'can_see' => true]]])->assertOk();

        $status = collect(collect($ws->json('workflows'))->firstWhere('code', 'proc_order_workflow')['statuses'])->firstWhere('id', $draft);
        $this->assertSame([true, false, true], [$status['can_see'], $status['see_open'], $status['enter_open']]);
        $entry = $this->log()[0];
        $this->assertSame(['statuses', $draft, true], [$entry['action'], $entry['changes'][0]['status_id'], $entry['changes'][0]['after']['see']]);
    }

    public function test_changes_made_elsewhere_are_in_the_roles_log_too(): void
    {
        $grid = $this->metaId('proc_items_grid');
        $person = User::factory()->create();

        $this->putJson("/api/users/{$person->id}/roles", ['role_ids' => [$this->clerk->id]])->assertOk();
        $this->putJson("/api/metas/{$grid}/permissions", ['rows' => [['role_id' => $this->clerk->id, 'can_view' => true]]])->assertOk();
        $this->putJson("/api/roles/{$this->clerk->id}", ['name' => 'Clerks'])->assertOk();
        // Saving the same again changes nothing, so it logs nothing.
        $this->putJson("/api/metas/{$grid}/permissions", ['rows' => [['role_id' => $this->clerk->id, 'can_view' => true]]])->assertOk();

        $log = $this->log();
        $this->assertSame(['role', 'metas', 'users'], array_column($log, 'action'));
        $this->assertSame(['Clerk', 'Clerks'], $log[0]['changes']['name']);
        $this->assertSame(1, DB::table('role_log')->where('action', 'metas')->count());
    }

    public function test_the_meta_tree_puts_screens_under_their_app_and_menus(): void
    {
        $tree = $this->getJson('/api/meta-tree')->assertOk()->json();

        $app = $this->metaId('proc_app');
        $menu = collect($tree['menus'][$app]);
        $this->assertSame($this->metaId('proc_request_form'), collect($menu->firstWhere('label', 'Purchasing')['children'])->firstWhere('label', 'New request')['meta_id']);
        $this->assertContains($this->metaId('proc_request_form'), $tree['children'][$app]);
        // A form's lookups are its children; widgets never show up, only what they show.
        $this->assertContains($this->metaId('proc_items_grid'), $tree['children'][$this->metaId('proc_request_form')]);
        $widgets = Meta::whereHas('type', fn ($q) => $q->where('code', 'widget'))->pluck('id')->all();
        $this->assertSame([], array_intersect($widgets, array_merge(array_keys($tree['children']), ...array_values($tree['children']))));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/meta-tree')->assertForbidden();
    }
}
