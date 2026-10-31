<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\User;
use App\Support\Market\MarketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyRegistrationTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    public function test_the_director_registers_a_company_and_becomes_its_manager(): void
    {
        $director = $this->verifiedUser('УБ90010112');
        Sanctum::actingAs($director);

        $res = $this->postJson('/api/market/companies', ['registry_number' => ' 5123456 '])->assertCreated();

        $res->assertJsonPath('company.name', 'Говь Ханган ХХК')->assertJsonPath('member.can_manage', true);
        $company = Company::sole();
        $this->assertSame('active', $company->status);
        $this->assertNotNull($company->checked_at);
        foreach (['browse', 'bid', 'sign', 'manage'] as $right) {
            $this->assertTrue(MarketAccess::canActFor($director, $company, $right), $right);
        }
    }

    public function test_an_unverified_user_cannot_register_a_company(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/market/companies', ['registry_number' => '5123456'])->assertForbidden();
        $this->assertSame(0, Company::count());
    }

    public function test_an_unknown_or_liquidated_company_is_refused(): void
    {
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));

        $this->postJson('/api/market/companies', ['registry_number' => '2345678'])->assertStatus(422)->assertJsonPath('message', 'This company is liquidated.');
        $this->postJson('/api/market/companies', ['registry_number' => '9999999'])->assertStatus(422)->assertJsonPath('message', 'ХУР has no company with this registry number.');
        $this->postJson('/api/market/companies', ['registry_number' => '12ab'])->assertStatus(422);
        $this->assertSame(0, Company::count());
    }

    public function test_registering_a_company_already_on_the_platform_returns_it_without_membership(): void
    {
        Sanctum::actingAs($this->verifiedUser('УБ90010112'));
        $id = $this->postJson('/api/market/companies', ['registry_number' => '5123456'])->json('company.id');

        $other = $this->verifiedUser('УБ85051523');
        Sanctum::actingAs($other);
        $res = $this->postJson('/api/market/companies', ['registry_number' => '5123456'])->assertCreated();

        $res->assertJsonPath('company.id', $id)->assertJsonPath('member', null);
        $this->assertSame(1, Company::count());
        $this->assertFalse(MarketAccess::canActFor($other, Company::find($id), 'browse'));
    }

    public function test_me_lists_memberships(): void
    {
        Sanctum::actingAs($this->verifiedUser('УБ90010112'));
        $this->postJson('/api/market/companies', ['registry_number' => '5123456']);

        $this->getJson('/api/market/me')->assertOk()
            ->assertJsonPath('memberships.0.company.registry_number', '5123456')
            ->assertJsonPath('memberships.0.company.frozen', false)
            ->assertJsonPath('memberships.0.can_sign', true)
            ->assertJsonPath('requests', []);
    }
}
