<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Login, sign-up and password change are limited so passwords can't be guessed and accounts can't be flooded. */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_blocked_after_five_tries_for_one_account(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();

        // Another account from the same address still gets its own tries.
        $other = User::factory()->create();
        $this->postJson('/api/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
    }

    public function test_login_tries_count_the_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        foreach (['someone@example.com', 'SOMEONE@example.com', 'Someone@Example.com', 'someone@EXAMPLE.com', 'someONE@example.com'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    }

    public function test_one_address_cannot_make_more_than_five_accounts_a_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [
                'name' => "User $i",
                'email' => "user$i@example.com",
                'password' => 'Secret-pass-123',
                'password_confirmation' => 'Secret-pass-123',
            ])->assertCreated();
        }

        $this->postJson('/api/register', [
            'name' => 'One more',
            'email' => 'more@example.com',
            'password' => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
        ])->assertTooManyRequests();
    }

    public function test_the_current_password_cannot_be_guessed_with_a_token(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->putJson('/api/user/password', [
                'current_password' => "guess-$i",
                'password' => 'New-secret-123',
                'password_confirmation' => 'New-secret-123',
            ])->assertUnprocessable();
        }

        $this->withToken($token)->putJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'New-secret-123',
            'password_confirmation' => 'New-secret-123',
        ])->assertTooManyRequests();
    }
}
