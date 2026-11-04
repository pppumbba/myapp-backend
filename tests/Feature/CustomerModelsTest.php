<?php

namespace Tests\Feature;

use App\Models\CompanyMember;
use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Models\CustomerUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_lists_their_customers(): void
    {
        $user = User::factory()->outside()->create();
        $mine = Customer::create(['code' => 'mine', 'name' => 'Mine', 'schema_name' => 'cust_mine']);
        Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        CustomerUser::create(['customer_id' => $mine->id, 'user_id' => $user->id]);

        $this->assertSame([$mine->id], $user->customers()->pluck('customers.id')->all());
        $this->assertTrue($user->isIn($mine));
        $this->assertFalse($user->isIn($this->testCustomer));
    }

    public function test_codes_are_made_unique(): void
    {
        $this->assertSame('acme-llc', Customer::makeCode('Acme LLC'));
        Customer::create(['code' => 'acme-llc', 'name' => 'Acme LLC', 'schema_name' => 'cust_acme']);
        $this->assertSame('acme-llc-2', Customer::makeCode('Acme LLC'));
        $this->assertSame('mongol', Customer::makeCode('Монгол'));
        $this->assertSame('customer', Customer::makeCode('!!!'));
    }

    public function test_an_invite_stops_being_usable(): void
    {
        $invite = fn (array $extra) => new CustomerInvite($extra + ['customer_id' => $this->testCustomer->id, 'code' => 'ABCD2345', 'role_ids' => [], 'uses' => 0]);

        $this->assertTrue($invite([])->isUsable());
        $this->assertFalse($invite(['revoked_at' => now()])->isUsable());
        $this->assertFalse($invite(['expires_at' => now()->subDay()])->isUsable());
        $this->assertFalse($invite(['max_uses' => 1, 'uses' => 1])->isUsable());
        $this->assertTrue($invite(['max_uses' => 2, 'uses' => 1])->isUsable());
    }

    public function test_roles_still_read_from_the_customer_schema(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['code' => 'clerk', 'name' => 'Clerk']);

        // Reached from User (main), these must name their connection, or they'd follow User into main.
        foreach ([Role::class, UserNotification::class, CompanyMember::class] as $model) {
            $this->assertSame('customer', (new $model)->getConnectionName(), $model);
        }
        $user->roles()->attach($role->id);
        $this->assertSame([$role->id], $user->roles()->pluck('roles.id')->all());
    }

    public function test_the_theme_is_kept_per_customer(): void
    {
        $user = User::factory()->create();
        CustomerUser::updateOrCreate(['customer_id' => $this->testCustomer->id, 'user_id' => $user->id], ['theme_meta_id' => 7]);

        $this->assertSame(7, $user->fresh()->theme_meta_id);
    }
}
