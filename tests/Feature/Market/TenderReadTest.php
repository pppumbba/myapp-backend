<?php

namespace Tests\Feature\Market;

use App\Models\User;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderReadTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_sealed_bids_show_only_a_count_to_the_poster_and_admins_before_close(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        $bidder = $this->verifiedUser('УБ85051523');
        $this->rawBid($tender, $bidder, $this->companyOf($bidder, '6234567'), [2711111, 33333]);
        $this->rawBid($tender, $this->verifiedUser('ДА92120334'), null, [2622222, 44444]);

        foreach ([$poster, User::factory()->admin()->create()] as $reader) {
            Sanctum::actingAs($reader);
            $json = $this->getJson("/api/market/tenders/{$tender->id}")->assertOk()
                ->assertJsonPath('bid_count', $reader->is($poster) ? 2 : null)
                ->assertJsonPath('items.0.best', null)
                ->getContent();
            foreach (['2711111', '2622222', '33333', '44444'] as $price) {
                $this->assertStringNotContainsString($price, $json);
            }
        }

        // The bidder sees their own bid's summary, nobody else's.
        Sanctum::actingAs($bidder);
        $json = $this->getJson("/api/market/tenders/{$tender->id}")->assertOk()
            ->assertJsonPath('role', 'bidder')
            ->assertJsonPath('my_bid.status', 'submitted')
            ->getContent();
        $this->assertStringNotContainsString('2622222', $json);
    }

    public function test_open_visibility_shows_the_lowest_price_per_item_only(): void
    {
        ['tender' => $tender] = $this->openTender(['rule' => ['visibility' => 'open']]);
        $bidder = $this->verifiedUser('УБ85051523');
        $this->rawBid($tender, $bidder, $this->companyOf($bidder, '6234567'), [2700000, 30000]);
        $this->rawBid($tender, $this->verifiedUser('ДА92120334'), null, [2600000, 45000]);

        Sanctum::actingAs($this->verifiedUser('ЖЧ88010101'));
        $json = $this->getJson("/api/market/tenders/{$tender->id}")->assertOk()
            ->assertJsonPath('items.0.best', 2600000)
            ->assertJsonPath('items.1.best', 30000)
            ->getContent();
        $this->assertStringNotContainsString('Талст', $json);
        $this->assertStringNotContainsString('6234567', $json);
    }

    public function test_budget_is_hidden_unless_shown(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender();
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('items.0.budget', null);

        $tender->items()->first()->update(['show_budget' => true]);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('items.0.budget', 30000000);

        $tender->items()->first()->update(['show_budget' => false]);
        Sanctum::actingAs($poster);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('items.0.budget', 30000000);
    }

    public function test_drafts_are_seen_only_by_the_poster(): void
    {
        ['poster' => $poster, 'company' => $company, 'tender' => $draft] = $this->draftTender();
        $open = Tenders::draft($poster, $this->tenderPayload(['company_id' => $company->id, 'title' => 'Printers']));
        $open->update(['status' => 'open', 'published_at' => now()]);

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->getJson('/api/market/tenders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Printers')
            ->assertJsonPath('data.0.organizer.name', $company->name);
        $this->getJson('/api/market/tenders?q=scanner')->assertJsonCount(0, 'data');
        $this->getJson('/api/market/tenders?q=printers')->assertJsonCount(1, 'data');
        $this->getJson('/api/market/tenders/mine')->assertJsonCount(0, 'data');

        Sanctum::actingAs($poster);
        $this->getJson('/api/market/tenders/mine')->assertJsonCount(2, 'data');
    }
}
