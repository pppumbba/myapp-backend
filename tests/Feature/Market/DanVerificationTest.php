<?php

namespace Tests\Feature\Market;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DanVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://front.test', 'market.dan.driver' => 'fake']);
    }

    /** Starts DAN as this user and returns the state the fake page was sent. */
    private function start(User $user): string
    {
        Sanctum::actingAs($user);
        $url = $this->postJson('/api/market/dan/start')->assertOk()->json('url');
        $this->assertStringContainsString('/auth/dan/fake?state=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    /**
     * Comes back from DAN and returns the frontend query it was sent to.
     *
     * @return array<string, string>
     */
    private function comeBack(string $state, string $registryNumber): array
    {
        $back = $this->get('/auth/dan/callback?'.http_build_query(['state' => $state, 'code' => 'fake-'.$registryNumber]))
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('http://front.test/market/account?', $back);
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_a_user_verifies_through_the_fake_dan_and_is_marked_verified(): void
    {
        $user = User::factory()->create();
        $state = $this->start($user);

        $this->get('/auth/dan/fake?state='.$state)->assertOk()->assertSee('УБ90010112')->assertSee(http_build_query(['code' => 'fake-УБ90010112']), false);
        $this->assertSame(['dan' => 'ok'], $this->comeBack($state, 'УБ90010112'));

        $user->refresh();
        $this->assertSame('УБ90010112', $user->registry_number);
        $this->assertSame('Бат-Эрдэнэ Ганбаатар', $user->verified_name);
        $this->assertTrue($user->isVerified());
        $this->getJson('/api/market/me')->assertOk()->assertJsonPath('verified.registry_number', 'УБ90010112');
    }

    public function test_the_same_person_cannot_verify_a_second_account(): void
    {
        $first = User::factory()->create();
        $this->comeBack($this->start($first), 'УБ90010112');

        $second = User::factory()->create();
        $query = $this->comeBack($this->start($second), 'УБ90010112');

        $this->assertSame('This person is already verified on another account.', $query['dan_error']);
        $this->assertFalse($second->fresh()->isVerified());
        $this->assertSame('УБ90010112', $first->fresh()->registry_number);
    }

    public function test_a_reused_or_unknown_state_verifies_nobody(): void
    {
        $user = User::factory()->create();
        $state = $this->start($user);
        $this->comeBack($state, 'УБ90010112');
        $user->forceFill(['registry_number' => null, 'verified_name' => null, 'verified_at' => null])->save();

        $this->assertArrayHasKey('dan_error', $this->comeBack($state, 'УБ85051523'));
        $this->assertArrayHasKey('dan_error', $this->comeBack('made-up', 'УБ85051523'));
        $this->assertFalse($user->fresh()->isVerified());
    }

    public function test_the_fake_page_is_missing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get('/auth/dan/fake?state=x')->assertNotFound();
    }
}
