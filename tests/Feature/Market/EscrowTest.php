<?php

namespace Tests\Feature\Market;

use App\Models\Contract;
use App\Models\ContractStep;
use App\Models\User;
use App\Support\Market\Contracts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EscrowTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    /** @return array{buyer: User, vendor: User, contract: Contract, step: ContractStep} */
    private function escrowContract(string $middleman = 'escrow'): array
    {
        $parties = $this->draftContract(['middleman' => $middleman]);
        $parties['contract']->forceFill(['status' => 'active', 'signed_at' => now()])->save();
        $parties['step'] = $parties['contract']->steps()->first();

        return $parties;
    }

    private function stepAction(User $user, ContractStep $step, string $action, array $body = [], int $status = 200): array
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/market/contract-steps/{$step->id}/{$action}", $body)->assertStatus($status)->json() ?? [];
    }

    /** The buyer funds the step and pays the escrow invoice on the fake provider. */
    private function fund(User $buyer, ContractStep $step): void
    {
        $payment = $this->stepAction($buyer, $step, 'fund', [], 201);
        $this->assertSame(870000, (int) $payment['amount']);
        $this->get("/market/pay/fake/{$payment['id']}/pay");
        $this->assertSame('funded', $step->fresh()->status);
    }

    public function test_the_buyer_funds_a_step_the_vendor_asks_for_it_and_the_buyer_releases_it(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'step' => $step] = $this->escrowContract();
        $this->fund($buyer, $step);

        $this->stepAction($vendor, $step, 'request');
        $this->assertSame('requested', $step->fresh()->status);
        $this->stepAction($buyer, $step, 'reject-release', ['reason' => 'Not delivered yet']);
        $this->assertSame('funded', $step->fresh()->status);
        $this->stepAction($vendor, $step, 'request');
        $this->stepAction($buyer, $step, 'release');

        $this->assertSame('released', $step->fresh()->status);
        $this->assertSame(['held', 'released'], $step->entries()->orderBy('id')->pluck('type')->all());
    }

    public function test_a_requested_step_releases_itself_after_seven_days(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'step' => $step] = $this->escrowContract();
        $this->fund($buyer, $step);
        $this->stepAction($vendor, $step, 'request');

        $this->travel(6)->days();
        $this->artisan('market:release-due')->expectsOutput('0 payment steps released.')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('market:release-due')->expectsOutput('1 payment steps released.')->assertSuccessful();

        $this->assertSame('released', $step->fresh()->status);
    }

    public function test_a_dispute_freezes_held_money_until_staff_decide(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract, 'step' => $step] = $this->escrowContract();
        $this->fund($buyer, $step);
        $this->stepAction($vendor, $step, 'request');

        Sanctum::actingAs($buyer);
        $this->postJson("/api/market/contracts/{$contract->id}/disputes", ['reason' => 'Wrong model delivered'])->assertCreated();
        $this->postJson("/api/market/contracts/{$contract->id}/disputes", ['reason' => 'Again'])->assertStatus(422);
        $this->assertSame('frozen', $step->fresh()->status);

        // Frozen money never releases by itself.
        $this->travel(8)->days();
        $this->artisan('market:release-due')->expectsOutput('0 payment steps released.');

        Sanctum::actingAs($vendor);
        $this->getJson('/api/market/disputes')->assertForbidden();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $dispute = $this->getJson('/api/market/disputes')->assertOk()
            ->assertJsonPath('data.0.reason', 'Wrong model delivered')
            ->assertJsonPath('data.0.contract.number', $contract->number)
            ->json('data.0');
        $this->postJson("/api/market/disputes/{$dispute['id']}/decide", ['resolution' => 'refund'])->assertStatus(422);
        $this->postJson("/api/market/disputes/{$dispute['id']}/decide", ['resolution' => 'refund', 'decision' => 'Vendor sent the wrong model.'])->assertOk();

        $this->assertSame('refunded', $step->fresh()->status);
        $this->assertSame('refunded', $step->fresh()->entries()->latest('id')->value('type'));
        $this->assertTrue($vendor->userNotifications()->where('body', 'Vendor sent the wrong model.')->exists());
        $this->postJson("/api/market/disputes/{$dispute['id']}/decide", ['resolution' => 'release', 'decision' => 'x'])->assertStatus(422);
    }

    public function test_confirming_the_delivery_releases_what_is_still_held(): void
    {
        ['buyer' => $buyer, 'contract' => $contract, 'step' => $step] = $this->escrowContract();
        $this->fund($buyer, $step);
        $contract->forceFill(['status' => 'delivered'])->save();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/market/contracts/{$contract->id}/confirm")->assertOk();

        $this->assertSame('released', $step->fresh()->status);
    }

    public function test_escrow_needs_an_escrow_contract_and_the_right_side(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract, 'step' => $step] = $this->escrowContract('off');

        $this->stepAction($buyer, $step, 'fund', [], 422);
        Sanctum::actingAs($buyer);
        $this->postJson("/api/market/contracts/{$contract->id}/disputes", ['reason' => 'x'])->assertStatus(422);

        ['buyer' => $buyer, 'vendor' => $vendor, 'step' => $step] = $this->escrowContractAgain();
        $this->stepAction($vendor, $step, 'fund', [], 403);
        $this->stepAction($this->verifiedUser('ДА92120334'), $step, 'fund', [], 403);
        $this->stepAction($buyer, $step, 'release', [], 422);
    }

    /** A second escrow contract between the same companies. */
    private function escrowContractAgain(): array
    {
        $first = Contract::firstOrFail();
        $buyer = $first->buyerCompany->members()->first()->user;
        $vendor = $first->vendorCompany->members()->first()->user;
        $contract = Contracts::draft($buyer, [
            'title' => 'Chairs', 'buyer_company_id' => $first->buyer_company_id, 'vendor_registry_number' => '6234567',
            'items' => [['name' => 'Chair', 'quantity' => 10, 'unit_price' => 87000]], 'middleman' => 'escrow',
        ]);
        $contract->forceFill(['status' => 'active'])->save();

        return ['buyer' => $buyer, 'vendor' => $vendor, 'step' => $contract->steps()->first()];
    }
}
