<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UseCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_without_a_customer_must_pick(): void
    {
        [$user, $token] = $this->member();

        $this->withToken($token)->getJson('/api/metas')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pick a customer.')
            ->assertJsonPath('customers.0.code', 'test')
            ->assertJsonPath('customers.0.is_admin', false);
    }

    public function test_a_removed_member_is_refused(): void
    {
        [$user, $token] = $this->member(bound: true);
        CustomerUser::where('user_id', $user->id)->delete();

        $this->withToken($token)->getJson('/api/metas')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are no longer in this customer.')
            ->assertJsonPath('customers', []);
    }

    public function test_user_shows_the_current_customer(): void
    {
        [$user, $token] = $this->member(bound: true);

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('customer.code', 'test')
            ->assertJsonPath('customers.0.code', 'test');
    }

    public function test_user_without_a_customer_still_answers(): void
    {
        [$user, $token] = $this->member();

        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('customer', null)->assertJsonPath('id', $user->id);
    }

    /** @return array{User, string} a member of the test customer and a token, bound to it or not */
    private function member(bool $bound = false): array
    {
        $user = User::factory()->create();
        CustomerUser::create(['customer_id' => $this->testCustomer->id, 'user_id' => $user->id]);
        $token = $user->createToken('t');
        if ($bound) {
            $token->accessToken->forceFill(['customer_id' => $this->testCustomer->id])->save();
        }

        return [$user, $token->plainTextToken];
    }
}
