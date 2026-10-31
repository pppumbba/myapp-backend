<?php

namespace Tests\Feature\Market;

use App\Models\Bid;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Tender;
use App\Models\User;
use App\Support\Market\Companies;
use App\Support\Market\Contracts;
use App\Support\Market\Tenders;

/** Shared set-up for market tests: users DAN has already verified. */
trait MarketTestHelpers
{
    /** A user verified as this test person (config market.fake.citizens). */
    protected function verifiedUser(string $registryNumber, string $name = 'Test Person'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['registry_number' => $registryNumber, 'verified_name' => $name, 'verified_at' => now()])->save();

        return $user;
    }

    /** The user (its director in the fake ХУР) registers the company and so acts for it with every right. */
    protected function companyOf(User $director, string $registryNumber): Company
    {
        return Companies::register($director, $registryNumber)['company'];
    }

    /**
     * A draft between Говь Ханган (buyer, director УБ90010112) and Талст Үйлчилгээ (vendor,
     * director УБ85051523): 2 × 1 500 000 less 100 000, paid 30% / 70%.
     *
     * @return array{buyer: User, vendor: User, contract: Contract}
     */
    protected function draftContract(array $overrides = []): array
    {
        $buyer = $this->verifiedUser('УБ90010112', 'Buyer Person');
        $vendor = $this->verifiedUser('УБ85051523', 'Vendor Person');
        $buyerCompany = $this->companyOf($buyer, '5123456');
        $this->companyOf($vendor, '6234567');

        $contract = Contracts::draft($buyer, $overrides + [
            'title' => 'Notebooks',
            'buyer_company_id' => $buyerCompany->id,
            'vendor_registry_number' => '6234567',
            'items' => [['name' => 'Notebook 15.6"', 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 1500000]],
            'discount' => 100000,
            'delivery_date' => now()->addMonth()->toDateString(),
            'middleman' => 'off',
            'steps' => [['label' => 'Advance', 'percent' => 30], ['label' => 'On delivery', 'percent' => 70]],
        ]);

        return ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract];
    }

    /**
     * What a poster sends for a tender: 10 laptops (memory ≥ 8 GB must-have, a nice-to-have
     * backlit keyboard) and 10 mice, open from now for a week.
     *
     * @return array<string, mixed>
     */
    protected function tenderPayload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Office laptops',
            'type' => 'goods',
            'description' => 'Laptops and mice for the new office.',
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'closes_at' => now()->addWeek()->toDateTimeString(),
            'rule' => [],
            'items' => [
                ['name' => 'Laptop', 'quantity' => 10, 'unit' => 'pcs', 'budget' => 30000000, 'specs' => [
                    ['label' => 'Memory', 'kind' => 'number', 'operator' => 'gte', 'value' => '8', 'unit' => 'GB', 'must' => true],
                    ['label' => 'Backlit keyboard', 'kind' => 'text', 'must' => false],
                ]],
                ['name' => 'Mouse', 'quantity' => 10, 'unit' => 'pcs', 'specs' => []],
            ],
        ];
    }

    /**
     * Говь Ханган (director УБ90010112) drafts a tender.
     *
     * @return array{poster: User, company: Company, tender: Tender}
     */
    protected function draftTender(array $overrides = []): array
    {
        $poster = $this->verifiedUser('УБ90010112', 'Poster Person');
        $company = $this->companyOf($poster, '5123456');
        $tender = Tenders::draft($poster, $this->tenderPayload($overrides + ['company_id' => $company->id]));

        return ['poster' => $poster, 'company' => $company, 'tender' => $tender];
    }

    /**
     * draftTender, published (fee paid) and open for bids now.
     *
     * @return array{poster: User, company: Company, tender: Tender}
     */
    protected function openTender(array $overrides = []): array
    {
        $made = $this->draftTender($overrides);
        $made['tender']->update(['status' => 'open', 'published_at' => now()]);
        $made['tender'] = $made['tender']->fresh();

        return $made;
    }

    /**
     * A submitted bid straight into the tables (no fee), one price per item in order; lead time
     * 14 days and every spec claimed as met with its required value.
     */
    protected function rawBid(Tender $tender, User $by, ?Company $company, array $prices, int $lead = 14): Bid
    {
        $bid = $tender->bids()->create([
            'company_id' => $company?->id,
            'user_id' => $company ? null : $by->id,
            'submitted_by' => $by->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'lead_time_days' => $lead,
        ]);
        $total = 0;
        foreach ($tender->items as $i => $item) {
            $quantity = (float) ($item->quantity ?? 1);
            $bid->lines()->create(['tender_item_id' => $item->id, 'price' => $prices[$i], 'quantity' => $quantity, 'lead_time_days' => $lead, 'amount' => $prices[$i] * $quantity]);
            $total += $prices[$i] * $quantity;
            foreach ($item->specs as $spec) {
                $bid->specs()->create(['tender_spec_id' => $spec->id, 'value' => $spec->value, 'claim' => 'meets']);
            }
        }
        $bid->update(['total' => $total]);

        return $bid->fresh();
    }
}
