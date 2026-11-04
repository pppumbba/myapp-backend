<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Support\CustomerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_up_gives_no_customer_and_no_admin(): void
    {
        User::query()->delete();

        $res = $this->postJson('/api/register', ['name' => 'First', 'email' => 'first@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123'])
            ->assertCreated()
            ->assertJsonPath('customers', []);

        $this->assertNull(PersonalAccessToken::findToken($res->json('token'))->customer_id);
        $this->assertFalse(User::where('email', 'first@example.com')->sole()->isAdmin());
    }

    public function test_one_customer_is_picked_automatically(): void
    {
        $user = $this->person();
        $this->link($user, $this->testCustomer);

        $token = $this->login($user)->assertOk()->assertJsonPath('customers.0.code', 'test')->json('token');

        $this->assertSame($this->testCustomer->id, PersonalAccessToken::findToken($token)->customer_id);
        $this->asToken($token)->getJson('/api/metas')->assertOk();
    }

    public function test_several_customers_need_a_pick(): void
    {
        $user = $this->person();
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        $this->link($user, $this->testCustomer);
        $this->link($user, $other);

        $token = $this->login($user)->assertOk()->assertJsonCount(2, 'customers')->json('token');
        $this->asToken($token)->getJson('/api/metas')->assertStatus(409);

        $picked = $this->asToken($token)->postJson("/api/customers/{$other->id}/use")->assertOk()->assertJsonPath('customer.code', 'other')->json('token');
        $this->asToken($picked)->getJson('/api/user')->assertJsonPath('customer.code', 'other');
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_use_refuses_a_customer_you_are_not_in(): void
    {
        $user = $this->person();
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);

        $this->asToken($user->createToken('t')->plainTextToken)->postJson("/api/customers/{$other->id}/use")->assertForbidden();
    }

    public function test_the_last_admin_cannot_leave(): void
    {
        $admin = User::factory()->admin()->create();
        $this->link($admin, $this->testCustomer);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/me/customers/{$this->testCustomer->id}")->assertStatus(422)->assertJsonPath('message', 'You are the last admin of this customer.');
        $this->assertTrue($admin->isIn($this->testCustomer));
    }

    public function test_a_member_leaves(): void
    {
        $user = $this->person();
        $this->link($user, $this->testCustomer);
        $user->roles()->attach(Role::create(['code' => 'clerk', 'name' => 'Clerk'])->id);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/me/customers/{$this->testCustomer->id}")->assertOk()->assertJsonPath('customers', []);
        $this->assertFalse($user->isIn($this->testCustomer));
        $this->assertSame([], $user->roles()->pluck('roles.id')->all());
    }

    public function test_an_admin_removes_a_member(): void
    {
        $admin = User::factory()->admin()->create();
        $this->link($admin, $this->testCustomer);
        $member = $this->person();
        $this->link($member, $this->testCustomer);
        $token = $member->createToken('t');
        $token->accessToken->forceFill(['customer_id' => $this->testCustomer->id])->save();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/customer/members/{$member->id}")->assertOk();

        $this->assertFalse($member->isIn($this->testCustomer));
        $this->assertNull($token->accessToken->fresh());
    }

    public function test_only_platform_owners_list_customers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform/customers')->assertForbidden();

        $owner = User::factory()->create();
        $owner->forceFill(['is_platform_owner' => true])->save();
        $this->link($owner, $this->testCustomer);
        Sanctum::actingAs($owner);
        $this->getJson('/api/platform/customers')->assertOk()->assertJsonPath('0.code', 'test')->assertJsonPath('0.members', 1);
    }

    private function person(): User
    {
        return User::factory()->create(['password' => Hash::make('secret-pass-123')]);
    }

    private function link(User $user, Customer $customer): void
    {
        CustomerUser::create(['customer_id' => $customer->id, 'user_id' => $user->id]);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-pass-123']);
    }

    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();
        CustomerSchema::forget();

        return $this->withToken($token);
    }
}
