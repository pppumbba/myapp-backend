<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The guest limit on the routes guests may use (throttle:public) never counts signed-in users. */
class PublicThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_with_a_token_is_not_limited_like_a_guest(): void
    {
        $this->seed(ProcurementDemoSeeder::class);
        $token = User::factory()->admin()->create()->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user/access')->assertOk();

        $this->assertFalse($response->headers->has('X-RateLimit-Limit'), 'Signed-in requests got the guest limit.');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/user/access')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }
}
