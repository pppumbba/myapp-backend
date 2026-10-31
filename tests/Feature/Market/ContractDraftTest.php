<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\ContractSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractDraftTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function body(Company $buyerCompany, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Notebooks',
            'buyer_company_id' => $buyerCompany->id,
            'vendor_registry_number' => '6234567',
            'items' => [['name' => 'Notebook', 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 1500000]],
            'discount' => 100000,
            'middleman' => 'off',
            'steps' => [['label' => 'Advance', 'percent' => 30], ['label' => 'On delivery', 'percent' => 70]],
        ];
    }

    public function test_a_buyer_drafts_a_contract_and_the_vendor_sees_it(): void
    {
        $buyer = $this->verifiedUser('УБ90010112');
        $vendor = $this->verifiedUser('УБ85051523');
        $buyerCompany = $this->companyOf($buyer, '5123456');
        $this->companyOf($vendor, '6234567');
        Sanctum::actingAs($buyer);

        $id = $this->postJson('/api/market/contracts', $this->body($buyerCompany))->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('side', 'buyer')
            ->assertJsonPath('total', 2900000)
            ->assertJsonPath('steps.0.amount', 870000)
            ->assertJsonPath('steps.1.amount', 2030000)
            ->assertJsonPath('vendor.name', 'Талст Үйлчилгээ ХХК')
            ->json('id');

        Sanctum::actingAs($vendor);
        $this->getJson('/api/market/contracts')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.side', 'vendor');
        $this->getJson("/api/market/contracts/{$id}")->assertOk()->assertJsonPath('can.edit', true);
        $this->assertSame(1, $vendor->userNotifications()->count());
    }

    public function test_a_verified_person_can_be_the_vendor(): void
    {
        $buyer = $this->verifiedUser('УБ90010112');
        $person = $this->verifiedUser('ДА92120334');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/market/contracts', $this->body($this->companyOf($buyer, '5123456'), ['vendor_registry_number' => 'да92120334']))
            ->assertCreated()->assertJsonPath('vendor.type', 'person');

        Sanctum::actingAs($person);
        $this->getJson('/api/market/contracts')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_company_or_an_unverified_user_cannot_read_or_change_it(): void
    {
        ['contract' => $contract] = $this->draftContract();

        foreach ([$this->verifiedUser('ДА92120334'), User::factory()->create()] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/market/contracts/{$contract->id}")->assertForbidden();
            $this->putJson("/api/market/contracts/{$contract->id}", ['title' => 'Mine now'])->assertForbidden();
            $this->postJson("/api/market/contracts/{$contract->id}/cancel")->assertForbidden();
            $this->getJson('/api/market/contracts')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_editing_bumps_the_version_and_drops_signatures(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->draftContract();
        ContractSignature::create(['contract_id' => $contract->id, 'side' => 'buyer', 'user_id' => $buyer->id, 'registry_number' => 'УБ90010112', 'verified_name' => 'Buyer Person', 'version' => 1, 'signed_at' => now()]);
        $contract->update(['status' => 'signing']);
        Sanctum::actingAs($vendor);

        $this->putJson("/api/market/contracts/{$contract->id}", ['discount' => 0])->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total', 3000000)
            ->assertJsonCount(0, 'signatures');
        $this->assertSame(0, $contract->signatures()->count());
        $this->assertSame('edited', $contract->events()->pluck('type')->last());
    }

    public function test_steps_must_add_up_to_100_and_the_parties_must_differ(): void
    {
        $buyer = $this->verifiedUser('УБ90010112');
        $company = $this->companyOf($buyer, '5123456');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/market/contracts', $this->body($company, ['steps' => [['label' => 'A', 'percent' => 30], ['label' => 'B', 'percent' => 60]]]))->assertStatus(422);
        $this->postJson('/api/market/contracts', $this->body($company, ['vendor_registry_number' => '5123456']))->assertStatus(422);
        $this->postJson('/api/market/contracts', $this->body($company, ['vendor_registry_number' => '9999999']))->assertStatus(422);
        $this->postJson('/api/market/contracts', $this->body($company, ['discount' => 5000000]))->assertStatus(422);
    }

    public function test_either_side_cancels_before_it_is_signed_and_then_it_is_closed(): void
    {
        ['vendor' => $vendor, 'contract' => $contract] = $this->draftContract();
        Sanctum::actingAs($vendor);

        $this->postJson("/api/market/contracts/{$contract->id}/cancel", ['reason' => 'Out of stock'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->putJson("/api/market/contracts/{$contract->id}", ['title' => 'Again'])->assertStatus(422);
    }
}
