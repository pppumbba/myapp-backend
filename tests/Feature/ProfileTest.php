<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    public function test_user_updates_name_and_email(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);
        $auth = $this->signIn($user);

        $this->putJson('/api/user', ['name' => 'New Name', 'email' => 'new@example.com'], $auth)
            ->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('email', 'new@example.com');

        $this->putJson('/api/user', ['name' => 'X', 'email' => 'taken@example.com'], $auth)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_preferences_merge_and_only_known_keys_are_kept(): void
    {
        $user = User::factory()->create(['preferences' => ['other' => 1]]);
        $auth = $this->signIn($user);

        $this->putJson('/api/user/preferences', ['form_saved_notice' => true, 'unknown' => 'x'], $auth)
            ->assertOk()
            ->assertJsonPath('preferences.form_saved_notice', true)
            ->assertJsonPath('preferences.other', 1)
            ->assertJsonMissingPath('preferences.unknown');

        $this->putJson('/api/user/preferences', ['form_saved_notice' => 'maybe'], $auth)->assertUnprocessable();
    }

    public function test_password_change_needs_the_current_password_and_signs_out_other_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $other = $user->createToken('other-device');
        $auth = $this->signIn($user);

        $this->putJson('/api/user/password', [
            'current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ], $auth)->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ], $auth)->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertSame(1, $user->tokens()->count());
    }
}
