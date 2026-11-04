<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_customer_makes_its_owner_admin(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/customers', ['name' => 'Acme LLC'])
            ->assertCreated()
            ->assertJsonPath('customer.code', 'acme-llc');

        $customer = Customer::where('code', 'acme-llc')->firstOrFail();
        $this->assertTrue(CustomerUser::where('customer_id', $customer->id)->where('user_id', $user->id)->exists());
        $this->assertSame($user->id, $customer->created_by);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('customer.code', 'acme-llc')
            ->assertJsonPath('customers.0.is_admin', true);
    }

    public function test_the_old_token_is_replaced(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t');

        $this->withToken($token->plainTextToken)->postJson('/api/customers', ['name' => 'Acme'])->assertCreated();

        $this->assertNull($token->accessToken->fresh());
    }

    public function test_creating_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        foreach (range(1, 3) as $n) {
            $token = $this->withToken($token)->postJson('/api/customers', ['name' => "Acme {$n}"])->assertCreated()->json('token');
            $this->app['auth']->forgetGuards();
        }
        $this->withToken($token)->postJson('/api/customers', ['name' => 'Acme 4'])->assertTooManyRequests();
    }

    public function test_a_name_is_needed(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)->postJson('/api/customers', ['name' => ''])->assertJsonValidationErrors('name');
    }
}
