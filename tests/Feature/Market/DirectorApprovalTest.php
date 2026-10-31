<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\RepresentationRequest;
use App\Models\User;
use App\Support\Market\MarketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectorApprovalTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    private User $requester;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://front.test']);
        // 6234567's director is УБ85051523; the requester is someone else.
        $this->requester = $this->verifiedUser('ДА92120334');
        Sanctum::actingAs($this->requester);
        $this->company = Company::findOrFail($this->postJson('/api/market/companies', ['registry_number' => '6234567'])->json('company.id'));
    }

    /** Asks the director and returns the token from the approval link. */
    private function askDirector(): string
    {
        Sanctum::actingAs($this->requester);
        $url = $this->postJson("/api/market/companies/{$this->company->id}/representations", ['method' => 'director'])
            ->assertCreated()->json('approve_url');
        $this->assertStringStartsWith('http://front.test/market/approve/', $url);

        return basename($url);
    }

    public function test_the_director_approves_and_the_requester_becomes_a_manager(): void
    {
        $token = $this->askDirector();
        Sanctum::actingAs($this->verifiedUser('УБ85051523'));

        $this->getJson("/api/market/approvals/{$token}")->assertOk()
            ->assertJsonPath('company.name', 'Талст Үйлчилгээ ХХК')
            ->assertJsonPath('requester.verified_name', 'Test Person')
            ->assertJsonPath('method', 'director');
        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'approve'])->assertOk();

        $this->assertSame('approved', RepresentationRequest::sole()->status);
        $this->assertTrue(MarketAccess::canActFor($this->requester, $this->company, 'manage'));
        $this->assertSame(1, $this->requester->userNotifications()->count());
    }

    public function test_someone_who_is_not_the_director_cannot_decide(): void
    {
        $token = $this->askDirector();
        Sanctum::actingAs($this->verifiedUser('УБ90010112'));

        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'approve'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'approve'])->assertForbidden();

        $this->assertSame('pending', RepresentationRequest::sole()->status);
        $this->assertFalse(MarketAccess::canActFor($this->requester, $this->company, 'browse'));
    }

    public function test_an_approval_link_works_once_and_expires(): void
    {
        $token = $this->askDirector();
        $director = $this->verifiedUser('УБ85051523');
        Sanctum::actingAs($director);
        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'reject'])->assertOk();
        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'approve'])->assertNotFound();
        $this->assertSame('rejected', RepresentationRequest::sole()->status);

        $late = $this->askDirector();
        $this->travel(15)->days();
        Sanctum::actingAs($director);
        $this->getJson("/api/market/approvals/{$late}")->assertNotFound();
        $this->postJson("/api/market/approvals/{$late}", ['decision' => 'approve'])->assertNotFound();
    }

    public function test_a_member_cannot_request_again(): void
    {
        $token = $this->askDirector();
        $this->postJson("/api/market/companies/{$this->company->id}/representations", ['method' => 'director'])->assertStatus(422);

        Sanctum::actingAs($this->verifiedUser('УБ85051523'));
        $this->postJson("/api/market/approvals/{$token}", ['decision' => 'approve'])->assertOk();
        Sanctum::actingAs($this->requester);
        $this->postJson("/api/market/companies/{$this->company->id}/representations", ['method' => 'director'])->assertStatus(422);
    }
}
