<?php

namespace Tests\Feature\Market;

use App\Models\MarketCategory;
use App\Models\MarketFee;
use App\Models\Payment;
use App\Models\UserNotification;
use App\Support\Market\Categories;
use App\Support\Market\Payments;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderPublishTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_publishing_invoices_the_posting_fee_and_opens_when_paid(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->draftTender();
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->postJson("/api/market/tenders/{$tender->id}/publish")->assertNotFound();

        Sanctum::actingAs($poster);
        $response = $this->postJson("/api/market/tenders/{$tender->id}/publish")->assertOk()
            ->assertJsonPath('tender.status', 'draft')
            ->assertJsonPath('payment.kind', 'posting')
            ->assertJsonPath('payment.amount', 10000)
            ->assertJsonPath('payment.subject.type', 'tender')
            ->assertJsonPath('payment.subject.number', $tender->code);
        // Asking again gives the same invoice.
        $this->postJson("/api/market/tenders/{$tender->id}/publish")->assertJsonPath('payment.id', $response->json('payment.id'));

        Payments::markPaid(Payment::findOrFail($response->json('payment.id')));
        $tender->refresh();
        $this->assertSame('open', $tender->status);
        $this->assertNotNull($tender->published_at);
        $this->assertSame('published', $tender->events->last()->type);
        $this->postJson("/api/market/tenders/{$tender->id}/publish")->assertStatus(422);
    }

    public function test_a_free_posting_opens_at_once_and_a_past_close_date_is_refused(): void
    {
        MarketFee::where('kind', 'posting')->update(['amount' => 0]);
        ['poster' => $poster, 'company' => $company, 'tender' => $tender] = $this->draftTender();
        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/publish")->assertOk()->assertJsonPath('tender.status', 'open');

        $late = Tenders::draft($poster, $this->tenderPayload(['company_id' => $company->id]));
        $late->update(['closes_at' => now()->subHour()]);
        $this->postJson("/api/market/tenders/{$late->id}/publish")->assertStatus(422);
    }

    public function test_followers_hear_of_a_new_tender(): void
    {
        $laptops = MarketCategory::create(['name' => 'Laptops']);
        $follower = $this->verifiedUser('ДА92120334');
        Categories::follow($follower, $laptops, true);
        ['poster' => $poster, 'tender' => $tender] = $this->draftTender(['category_id' => $laptops->id]);
        Categories::follow($poster, $laptops, true);

        Sanctum::actingAs($poster);
        Payments::markPaid(Payment::findOrFail($this->postJson("/api/market/tenders/{$tender->id}/publish")->json('payment.id')));

        $this->assertSame("/market/tenders/{$tender->id}", UserNotification::where('user_id', $follower->id)->value('link'));
        $this->assertFalse(UserNotification::where('user_id', $poster->id)->where('link', "/market/tenders/{$tender->id}")->exists());
    }
}
