<?php

namespace Tests\Feature\Market;

use App\Models\Bid;
use App\Models\Payment;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenderModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tender_gets_a_daily_code_and_its_parts(): void
    {
        $poster = User::factory()->create();
        $first = Tender::factory()->create(['organizer_user_id' => $poster->id, 'created_by' => $poster->id]);
        $second = Tender::factory()->create(['organizer_user_id' => $poster->id, 'created_by' => $poster->id]);

        $day = now()->format('ymd');
        $this->assertSame("TND-{$day}-001", $first->code);
        $this->assertSame("TND-{$day}-002", $second->code);

        $laptop = $first->items()->create(['position' => 2, 'name' => 'Laptop', 'quantity' => 10, 'unit' => 'pcs']);
        $first->items()->create(['position' => 1, 'name' => 'Mouse']);
        $laptop->specs()->create(['position' => 1, 'label' => 'Memory', 'kind' => 'number', 'operator' => 'gte', 'value' => '8', 'unit' => 'GB', 'must' => true]);

        $bidder = User::factory()->create();
        $bid = $first->bids()->create(['user_id' => $bidder->id, 'submitted_by' => $bidder->id, 'round' => 1, 'status' => 'draft']);
        $bid->lines()->create(['tender_item_id' => $laptop->id, 'price' => 2500000, 'quantity' => 10, 'lead_time_days' => 14, 'amount' => 25000000]);
        $bid->specs()->create(['tender_spec_id' => $laptop->specs->first()->id, 'value' => '16', 'claim' => 'exceeds']);

        $first = $first->fresh();
        $this->assertSame(['Mouse', 'Laptop'], $first->items->pluck('name')->all());
        $this->assertTrue($first->items->last()->specs->first()->must);
        $this->assertSame('draft', $first->status);
        $this->assertSame('sealed', $first->rule['visibility']);
        $this->assertInstanceOf(Bid::class, $first->bids->first());
        $this->assertSame('exceeds', $bid->specs->first()->claim);
        $this->assertSame(25000000.0, (float) $bid->lines->first()->amount);
        $this->assertContains('security', Payment::KINDS);
    }
}
