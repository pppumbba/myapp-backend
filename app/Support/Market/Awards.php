<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Awards: the poster picks the winners — the whole tender to one bid, each item to a bid, or
 * quantities of an item to several — and each winner gets a contract draft to sign. The tender
 * is contracted once every one of those contracts is in force.
 */
class Awards
{
    /** Contract statuses that mean the contract is in force (or past it). */
    public const IN_FORCE = ['active', 'in_progress', 'delivered', 'completed'];

    /**
     * @param  list<array{bid_id: int, item_id?: ?int, quantity?: ?float}>  $lines
     */
    public static function award(User $by, Tender $tender, array $lines): Tender
    {
        Tenders::requirePoster($by, $tender);
        abort_unless($tender->status === 'evaluating', 422, __('Award while evaluating.'));
        $picked = self::check($tender, $lines);

        return DB::transaction(function () use ($by, $tender, $picked) {
            $bids = Bid::with('company', 'user', 'lines')->findMany(array_keys($picked))->keyBy('id');
            $items = $tender->items()->get()->keyBy('id');
            foreach ($picked as $bidId => $awarded) {
                $bid = $bids[$bidId];
                $contract = Contracts::draft($by, [
                    'source' => 'tender',
                    'source_id' => $tender->id,
                    'title' => "{$tender->code} — {$tender->title}",
                    'buyer_company_id' => $tender->organizer_company_id,
                    'vendor_registry_number' => $bid->company?->registry_number ?? $bid->user?->registry_number,
                    'middleman' => $tender->rule['middleman'],
                    'items' => array_map(fn ($a) => [
                        'name' => $items[$a['item_id']]->name,
                        'description' => $items[$a['item_id']]->description,
                        'quantity' => $a['quantity'],
                        'unit' => $items[$a['item_id']]->unit,
                        'unit_price' => $a['price'],
                    ], $awarded),
                ]);
                foreach ($awarded as $a) {
                    TenderAward::create([
                        'tender_id' => $tender->id, 'bid_id' => $bid->id, 'tender_item_id' => $a['item_id'],
                        'quantity' => $a['quantity'], 'amount' => round($a['quantity'] * $a['price'], 2), 'contract_id' => $contract->id,
                    ]);
                }
                MarketNotify::send(User::findOrFail($bid->submitted_by), __('You won: :title', ['title' => $tender->title]), __('Check the contract draft, then sign it.'), "/market/contracts/{$contract->id}", 'success');
            }
            $tender->update(['status' => 'awarded']);
            Tenders::log($tender, $by, 'awarded', null, ['bid_ids' => array_keys($picked)]);

            $losers = Evaluation::currentBids($tender)->whereNotIn('id', array_keys($picked))->pluck('submitted_by')->unique();
            foreach (User::findMany($losers) as $user) {
                MarketNotify::send($user, __('Your bid was not chosen: :title', ['title' => $tender->title]), null, Tenders::link($tender));
            }

            return $tender->fresh();
        });
    }

    /**
     * What was awarded, by winner.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(Tender $tender): array
    {
        return $tender->awards()->with('bid.company', 'bid.user', 'item')->get()->groupBy('bid_id')->map(function ($rows) {
            $bid = $rows->first()->bid;
            $contract = Contract::find($rows->first()->contract_id);

            return [
                'bid_id' => $bid->id,
                'party' => $bid->company ? ['type' => 'company', 'name' => $bid->company->name, 'registry_number' => $bid->company->registry_number]
                    : ['type' => 'person', 'name' => $bid->user?->verified_name, 'registry_number' => null],
                'lines' => $rows->map(fn (TenderAward $a) => ['item_id' => $a->tender_item_id, 'name' => $a->item?->name, 'quantity' => (float) $a->quantity, 'amount' => (float) $a->amount])->values()->all(),
                'total' => (float) $rows->sum('amount'),
                'contract' => $contract ? ['id' => $contract->id, 'number' => $contract->number, 'status' => $contract->status] : null,
            ];
        })->values()->all();
    }

    /** A contract from a tender came into force: the tender is contracted once all of them are. */
    public static function contractInForce(Contract $contract): void
    {
        $tender = Tender::find($contract->source_id);
        if (! $tender || $tender->status !== 'awarded') {
            return;
        }
        $contractIds = $tender->awards()->pluck('contract_id')->unique();
        if (Contract::whereIn('id', $contractIds)->whereNotIn('status', self::IN_FORCE)->exists()) {
            return;
        }
        $tender->update(['status' => 'contracted']);
        Tenders::refundSecurities($tender);
        Tenders::log($tender, null, 'contracted');
    }

    /**
     * Checks the award against the rule's split and the bids, and resolves each line to the
     * item, quantity and unit price it gives the winner.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, list<array{item_id: int, quantity: float, price: float}>> by bid id
     */
    private static function check(Tender $tender, array $lines): array
    {
        $split = $tender->rule['split'];
        $bids = Evaluation::currentBids($tender)->where('status', 'submitted')->keyBy('id');
        $items = $tender->items()->get()->keyBy('id');
        $fail = fn (string $message) => throw ValidationException::withMessages(['lines' => $message]);
        $picked = [];
        $given = [];

        foreach ($lines as $line) {
            $bid = $bids->get((int) $line['bid_id']) ?? $fail(__('Pick bids that are still in.'));
            if ($split === 'none') {
                (count($lines) === 1 && empty($line['item_id'])) || $fail(__('This tender goes to one bid as a whole.'));
                foreach ($bid->lines as $bl) {
                    $picked[$bid->id][] = ['item_id' => $bl->tender_item_id, 'quantity' => (float) $bl->quantity, 'price' => (float) $bl->price];
                }

                continue;
            }
            $item = $items->get((int) ($line['item_id'] ?? 0)) ?? $fail(__('Name the item for each winner.'));
            $offer = $bid->lines->firstWhere('tender_item_id', $item->id) ?? $fail(__('That bid did not price :item.', ['item' => $item->name]));
            $quantity = $split === 'quantity' && isset($line['quantity']) ? (float) $line['quantity'] : (float) $offer->quantity;
            $quantity > 0 && $quantity <= (float) $offer->quantity + 0.0001 || $fail(__('Award no more than the bid offered.'));
            $given[$item->id] = ($given[$item->id] ?? 0) + $quantity;
            if ($split === 'item' && $given[$item->id] > (float) $offer->quantity + 0.0001) {
                $fail(__('Each item goes to one bid.'));
            }
            if ($split === 'item' && collect($picked)->flatten(1)->contains('item_id', $item->id)) {
                $fail(__('Each item goes to one bid.'));
            }
            $given[$item->id] <= (float) ($item->quantity ?? 1) + 0.0001 || $fail(__('Award no more than the tender asks for :item.', ['item' => $item->name]));
            $picked[$bid->id][] = ['item_id' => $item->id, 'quantity' => $quantity, 'price' => (float) $offer->price];
        }
        $picked || $fail(__('Pick at least one winner.'));

        return $picked;
    }
}
