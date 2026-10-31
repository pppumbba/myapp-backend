<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_lists_its_members_and_a_user_their_memberships(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $user->id, 'can_bid' => true]);

        $this->assertSame($user->id, $company->members->sole()->user_id);
        $this->assertCount(1, $user->companyMemberships);
        $this->assertFalse($user->isVerified());

        $user->forceFill(['registry_number' => 'УБ90010112', 'verified_name' => 'Бат', 'verified_at' => now()])->save();
        $this->assertTrue($user->fresh()->isVerified());
        $this->assertFalse($company->isFrozen());
    }
}
