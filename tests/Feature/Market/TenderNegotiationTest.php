<?php

namespace Tests\Feature\Market;

use App\Models\Payment;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderNegotiationTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_negotiation_rounds_and_final_offers(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['procedure' => 'negotiated']]);
        $alice = $this->verifiedUser('ДА92120334');
        $bob = $this->verifiedUser('ЖЧ88010101');
        $carol = $this->verifiedUser('ЖЧ88010202');
        $a = $this->rawBid($tender, $alice, null, [2700000, 30000]);
        $b = $this->rawBid($tender, $bob, null, [2800000, 30000]);
        $c = $this->rawBid($tender, $carol, null, [2900000, 30000]);
        $tender->update(['status' => 'evaluating']);

        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/shortlist", ['bid_ids' => [$a->id, $b->id]])->assertOk()
            ->assertJsonPath('status', 'negotiating')->assertJsonPath('round', 2);
        $this->assertTrue($a->fresh()->shortlisted);
        $this->assertFalse($c->fresh()->shortlisted);
        $this->assertTrue(UserNotification::where('user_id', $alice->id)->where('link', "/market/tenders/{$tender->id}")->exists());

        // A shortlisted bidder revises without a new fee; someone left out cannot.
        Sanctum::actingAs($alice);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('my_bid.shortlisted', true);
        $revised = $this->postJson("/api/market/bids/{$a->id}/revise", ['lines' => [
            ['item_id' => $tender->items[0]->id, 'price' => 2500000], ['item_id' => $tender->items[1]->id, 'price' => 30000],
        ]])->assertCreated()->assertJsonPath('round', 2)->assertJsonPath('status', 'submitted')->assertJsonPath('total', 25300000);
        $this->assertSame('superseded', $a->fresh()->status);
        $this->assertSame(0, Payment::where('kind', 'bidding')->count());
        Sanctum::actingAs($carol);
        $this->postJson("/api/market/bids/{$c->id}/revise", ['lines' => [['item_id' => $tender->items[0]->id, 'price' => 1]]])->assertStatus(422);

        // Best and final offers: three days unless the poster names a date, then back to evaluation.
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/final-round")->assertOk()->assertJsonPath('round', 3);
        $this->assertSame(now()->addDays(3)->toDateString(), $tender->fresh()->final_round_closes_at->toDateString());
        $this->postJson("/api/market/tenders/{$tender->id}/finish-negotiation")->assertOk()->assertJsonPath('status', 'evaluating');

        $ids = collect($this->getJson("/api/market/tenders/{$tender->id}/evaluation")->json('bids'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$revised->json('id'), $b->id, $c->id], $ids);

        // Shortlisting needs the negotiated procedure.
        $tender->update(['rule' => ['procedure' => 'single']]);
        $this->postJson("/api/market/tenders/{$tender->id}/shortlist", ['bid_ids' => [$b->id]])->assertStatus(422);
    }

    public function test_messages_are_private_to_poster_and_bidder(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['procedure' => 'negotiated']]);
        $alice = $this->verifiedUser('ДА92120334');
        $bob = $this->verifiedUser('ЖЧ88010101');
        $a = $this->rawBid($tender, $alice, null, [2700000, 30000]);
        $b = $this->rawBid($tender, $bob, null, [2800000, 30000]);
        $tender->update(['status' => 'evaluating']);
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/shortlist", ['bid_ids' => [$a->id, $b->id]])->assertOk();

        $this->postJson("/api/market/bids/{$a->id}/messages", ['body' => 'Can you do 2.5M per laptop?'])->assertCreated()->assertJsonPath('side', 'poster');
        Sanctum::actingAs($alice);
        $this->postJson("/api/market/bids/{$a->id}/messages", ['body' => 'Yes, with 20 days delivery.'])->assertCreated()->assertJsonPath('side', 'bidder');
        $this->getJson("/api/market/bids/{$a->id}/messages")->assertOk()->assertJsonCount(2)->assertJsonPath('1.body', 'Yes, with 20 days delivery.');
        $this->assertTrue(UserNotification::where('user_id', $poster->id)->where('title', 'like', '%message%')->exists());

        Sanctum::actingAs($bob);
        $this->getJson("/api/market/bids/{$a->id}/messages")->assertNotFound();
        $this->postJson("/api/market/bids/{$a->id}/messages", ['body' => 'Me too'])->assertNotFound();
        $this->getJson("/api/market/bids/{$b->id}/messages")->assertOk()->assertJsonCount(0);
    }
}
