<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'secret',
            'services.google.redirect' => 'http://backend.test/auth/google/callback',
            'services.google.allowed_domain' => null,
            'services.google.signup' => true,
            'app.frontend_url' => 'http://front.test',
        ]);
    }

    /**
     * Goes through Google as this person and returns where the frontend was sent.
     *
     * @return array<string, string>
     */
    private function signInAs(array $info): array
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1']),
            'openidconnect.googleapis.com/*' => Http::response($info),
        ]);
        $toGoogle = $this->get('/auth/google/redirect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $toGoogle);
        parse_str((string) parse_url($toGoogle, PHP_URL_QUERY), $sent);

        $back = $this->get('/auth/google/callback?'.http_build_query(['code' => 'c-1', 'state' => $sent['state']]))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('http://front.test/login?', $back);
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_a_new_person_gets_an_account_and_signs_in_with_a_one_time_code(): void
    {
        $query = $this->signInAs(['sub' => 'g-1', 'email' => 'Ana@Gmail.com', 'email_verified' => true, 'name' => 'Ana']);

        $res = $this->postJson('/api/auth/google/exchange', ['code' => $query['google_code']])->assertOk();
        $user = User::where('email', 'ana@gmail.com')->sole();
        $this->assertSame($user->id, $res->json('user.id'));
        $this->assertNotEmpty($res->json('token'));
        // The first account is the admin, as with signing up.
        $this->assertTrue($user->isAdmin());
        $this->assertSame('g-1', SocialAccount::where('user_id', $user->id)->value('provider_id'));

        // The code works once.
        $this->postJson('/api/auth/google/exchange', ['code' => $query['google_code']])->assertUnauthorized();
    }

    public function test_an_existing_user_is_linked_by_verified_email_and_found_by_google_id_after(): void
    {
        $user = User::factory()->create(['email' => 'bo@company.mn']);

        $this->signInAs(['sub' => 'g-2', 'email' => 'bo@company.mn', 'email_verified' => true]);
        $this->assertSame(1, User::count());
        $this->assertSame($user->id, SocialAccount::where('provider_id', 'g-2')->value('user_id'));

        // They change their Google email: still the same user.
        $query = $this->signInAs(['sub' => 'g-2', 'email' => 'bo.new@company.mn', 'email_verified' => true]);
        $this->postJson('/api/auth/google/exchange', ['code' => $query['google_code']])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_an_unverified_email_is_never_matched_to_an_account(): void
    {
        User::factory()->create(['email' => 'boss@company.mn']);

        $query = $this->signInAs(['sub' => 'g-3', 'email' => 'boss@company.mn', 'email_verified' => false]);

        $this->assertArrayHasKey('google_error', $query);
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_the_domain_and_sign_up_settings_keep_people_out(): void
    {
        config(['services.google.allowed_domain' => 'company.mn']);
        $this->assertStringContainsString('company.mn', $this->signInAs(['sub' => 'g-4', 'email' => 'x@gmail.com', 'email_verified' => true])['google_error']);

        config(['services.google.allowed_domain' => null, 'services.google.signup' => false]);
        $this->assertArrayHasKey('google_error', $this->signInAs(['sub' => 'g-5', 'email' => 'new@company.mn', 'email_verified' => true]));
        $this->assertSame(0, User::count());
    }

    public function test_a_callback_without_the_right_state_is_refused(): void
    {
        Http::fake();
        $this->get('/auth/google/redirect');

        $back = $this->get('/auth/google/callback?code=c-1&state=forged')->headers->get('Location');

        $this->assertStringContainsString('google_error', $back);
        Http::assertNothingSent();
    }

    public function test_the_providers_say_whether_google_is_set_up(): void
    {
        $this->getJson('/api/auth/providers')->assertExactJson(['google' => true]);
        config(['services.google.client_secret' => null]);
        $this->getJson('/api/auth/providers')->assertExactJson(['google' => false]);
    }
}
