<?php

namespace Tests\Feature\Market;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractFlowTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    /** @return array{buyer: User, vendor: User, contract: Contract} */
    private function activeContract(): array
    {
        $parties = $this->draftContract();
        $parties['contract']->forceFill(['status' => 'active', 'signed_at' => now()])->save();

        return $parties;
    }

    private function act(User $user, Contract $contract, string $action, array $body = [], int $status = 200): void
    {
        Sanctum::actingAs($user);
        $this->postJson("/api/market/contracts/{$contract->id}/{$action}", $body)->assertStatus($status);
    }

    public function test_the_vendor_works_and_delivers_and_the_buyer_confirms(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->activeContract();

        $this->act($buyer, $contract, 'start', [], 403);
        $this->act($vendor, $contract, 'start');
        $this->act($vendor, $contract, 'deliver', ['note' => 'Left at reception']);
        $this->act($buyer, $contract, 'reject', [], 422);
        $this->act($buyer, $contract, 'reject', ['reason' => 'One screen is cracked']);
        $this->assertSame('in_progress', $contract->fresh()->status);
        $this->assertTrue($vendor->userNotifications()->where('body', 'One screen is cracked')->exists());

        $this->act($vendor, $contract, 'deliver');
        $this->act($vendor, $contract, 'confirm', [], 403);
        $this->act($buyer, $contract, 'confirm');

        $contract->refresh();
        $this->assertSame('completed', $contract->status);
        $this->assertNotNull($contract->completed_at);
        $this->assertSame(['created', 'started', 'delivered', 'rejected', 'delivered', 'completed'], $contract->events()->pluck('type')->all());
    }

    public function test_each_side_rates_the_other_once_after_completion(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->activeContract();
        $this->act($buyer, $contract, 'rate', ['stars' => 5], 422);
        $contract->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->act($buyer, $contract, 'rate', ['stars' => 6], 422);
        $this->act($buyer, $contract, 'rate', ['stars' => 4, 'comment' => 'On time']);
        $this->act($buyer, $contract, 'rate', ['stars' => 1], 422);
        $this->act($vendor, $contract, 'rate', ['stars' => 5]);

        $this->getJson("/api/market/contracts/{$contract->id}")->assertOk()
            ->assertJsonPath('vendor.rating.average', 4)
            ->assertJsonPath('vendor.rating.count', 1)
            ->assertJsonPath('buyer.rating.average', 5)
            ->assertJsonPath('can.rate', false)
            ->assertJsonCount(2, 'ratings');
    }

    public function test_outsiders_cannot_move_the_contract(): void
    {
        ['contract' => $contract] = $this->activeContract();

        $this->act($this->verifiedUser('ДА92120334'), $contract, 'start', [], 403);
    }
}
