<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\Role;
use App\Models\User;
use App\Models\WfmStatus;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['code' => $code], ['name' => ucfirst($code)])->id);

        return $user;
    }

    private function statusId(string $code): int
    {
        return WfmStatus::where('workflow_meta_id', $this->metaId('proc_order_workflow'))->where('code', $code)->value('id');
    }

    public function test_only_admins_change_metas_and_workflows(): void
    {
        $grid = $this->metaId('proc_items_grid');
        $workflow = $this->metaId('proc_order_workflow');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // A meta with no permission rows is for admins only.
        $this->getJson("/api/metas/{$grid}")->assertForbidden();
        $this->getJson("/api/metas/{$grid}/data")->assertForbidden();
        $this->assertNotContains($grid, collect($this->getJson('/api/metas')->assertOk()->json())->pluck('id'));
        $this->getJson('/api/user/access')->assertOk()->assertJsonMissingPath("metas.{$grid}");

        $this->allow($user, [$grid, $workflow]);
        $this->getJson("/api/metas/{$grid}")->assertOk();
        $this->putJson("/api/metas/{$grid}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/metas/{$grid}")->assertForbidden();
        $this->postJson("/api/metas/{$grid}/actions", ['action' => 'add', 'label' => 'X', 'form_meta_id' => $grid])->assertForbidden();
        $this->putJson("/api/workflows/{$workflow}/layout", ['layout' => []])->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/metas/{$grid}", ['name' => 'Items'])->assertOk();
    }

    public function test_meta_permissions_limit_who_sees_and_writes_records(): void
    {
        $grid = $this->metaId('proc_items_grid');
        $form = $this->metaId('proc_item_form');
        $viewer = $this->userWithRole('viewer');
        $clerk = $this->userWithRole('clerk');
        $outsider = User::factory()->create();

        Sanctum::actingAs($this->admin);
        $role = fn (string $code) => Role::where('code', $code)->value('id');
        $this->putJson("/api/metas/{$grid}/permissions", ['rows' => [
            ['role_id' => $role('viewer'), 'can_view' => true],
            ['role_id' => $role('clerk'), 'can_view' => true],
        ]])->assertOk()->assertJsonCount(2);
        $this->putJson("/api/metas/{$form}/permissions", ['rows' => [
            ['role_id' => $role('clerk'), 'can_view' => true, 'can_create' => true],
        ]])->assertOk();

        Sanctum::actingAs($outsider);
        $this->getJson("/api/metas/{$grid}/data")->assertForbidden();
        $this->assertNotContains($grid, collect($this->getJson('/api/metas')->assertOk()->json())->pluck('id'));

        Sanctum::actingAs($viewer);
        $this->getJson("/api/metas/{$grid}/data")->assertOk();
        $this->postJson("/api/metas/{$form}/data", ['code' => 'V-1', 'name' => 'Nope', 'unit' => 'box', 'unit_price' => 1])->assertForbidden();
        $this->getJson('/api/user/access')
            ->assertOk()
            ->assertJsonPath('is_admin', false)
            ->assertJsonPath("metas.{$grid}.view", true)
            ->assertJsonPath("metas.{$form}.create", false);

        Sanctum::actingAs($clerk);
        $id = $this->postJson("/api/metas/{$form}/data", ['code' => 'C-1', 'name' => 'Allowed', 'unit' => 'box', 'unit_price' => 1])
            ->assertCreated()->json('id');
        $this->deleteJson("/api/metas/{$form}/data/{$id}")->assertForbidden();
        $this->assertDatabaseHas('proc_items', ['id' => $id]);
    }

    public function test_status_permissions_hide_records_and_guard_moves(): void
    {
        $workflow = $this->metaId('proc_order_workflow');
        $grid = $this->metaId('proc_orders_grid');
        $order = DB::table('proc_purchase_orders')->where('po_number', 'PO-2026-0103')->first();
        $buyer = $this->allow($this->userWithRole('buyer'), [$grid, $workflow]);
        $approver = $this->allow($this->userWithRole('approver'), [$grid, $workflow]);
        $outsider = $this->allow(User::factory()->create(), [$grid, $workflow]);
        $role = fn (string $code) => Role::where('code', $code)->value('id');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/workflows/{$workflow}/permissions", ['rows' => [
            ['status_id' => $this->statusId('draft'), 'role_id' => $role('buyer'), 'can_see' => true],
            ['status_id' => $this->statusId('pending'), 'role_id' => $role('buyer'), 'can_enter' => true],
            ['status_id' => $this->statusId('approved'), 'role_id' => $role('approver'), 'can_enter' => true],
        ]])->assertOk()->assertJsonCount(3);

        // Drafts are only for buyers.
        Sanctum::actingAs($outsider);
        $this->getJson("/api/metas/{$grid}/data?filter[status_id]=".$this->statusId('draft'))->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/workflows/{$workflow}/records/{$order->id}")->assertNotFound();

        Sanctum::actingAs($approver);
        $this->getJson("/api/workflows/{$workflow}/records/{$order->id}")->assertNotFound();

        Sanctum::actingAs($buyer);
        $this->getJson("/api/metas/{$grid}/data?filter[status_id]=".$this->statusId('draft'))->assertOk()->assertJsonPath('total', 1);
        $state = $this->getJson("/api/workflows/{$workflow}/records/{$order->id}")->assertOk()->json();
        $toPending = collect($state['transitions'])->firstWhere('next_status.code', 'pending');
        $this->assertTrue($toPending['allowed']);

        // Moving into pending tells the approvers, who may take it on to approved.
        $this->postJson("/api/workflows/{$workflow}/records/{$order->id}/move", ['transition_id' => $toPending['id'], 'source_id' => $grid])
            ->assertOk()->assertJsonPath('status.code', 'pending');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $approver->id, 'link' => "/run/{$grid}?record={$order->id}"]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $buyer->id]);

        // Only approvers may approve.
        $state = $this->getJson("/api/workflows/{$workflow}/records/{$order->id}")->assertOk()->json();
        $toApproved = collect($state['transitions'])->firstWhere('next_status.code', 'approved');
        $this->assertFalse($toApproved['allowed']);
        $this->postJson("/api/workflows/{$workflow}/records/{$order->id}/move", ['transition_id' => $toApproved['id']])->assertForbidden();

        Sanctum::actingAs($approver);
        $this->postJson("/api/workflows/{$workflow}/records/{$order->id}/move", ['transition_id' => $toApproved['id']])
            ->assertOk()->assertJsonPath('status.code', 'approved');
    }

    public function test_roles_are_managed_and_an_admin_always_remains(): void
    {
        Sanctum::actingAs($this->admin);
        $adminRole = Role::where('code', Role::ADMIN)->value('id');

        $role = $this->postJson('/api/roles', ['code' => 'buyer', 'name' => 'Buyer'])->assertCreated()->json('id');
        $this->putJson("/api/users/{$this->admin->id}/roles", ['role_ids' => [$role]])->assertUnprocessable();
        $this->deleteJson("/api/roles/{$adminRole}")->assertUnprocessable();

        $other = User::factory()->create();
        $this->putJson("/api/users/{$other->id}/roles", ['role_ids' => [$role, $adminRole]])->assertOk()->assertJsonCount(2, 'roles');
        $this->putJson("/api/users/{$this->admin->id}/roles", ['role_ids' => [$role]])->assertOk();
        $this->assertFalse($this->admin->isAdmin());
    }

    public function test_the_first_account_registered_is_an_admin(): void
    {
        User::query()->delete();
        $register = fn (string $email) => $this->postJson('/api/register', [
            'name' => 'Someone', 'email' => $email, 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        $register('first@example.com');
        $register('second@example.com');

        $this->assertTrue(User::where('email', 'first@example.com')->first()->isAdmin());
        $this->assertFalse(User::where('email', 'second@example.com')->first()->isAdmin());
    }
}
