<?php

namespace Tests\Feature\Market;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Tender;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Market\Payments;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderAwardTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_awarding_drafts_a_contract_per_winner(): void
    {
        ['poster' => $poster, 'company' => $buyer, 'tender' => $tender] = $this->openTender(['rule' => ['middleman' => 'escrow']]);
        $winner = $this->verifiedUser('УБ85051523');
        $vendor = $this->companyOf($winner, '6234567');
        $loser = $this->verifiedUser('ДА92120334');
        $won = $this->rawBid($tender, $winner, $vendor, [2700000, 30000]);
        $this->rawBid($tender, $loser, null, [2800000, 31000]);
        $tender->update(['status' => 'evaluating']);

        Sanctum::actingAs($loser);
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $won->id]]])->assertForbidden();

        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $won->id]]])->assertOk()
            ->assertJsonPath('status', 'awarded')
            ->assertJsonCount(1, 'awards')
            ->assertJsonPath('awards.0.party.name', $vendor->name);

        $contract = Contract::firstOrFail();
        $this->assertSame('tender', $contract->source);
        $this->assertSame($tender->id, (int) $contract->source_id);
        $this->assertSame($buyer->id, $contract->buyer_company_id);
        $this->assertSame($vendor->id, $contract->vendor_company_id);
        $this->assertSame('escrow', $contract->middleman);
        $this->assertEquals(27300000, $contract->total);
        $this->assertSame(['Laptop', 'Mouse'], $contract->items()->orderBy('position')->pluck('name')->all());
        $this->assertSame(2, $tender->awards()->where('contract_id', $contract->id)->count());

        $this->assertTrue(UserNotification::where('user_id', $loser->id)->where('title', 'like', '%not chosen%')->exists());
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('awards.0.contract.number', $contract->number);
        Sanctum::actingAs($loser);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('awards', null);
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $won->id]]])->assertStatus(422);
    }

    public function test_split_awards_by_item_and_quantity(): void
    {
        ['poster' => $poster, 'company' => $buyer, 'tender' => $tender] = $this->openTender(['rule' => ['split' => 'item']]);
        $alice = $this->verifiedUser('ДА92120334');
        $bob = $this->verifiedUser('ЖЧ88010101');
        $a = $this->rawBid($tender, $alice, null, [2700000, 35000]);
        $b = $this->rawBid($tender, $bob, null, [2800000, 30000]);
        [$laptop, $mouse] = [$tender->items[0], $tender->items[1]];
        $tender->update(['status' => 'evaluating']);
        Sanctum::actingAs($poster);

        // Whole-tender lines don't fit an item split; an item can't go twice.
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $a->id]]])->assertStatus(422);
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $a->id, 'item_id' => $laptop->id], ['bid_id' => $b->id, 'item_id' => $laptop->id]]])->assertStatus(422);

        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $a->id, 'item_id' => $laptop->id], ['bid_id' => $b->id, 'item_id' => $mouse->id]]])
            ->assertOk()->assertJsonCount(2, 'awards');
        $this->assertSame(2, Contract::count());
        $this->assertEquals(27000000, Contract::where('vendor_user_id', $alice->id)->value('total'));
        $this->assertEquals(300000, Contract::where('vendor_user_id', $bob->id)->value('total'));

        // By quantity: 6 laptops from one, 4 from the other; never more than asked or offered.
        $tender2 = $this->openTender2($poster, $buyer->id, ['split' => 'quantity', 'partial' => true]);
        $a2 = $this->rawBid($tender2, $alice, null, [2700000, 35000]);
        $b2 = $this->rawBid($tender2, $bob, null, [2800000, 30000]);
        $tender2->update(['status' => 'evaluating']);
        $laptop2 = $tender2->items[0];
        $this->postJson("/api/market/tenders/{$tender2->id}/award", ['lines' => [['bid_id' => $a2->id, 'item_id' => $laptop2->id, 'quantity' => 8], ['bid_id' => $b2->id, 'item_id' => $laptop2->id, 'quantity' => 4]]])->assertStatus(422);
        $this->postJson("/api/market/tenders/{$tender2->id}/award", ['lines' => [['bid_id' => $a2->id, 'item_id' => $laptop2->id, 'quantity' => 11]]])->assertStatus(422);
        $this->postJson("/api/market/tenders/{$tender2->id}/award", ['lines' => [['bid_id' => $a2->id, 'item_id' => $laptop2->id, 'quantity' => 6], ['bid_id' => $b2->id, 'item_id' => $laptop2->id, 'quantity' => 4]]])->assertOk();
        $this->assertEquals(16200000, Contract::where('source_id', $tender2->id)->where('vendor_user_id', $alice->id)->value('total'));
        $this->assertEquals(11200000, Contract::where('source_id', $tender2->id)->where('vendor_user_id', $bob->id)->value('total'));
    }

    public function test_the_tender_is_contracted_when_its_contracts_are_in_force(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['split' => 'item', 'security' => 50000]]);
        $alice = $this->verifiedUser('ДА92120334');
        $bob = $this->verifiedUser('ЖЧ88010101');
        $a = $this->rawBid($tender, $alice, null, [2700000, 35000]);
        $b = $this->rawBid($tender, $bob, null, [2800000, 30000]);
        $security = Payment::create(['user_id' => $alice->id, 'kind' => 'security', 'amount' => 50000, 'status' => 'paid', 'driver' => 'fake', 'subject_type' => $a->getMorphClass(), 'subject_id' => $a->id]);
        $tender->update(['status' => 'evaluating']);
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/award", ['lines' => [['bid_id' => $a->id, 'item_id' => $tender->items[0]->id], ['bid_id' => $b->id, 'item_id' => $tender->items[1]->id]]])->assertOk();

        $contracts = Contract::orderBy('id')->get();
        $inForce = function (Contract $contract) use ($poster) {
            $contract->forceFill(['status' => 'awaiting_payment'])->save();
            Payments::markPaid(Payments::invoice($poster, $contract->buyerCompany, 'contract', 1000, $contract));
        };
        $inForce($contracts[0]);
        $this->assertSame('awarded', $tender->fresh()->status);
        $inForce($contracts[1]);
        $this->assertSame('contracted', $tender->fresh()->status);
        $this->assertSame('refunded', $security->fresh()->status);
    }

    private function openTender2(User $poster, int $companyId, array $rule): Tender
    {
        $tender = Tenders::draft($poster, $this->tenderPayload(['company_id' => $companyId, 'rule' => $rule]));
        $tender->update(['status' => 'open', 'published_at' => now()]);

        return $tender->fresh();
    }
}
