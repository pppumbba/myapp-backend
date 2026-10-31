<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\Contract;
use App\Models\MarketFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contract_holds_items_steps_and_parties(): void
    {
        $buyer = Company::factory()->create();
        $vendor = User::factory()->create();
        $contract = Contract::factory()->create(['buyer_company_id' => $buyer->id, 'vendor_user_id' => $vendor->id]);
        $contract->items()->create(['name' => 'Notebook', 'quantity' => 2, 'unit_price' => 1500000, 'amount' => 3000000]);
        $contract->steps()->create(['label' => 'Advance', 'percent' => 30, 'amount' => 900000]);

        $this->assertMatchesRegularExpression('/^C-\d{4}-\d{6}$/', $contract->fresh()->number);
        $this->assertTrue($contract->buyerCompany->is($buyer));
        $this->assertTrue($contract->vendorUser->is($vendor));
        $this->assertSame('Notebook', $contract->items->first()->name);
        $this->assertSame('pending', $contract->steps->first()->status);
        $this->assertSame(['bidding', 'contract', 'middleman', 'posting'], MarketFee::orderBy('kind')->pluck('kind')->all());
    }
}
