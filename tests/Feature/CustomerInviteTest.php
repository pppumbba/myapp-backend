<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Models\CustomerUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_makes_a_code_and_it_gives_its_roles(): void
    {
        $clerk = Role::create(['code' => 'clerk', 'name' => 'Clerk']);
        Sanctum::actingAs($this->admin());

        $code = $this->postJson('/api/customer/invites', ['role_ids' => [$clerk->id], 'max_uses' => 5])
            ->assertCreated()
            ->assertJsonPath('roles.0.name', 'Clerk')
            ->json('code');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);

        $joiner = User::factory()->create();
        $this->joinAs($joiner, $code)->assertOk()->assertJsonPath('customer.code', 'test');

        $this->assertTrue($joiner->isIn($this->testCustomer));
        $this->assertSame([$clerk->id], $joiner->fresh()->roleIds());
        $this->assertSame(1, CustomerInvite::where('code', $code)->value('uses'));
    }

    public function test_a_code_is_found_however_it_is_typed(): void
    {
        $this->invite(['code' => 'ABCD2345']);

        $this->joinAs(User::factory()->create(), ' abcd-2345 ')->assertOk();
    }

    public function test_joining_twice_keeps_one_membership(): void
    {
        $this->invite(['code' => 'ABCD2345']);
        $joiner = User::factory()->create();

        $this->joinAs($joiner, 'ABCD2345')->assertOk();
        $this->joinAs($joiner, 'ABCD2345')->assertOk();

        $this->assertSame(1, CustomerUser::where('user_id', $joiner->id)->count());
        $this->assertSame(1, CustomerInvite::where('code', 'ABCD2345')->value('uses'));
    }

    public function test_a_revoked_or_used_up_code_is_refused(): void
    {
        $this->invite(['code' => 'REVK2345', 'revoked_at' => now()]);
        $this->invite(['code' => 'USED2345', 'max_uses' => 1, 'uses' => 1]);

        $this->joinAs(User::factory()->create(), 'REVK2345')->assertStatus(422)->assertJsonPath('message', 'This code is not valid.');
        $this->joinAs(User::factory()->create(), 'USED2345')->assertStatus(422);
        $this->joinAs(User::factory()->create(), 'NOPE2345')->assertStatus(422);
    }

    public function test_non_admins_cannot_manage_invites(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/customer/invites')->assertForbidden();
        $this->postJson('/api/customer/invites', ['role_ids' => []])->assertForbidden();
    }

    public function test_an_admin_lists_and_revokes_codes(): void
    {
        Sanctum::actingAs($this->admin());
        $invite = $this->invite(['code' => 'ABCD2345']);

        $this->getJson('/api/customer/invites')->assertOk()->assertJsonPath('0.code', 'ABCD2345');
        $this->postJson("/api/customer/invites/{$invite->id}/revoke")->assertOk();

        $this->assertNotNull($invite->fresh()->revoked_at);
    }

    public function test_invites_of_another_customer_are_hidden(): void
    {
        Sanctum::actingAs($this->admin());
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        $theirs = $this->invite(['code' => 'THRS2345', 'customer_id' => $other->id]);

        $this->getJson('/api/customer/invites')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/customer/invites/{$theirs->id}/revoke")->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        CustomerUser::create(['customer_id' => $this->testCustomer->id, 'user_id' => $admin->id]);

        return $admin;
    }

    /** @param array<string, mixed> $values */
    private function invite(array $values): CustomerInvite
    {
        return CustomerInvite::create($values + ['customer_id' => $this->testCustomer->id, 'role_ids' => [], 'uses' => 0]);
    }

    private function joinAs(User $user, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken)->postJson('/api/customers/join', ['code' => $code]);
    }
}
