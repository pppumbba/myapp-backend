<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Support\Market\MarketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyMembersTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    private User $manager;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://front.test']);
        $this->manager = $this->verifiedUser('УБ90010112');
        Sanctum::actingAs($this->manager);
        $this->company = Company::findOrFail($this->postJson('/api/market/companies', ['registry_number' => '5123456'])->json('company.id'));
    }

    /** The manager invites with these rights; returns the token from the link. */
    private function invite(array $rights): string
    {
        Sanctum::actingAs($this->manager);
        $url = $this->postJson("/api/market/companies/{$this->company->id}/invites", $rights)->assertCreated()->json('url');
        $this->assertStringStartsWith('http://front.test/market/join/', $url);

        return basename($url);
    }

    private function member(User $user): CompanyMember
    {
        return CompanyMember::where(['company_id' => $this->company->id, 'user_id' => $user->id])->sole();
    }

    public function test_a_manager_invites_a_colleague_with_chosen_rights(): void
    {
        $token = $this->invite(['can_bid' => true, 'can_sign' => false, 'can_manage' => false]);
        $colleague = $this->verifiedUser('УБ85051523', 'Colleague');
        Sanctum::actingAs($colleague);

        $this->postJson("/api/market/invites/{$token}/accept")->assertOk()->assertJsonPath('member.can_bid', true);

        $this->assertTrue(MarketAccess::canActFor($colleague, $this->company, 'bid'));
        $this->assertFalse(MarketAccess::canActFor($colleague, $this->company, 'sign'));
        Sanctum::actingAs($this->manager);
        $this->getJson("/api/market/companies/{$this->company->id}/members")->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['verified_name' => 'Colleague']);
    }

    public function test_the_last_manager_cannot_leave_or_drop_manage(): void
    {
        $mine = $this->member($this->manager);

        $this->deleteJson("/api/market/companies/{$this->company->id}/members/{$mine->id}")->assertStatus(422);
        $this->patchJson("/api/market/companies/{$this->company->id}/members/{$mine->id}", ['can_bid' => true, 'can_sign' => true, 'can_manage' => false])->assertStatus(422);
        $this->assertTrue($mine->fresh()->can_manage);

        // With a second manager, leaving is fine.
        $token = $this->invite(['can_bid' => true, 'can_sign' => true, 'can_manage' => true]);
        Sanctum::actingAs($this->verifiedUser('УБ85051523'));
        $this->postJson("/api/market/invites/{$token}/accept")->assertOk();
        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/market/companies/{$this->company->id}/members/{$mine->id}")->assertNoContent();
        $this->assertFalse(MarketAccess::canActFor($this->manager, $this->company, 'browse'));
    }

    public function test_members_without_manage_cannot_change_others(): void
    {
        $token = $this->invite(['can_bid' => true, 'can_sign' => false, 'can_manage' => false]);
        $colleague = $this->verifiedUser('УБ85051523');
        Sanctum::actingAs($colleague);
        $this->postJson("/api/market/invites/{$token}/accept")->assertOk();
        $managerRow = $this->member($this->manager);

        $this->patchJson("/api/market/companies/{$this->company->id}/members/{$managerRow->id}", ['can_bid' => false, 'can_sign' => false, 'can_manage' => false])->assertForbidden();
        $this->deleteJson("/api/market/companies/{$this->company->id}/members/{$managerRow->id}")->assertForbidden();
        $this->postJson("/api/market/companies/{$this->company->id}/invites", ['can_bid' => true])->assertForbidden();
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->getJson("/api/market/companies/{$this->company->id}/members")->assertForbidden();
    }

    public function test_an_invite_works_once(): void
    {
        $token = $this->invite(['can_bid' => true, 'can_sign' => false, 'can_manage' => false]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/market/invites/{$token}/accept")->assertForbidden();

        Sanctum::actingAs($this->verifiedUser('УБ85051523'));
        $this->postJson("/api/market/invites/{$token}/accept")->assertOk();
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->postJson("/api/market/invites/{$token}/accept")->assertNotFound();

        $late = $this->invite(['can_bid' => true]);
        $this->travel(8)->days();
        $this->postJson("/api/market/invites/{$late}/accept")->assertNotFound();
    }
}
