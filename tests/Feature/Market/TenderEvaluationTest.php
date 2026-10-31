<?php

namespace Tests\Feature\Market;

use App\Models\Bid;
use App\Models\Tender;
use App\Models\UserNotification;
use App\Support\Market\Evaluation;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderEvaluationTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    /** Sets one bid's answer to a spec (found by label). */
    private function answer(Bid $bid, string $label, ?string $value, string $claim = 'meets'): void
    {
        $spec = $bid->specs()->whereHas('spec', fn ($q) => $q->where('label', $label))->firstOrFail();
        $spec->update(['value' => $value, 'claim' => $claim]);
    }

    public function test_closing_due_tenders(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        $bidder = $this->verifiedUser('ДА92120334');
        $this->rawBid($tender, $bidder, null, [2700000, 30000]);

        $this->assertSame(0, Tenders::closeDue());
        $this->travel(8)->days();
        $this->artisan('market:close-tenders')->assertSuccessful();

        $this->assertSame('closed', $tender->fresh()->status);
        $this->assertTrue(UserNotification::where('user_id', $poster->id)->where('link', "/market/tenders/{$tender->id}")->exists());
        $this->assertTrue(UserNotification::where('user_id', $bidder->id)->where('link', "/market/tenders/{$tender->id}")->exists());
        $this->assertSame(0, Tenders::closeDue());

        $tender->update(['status' => 'negotiating', 'final_round_closes_at' => now()->subMinute()]);
        $this->assertSame(1, Tenders::closeDue());
        $this->assertSame('evaluating', $tender->fresh()->status);
    }

    public function test_numeric_must_haves_are_checked(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        $makers = [
            ['УБ85051523', '8'], ['ДА92120334', '7.5'], ['ЖЧ88010101', ''], ['ЖЧ88010202', '8.0'],
        ];
        $bids = [];
        foreach ($makers as [$regnum, $memory]) {
            $bid = $this->rawBid($tender, $this->verifiedUser($regnum), null, [2700000, 30000]);
            $this->answer($bid, 'Memory', $memory);
            $bids[] = $bid;
        }
        $tender->update(['status' => 'closed']);

        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/evaluation/start")->assertOk()->assertJsonPath('status', 'evaluating');
        $rows = collect($this->getJson("/api/market/tenders/{$tender->id}/evaluation")->assertOk()->json('bids'))->keyBy('id');
        $this->assertTrue($rows[$bids[0]->id]['compliant']);
        $this->assertFalse($rows[$bids[1]->id]['compliant']);
        $this->assertFalse($rows[$bids[2]->id]['compliant']);
        $this->assertTrue($rows[$bids[3]->id]['compliant']);
        $this->assertSame('ruled_out', $bids[1]->fresh()->status);

        // ≤ and = compare the same way.
        $spec = $tender->items()->first()->specs()->where('label', 'Memory')->first();
        $spec->update(['operator' => 'lte', 'value' => '7.5']);
        $this->assertTrue(collect(Evaluation::of($tender->fresh())['bids'])->firstWhere('id', $bids[1]->id)['compliant']);
        $spec->update(['operator' => 'eq', 'value' => '8']);
        $this->assertTrue(collect(Evaluation::of($tender->fresh())['bids'])->firstWhere('id', $bids[3]->id)['compliant']);
        $this->assertFalse(collect(Evaluation::of($tender->fresh())['bids'])->firstWhere('id', $bids[1]->id)['compliant']);

        // Only the poster reads the evaluation.
        Sanctum::actingAs($this->verifiedUser('ЖЧ88010303'));
        $this->getJson("/api/market/tenders/{$tender->id}/evaluation")->assertForbidden();
    }

    public function test_quality_scores_and_rankings_follow_the_rule(): void
    {
        ['tender' => $tender] = $this->openTender(['rule' => ['award' => 'best_value', 'weights' => ['price' => 50, 'lead' => 25, 'quality' => 25]]]);
        // cheap and slow, no backlight · dear and fast, backlight exceeds · middle, backlight meets
        $cheap = $this->rawBid($tender, $this->verifiedUser('УБ85051523'), null, [2000000, 30000], 30);
        $this->answer($cheap, 'Backlit keyboard', null, 'fails');
        $fast = $this->rawBid($tender, $this->verifiedUser('ДА92120334'), null, [3000000, 30000], 5);
        $this->answer($fast, 'Backlit keyboard', 'yes', 'exceeds');
        $middle = $this->rawBid($tender, $this->verifiedUser('ЖЧ88010101'), null, [2500000, 30000], 10);
        $tender->update(['status' => 'closed']);

        $ranked = fn (string $award) => collect(Evaluation::of($this->withRule($tender, $award))['bids'])->sortBy('rank')->pluck('id')->all();
        $scores = collect(Evaluation::of($tender->fresh())['bids'])->keyBy('id');
        $this->assertEquals(0, $scores[$cheap->id]['quality_score']);
        $this->assertEquals(120, $scores[$fast->id]['quality_score']);
        $this->assertEquals(100, $scores[$middle->id]['quality_score']);

        $this->assertSame([$cheap->id, $middle->id, $fast->id], $ranked('lowest_price'));
        $this->assertSame([$fast->id, $middle->id, $cheap->id], $ranked('fastest'));
        $this->assertSame([$fast->id, $middle->id, $cheap->id], $ranked('highest_quality'));
        // price 50 / lead 25 / quality 25: middle 40+12.5+20.8=73.3, fast 33.3+25+25=83.3, cheap 50+4.2+0=54.2
        $this->assertSame([$fast->id, $middle->id, $cheap->id], $ranked('best_value'));

        $items = Evaluation::of($tender->fresh())['items'];
        $this->assertSame($cheap->id, $items[0]['best_bid_id']);
        $this->assertEquals(2000000, $items[0]['best_price']);
    }

    public function test_overrides_and_overturns_are_logged(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        $bid = $this->rawBid($tender, $this->verifiedUser('УБ85051523'), null, [2700000, 30000]);
        $this->answer($bid, 'Memory', '4');
        $tender->update(['status' => 'closed']);
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/evaluation/start")->assertOk();
        $this->assertSame('ruled_out', $bid->fresh()->status);

        $this->postJson("/api/market/bids/{$bid->id}/overturn", ['overturn' => true, 'note' => 'Memory is upgradeable'])->assertOk();
        $this->assertSame('submitted', $bid->fresh()->status);

        $backlight = $bid->specs()->whereHas('spec', fn ($q) => $q->where('label', 'Backlit keyboard'))->firstOrFail();
        $this->postJson("/api/market/bid-specs/{$backlight->id}/override", ['claim' => 'fails', 'note' => 'Datasheet says no backlight'])->assertOk();
        $row = collect($this->getJson("/api/market/tenders/{$tender->id}/evaluation")->json('bids'))->firstWhere('id', $bid->id);
        $this->assertEquals(0, $row['quality_score']);

        $this->assertSame(['evaluation_started', 'bid_overturned', 'claim_overridden'], Tender::find($tender->id)->events()->whereIn('type', ['evaluation_started', 'bid_overturned', 'claim_overridden'])->orderBy('id')->pluck('type')->all());
        $this->assertSame('Datasheet says no backlight', $tender->events()->where('type', 'claim_overridden')->value('note'));

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->postJson("/api/market/bids/{$bid->id}/overturn", ['overturn' => false])->assertForbidden();
    }

    private function withRule(Tender $tender, string $award): Tender
    {
        $fresh = $tender->fresh();
        $fresh->rule = ['award' => $award] + $fresh->rule;

        return $fresh;
    }
}
