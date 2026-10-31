<?php

namespace Tests\Feature\Market;

use App\Models\Payment;
use App\Models\Tender;
use App\Support\Market\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiddingTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function bidPayload(Tender $tender, array $prices = [2700000, 30000], array $overrides = []): array
    {
        $tender->loadMissing('items.specs');

        return $overrides + [
            'lead_time_days' => 14,
            'lines' => $tender->items->values()->map(fn ($item, $i) => ['item_id' => $item->id, 'price' => $prices[$i], 'lead_time_days' => 14])->all(),
            'specs' => $tender->items->flatMap->specs->map(fn ($spec) => ['spec_id' => $spec->id, 'value' => '16', 'claim' => 'exceeds'])->values()->all(),
        ];
    }

    public function test_a_bid_is_submitted_once_its_fee_is_paid(): void
    {
        ['tender' => $tender] = $this->openTender(['rule' => ['security' => 100000]]);
        $bidder = $this->verifiedUser('УБ85051523');
        $company = $this->companyOf($bidder, '6234567');

        Sanctum::actingAs($bidder);
        $response = $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender) + ['company_id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('bid.status', 'pending_fee')
            ->assertJsonPath('bid.total', 27300000)
            ->assertJsonPath('bid.lines.0.amount', 27000000)
            ->assertJsonPath('bid.specs.0.claim', 'exceeds')
            ->assertJsonCount(2, 'payments');
        $this->assertEqualsCanonicalizing(['bidding', 'security'], array_column($response->json('payments'), 'kind'));

        $payments = Payment::whereIn('id', array_column($response->json('payments'), 'id'))->get();
        Payments::markPaid($payments[0]);
        $this->getJson('/api/market/bids/'.$response->json('bid.id'))->assertJsonPath('status', 'pending_fee');
        Payments::markPaid($payments[1]);
        $this->getJson('/api/market/bids/'.$response->json('bid.id'))->assertJsonPath('status', 'submitted')->assertJsonPath('company.name', $company->name);
        $this->getJson('/api/market/bids')->assertJsonPath('data.0.tender.code', $tender->code);
    }

    public function test_bidding_is_refused_outside_the_window_or_without_the_right(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['prequalification' => true]]);
        $bidder = $this->verifiedUser('УБ85051523');
        $company = $this->companyOf($bidder, '6234567');

        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender))->assertForbidden();

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender) + ['company_id' => $company->id])->assertForbidden();

        Sanctum::actingAs($bidder);
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender) + ['company_id' => $company->id])->assertForbidden();

        $tender->update(['rule' => ['prequalification' => false], 'starts_at' => now()->addDay()]);
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender) + ['company_id' => $company->id])->assertStatus(422);
        $tender->update(['starts_at' => now()->subWeek(), 'closes_at' => now()->subMinute()]);
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender) + ['company_id' => $company->id])->assertStatus(422);

        // Every item is priced unless the tender splits; a quantity is the item's unless partial.
        $tender->update(['closes_at' => now()->addDay()]);
        $payload = $this->bidPayload($tender) + ['company_id' => $company->id];
        $this->postJson("/api/market/tenders/{$tender->id}/bids", ['lines' => [$payload['lines'][0]]] + $payload)->assertStatus(422);
        $payload['lines'][0]['quantity'] = 5;
        $this->postJson("/api/market/tenders/{$tender->id}/bids", $payload)->assertStatus(422);
    }

    public function test_a_new_bid_supersedes_the_old_one(): void
    {
        ['tender' => $tender] = $this->openTender();
        $bidder = $this->verifiedUser('ДА92120334');
        Sanctum::actingAs($bidder);

        $first = $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender))->json('bid.id');
        Payments::markPaid(Payment::where('subject_id', $first)->where('kind', 'bidding')->firstOrFail());
        $second = $this->postJson("/api/market/tenders/{$tender->id}/bids", $this->bidPayload($tender, [2500000, 25000]))->json('bid.id');
        $this->getJson("/api/market/bids/{$first}")->assertJsonPath('status', 'submitted');
        Payments::markPaid(Payment::where('subject_id', $second)->where('kind', 'bidding')->firstOrFail());

        $this->getJson("/api/market/bids/{$first}")->assertJsonPath('status', 'superseded');
        $this->getJson("/api/market/bids/{$second}")->assertJsonPath('status', 'submitted');
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('my_bid.id', $second);
    }

    public function test_irrevocable_bids_cannot_be_withdrawn_before_validity(): void
    {
        ['tender' => $tender] = $this->openTender();
        $bidder = $this->verifiedUser('ДА92120334');
        $bid = $this->rawBid($tender, $bidder, null, [2700000, 30000]);
        Sanctum::actingAs($bidder);

        $this->postJson("/api/market/bids/{$bid->id}/withdraw")->assertStatus(422);

        $tender->update(['rule' => ['irrevocable' => false]]);
        $this->postJson("/api/market/bids/{$bid->id}/withdraw")->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->postJson("/api/market/bids/{$bid->id}/withdraw")->assertStatus(422);
    }

    public function test_a_bidder_never_reads_another_bid(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        $bidder = $this->verifiedUser('УБ85051523');
        $bid = $this->rawBid($tender, $bidder, $this->companyOf($bidder, '6234567'), [2700000, 30000]);

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->getJson("/api/market/bids/{$bid->id}")->assertNotFound();
        $this->postJson("/api/market/bids/{$bid->id}/withdraw")->assertNotFound();

        // The poster reads bids only once the tender has closed.
        Sanctum::actingAs($poster);
        $this->getJson("/api/market/bids/{$bid->id}")->assertNotFound();
        $tender->update(['status' => 'closed']);
        $this->getJson("/api/market/bids/{$bid->id}")->assertOk()->assertJsonPath('total', 27300000);
    }
}
