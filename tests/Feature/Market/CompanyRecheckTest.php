<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Support\Market\MarketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyRecheckTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    public function test_a_company_liquidated_in_khur_is_frozen_and_members_lose_rights(): void
    {
        $director = $this->verifiedUser('УБ90010112');
        Sanctum::actingAs($director);
        $company = Company::findOrFail($this->postJson('/api/market/companies', ['registry_number' => '5123456'])->json('company.id'));

        $companies = config('market.fake.companies');
        $companies[0]['status'] = 'liquidated';
        config(['market.fake.companies' => $companies]);
        $this->travel(8)->days();

        $this->artisan('market:recheck-companies')->expectsOutput('1 companies checked, 1 changed.')->assertSuccessful();

        $company->refresh();
        $this->assertSame('liquidated', $company->status);
        $this->assertTrue($company->isFrozen());
        $this->assertFalse(MarketAccess::canActFor($director, $company, 'bid'));
        $this->assertSame(1, $director->userNotifications()->count());

        // Checked just now: the next run leaves it alone.
        $this->artisan('market:recheck-companies')->expectsOutput('0 companies checked, 0 changed.')->assertSuccessful();
    }
}
