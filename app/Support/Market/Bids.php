<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\Company;
use App\Models\Content;
use App\Models\Payment;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bids: a party prices the tender's items and answers its specs. A bid counts once its bidding
 * fee and security are paid; a newer bid of the same party in the same round replaces it.
 */
class Bids
{
    /**
     * A party sends a bid. Lines price the items; specs answer the tender's specs.
     *
     * @param  array{company_id?: ?int, lead_time_days?: ?int, lines: list<array{item_id: int, price: float, quantity?: ?float, lead_time_days?: ?int}>, specs?: list<array{spec_id: int, value?: ?string, claim?: ?string, file?: ?UploadedFile}>}  $data
     * @return array{bid: Bid, payments: list<Payment>}
     */
    public static function submit(User $by, Tender $tender, ?Company $company, array $data): array
    {
        abort_unless($by->isVerified(), 403, __('Verify yourself with DAN first.'));
        abort_if(Tenders::isPoster($by, $tender), 403, __('The organizer does not bid on their own tender.'));
        if ($company) {
            abort_unless(MarketAccess::canActFor($by, $company, 'bid'), 403, __('You may not bid for this company.'));
        }
        abort_unless(Tenders::isQualified($tender, $by, $company), 403, __('Only prequalified parties may bid.'));
        abort_unless(self::isOpenForBids($tender), 422, __('This tender is not taking bids now.'));
        $lines = self::checkLines($tender, $data['lines']);

        return DB::transaction(function () use ($by, $tender, $company, $data, $lines) {
            $lead = (int) ($data['lead_time_days'] ?? max(array_column($lines, 'lead_time_days') ?: [0]));
            $bid = $tender->bids()->create([
                'company_id' => $company?->id,
                'user_id' => $company ? null : $by->id,
                'submitted_by' => $by->id,
                'round' => $tender->round ?? 1,
                'status' => 'pending_fee',
                'lead_time_days' => $lead,
                'valid_until' => self::validUntil($tender),
                'total' => array_sum(array_column($lines, 'amount')),
            ]);
            foreach ($lines as $line) {
                $bid->lines()->create($line);
            }
            self::fillSpecs($by, $tender, $bid, $data['specs'] ?? [], array_column($lines, 'tender_item_id'));
            Tenders::log($tender, $by, 'bid_sent', null, ['bid_id' => $bid->id]);

            $payments = [];
            foreach (['security' => (float) $tender->rule['security'], 'bidding' => (float) Fees::quote('bidding')] as $kind => $amount) {
                if ($amount > 0) {
                    $payments[] = Payments::invoice($by, $company, $kind, $amount, $bid);
                }
            }
            self::settle($bid);

            return ['bid' => $bid->fresh(), 'payments' => $payments];
        });
    }

    /** The bidder takes the bid back: never while it is irrevocable and still valid. */
    public static function withdraw(User $by, Bid $bid): Bid
    {
        self::requireBidder($by, $bid);
        $tender = $bid->tender;
        abort_unless(in_array($bid->status, ['pending_fee', 'submitted'], true), 422, __('This bid can no longer be withdrawn.'));
        abort_unless($tender->status === 'open', 422, __('Bids can be withdrawn only while the tender is open.'));
        abort_if($bid->status === 'submitted' && $tender->rule['irrevocable'] && self::validUntil($tender)->isFuture(), 422, __('This tender\'s bids are irrevocable until :date.', ['date' => self::validUntil($tender)->toDateString()]));

        $bid->update(['status' => 'withdrawn']);
        Payment::where('subject_type', $bid->getMorphClass())->where('subject_id', $bid->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        Tenders::log($tender, $by, 'bid_withdrawn', null, ['bid_id' => $bid->id]);

        return $bid->fresh();
    }

    /** A bidding fee or security is paid: the bid counts once nothing is left to pay. */
    public static function paymentPaid(Payment $payment): void
    {
        $bid = Bid::find($payment->subject_id);
        if ($bid) {
            self::settle($bid);
        }
    }

    /** The user's bids (theirs or their companies'), newest first. */
    public static function mine(User $user): LengthAwarePaginator
    {
        $companies = $user->companyMemberships()->pluck('company_id');

        return Bid::where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('company_id', $companies))
            ->with('tender', 'company')->latest('id')->paginate(20)
            ->through(fn (Bid $bid) => [
                'id' => $bid->id,
                'status' => $bid->status,
                'total' => (float) $bid->total,
                'round' => $bid->round,
                'company' => $bid->company?->name,
                'submitted_at' => $bid->submitted_at,
                'tender' => ['id' => $bid->tender->id, 'code' => $bid->tender->code, 'title' => $bid->tender->title, 'status' => $bid->tender->status, 'closes_at' => $bid->tender->closes_at],
            ]);
    }

    /**
     * The whole bid: for its own party, or the poster once the tender has closed.
     *
     * @return array<string, mixed>
     */
    public static function show(Bid $bid, User $user): array
    {
        $bid->loadMissing('lines.item', 'specs.spec', 'company', 'user', 'tender');
        $contents = Content::whereIn('id', $bid->specs->pluck('content_id')->filter())->get()->keyBy('id');

        return [
            'id' => $bid->id,
            'tender_id' => $bid->tender_id,
            'status' => $bid->status,
            'round' => $bid->round,
            'party' => $bid->company_id ? 'company' : 'person',
            'company' => $bid->company ? ['id' => $bid->company->id, 'name' => $bid->company->name, 'registry_number' => $bid->company->registry_number] : null,
            'person' => $bid->company_id ? null : ['id' => $bid->user_id, 'name' => $bid->user?->verified_name],
            'total' => (float) $bid->total,
            'lead_time_days' => $bid->lead_time_days,
            'valid_until' => $bid->valid_until?->toDateString(),
            'quality_score' => $bid->quality_score === null ? null : (float) $bid->quality_score,
            'ruled_out_reason' => $bid->ruled_out_reason,
            'submitted_at' => $bid->submitted_at,
            'lines' => $bid->lines->map(fn ($l) => [
                'item_id' => $l->tender_item_id, 'name' => $l->item?->name, 'price' => (float) $l->price,
                'quantity' => (float) $l->quantity, 'lead_time_days' => $l->lead_time_days, 'amount' => (float) $l->amount,
            ])->all(),
            'specs' => $bid->specs->map(fn ($s) => [
                'spec_id' => $s->tender_spec_id, 'label' => $s->spec?->label, 'required' => $s->spec?->value, 'must' => (bool) $s->spec?->must,
                'value' => $s->value, 'claim' => $s->override_claim ?? $s->claim, 'bidder_claim' => $s->claim, 'override_note' => $s->override_note,
                'file' => $s->content_id && $contents->has($s->content_id) ? ['id' => $s->content_id, 'name' => $contents[$s->content_id]->file_name] : null,
            ])->all(),
            'payments' => $bid->status === 'pending_fee' && self::isBidder($user, $bid)
                ? Payment::where('subject_type', $bid->getMorphClass())->where('subject_id', $bid->id)->where('status', 'pending')->get()->map(fn ($p) => Payments::show($p))->all()
                : [],
        ];
    }

    /** Whether the user sent the bid or belongs to the company that did. */
    public static function isBidder(?User $user, Bid $bid): bool
    {
        if (! $user) {
            return false;
        }

        return $bid->company_id
            ? $user->companyMemberships()->where('company_id', $bid->company_id)->exists()
            : $bid->user_id === $user->id;
    }

    /** Stops with 404 unless the user is the bid's party. */
    public static function requireBidder(?User $user, Bid $bid): void
    {
        abort_unless(self::isBidder($user, $bid), 404);
    }

    /** Whether the user reads the bid: its party, or the poster once bidding has closed. */
    public static function canRead(?User $user, Bid $bid): bool
    {
        if (self::isBidder($user, $bid)) {
            return true;
        }

        return ! in_array($bid->tender->status, ['draft', 'open'], true)
            && in_array($bid->status, Tenders::CURRENT_BIDS, true)
            && Tenders::isPoster($user, $bid->tender);
    }

    /** Whether bids are taken now: the tender is open and inside its window. */
    public static function isOpenForBids(Tender $tender): bool
    {
        return $tender->status === 'open'
            && ($tender->starts_at === null || $tender->starts_at->lte(now()))
            && $tender->closes_at !== null && $tender->closes_at->isFuture();
    }

    /** How long bids stay binding: the rule's date, else the configured days after the close. */
    public static function validUntil(Tender $tender): Carbon
    {
        return $tender->rule['valid_until']
            ? Carbon::parse($tender->rule['valid_until'])->endOfDay()
            : $tender->closes_at->copy()->addDays((int) config('market.tenders.validity_days', 30));
    }

    /** A bid with nothing left to pay is submitted, replacing the party's earlier bid this round. */
    private static function settle(Bid $bid): void
    {
        $bid->refresh();
        $pending = Payment::where('subject_type', $bid->getMorphClass())->where('subject_id', $bid->id)->where('status', 'pending')->exists();
        if ($bid->status !== 'pending_fee' || $pending) {
            return;
        }
        $bid->update(['status' => 'submitted', 'submitted_at' => now()]);

        $earlier = $bid->tender->bids()->whereKeyNot($bid->id)->where('round', $bid->round)
            ->whereIn('status', ['pending_fee', 'submitted'])
            ->where(fn ($q) => $bid->company_id ? $q->where('company_id', $bid->company_id) : $q->whereNull('company_id')->where('user_id', $bid->user_id))
            ->where('id', '<', $bid->id)->get();
        foreach ($earlier as $old) {
            $old->update(['status' => 'superseded']);
            Payment::where('subject_type', $old->getMorphClass())->where('subject_id', $old->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        }
    }

    /**
     * Checks the lines against the tender: known items, every item unless it splits, the item's
     * quantity unless partial quantities are allowed.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{tender_item_id: int, price: float, quantity: float, lead_time_days: ?int, amount: float}>
     */
    public static function checkLines(Tender $tender, array $lines): array
    {
        $rule = $tender->rule;
        $items = $tender->items()->get()->keyBy('id');
        $errors = [];
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $item = $items->get((int) $line['item_id']);
            if (! $item) {
                $errors["lines.{$i}.item_id"] = __('This item is not in the tender.');

                continue;
            }
            $wanted = (float) ($item->quantity ?? 1);
            $quantity = isset($line['quantity']) ? (float) $line['quantity'] : $wanted;
            if (! $rule['partial'] && abs($quantity - $wanted) > 0.0001) {
                $errors["lines.{$i}.quantity"] = __('This tender takes the full quantity of each item.');
            } elseif ($quantity <= 0 || $quantity > $wanted + 0.0001) {
                $errors["lines.{$i}.quantity"] = __('Offer between 0 and the quantity asked.');
            }
            $out[$item->id] = [
                'tender_item_id' => $item->id,
                'price' => round((float) $line['price'], 2),
                'quantity' => $quantity,
                'lead_time_days' => isset($line['lead_time_days']) ? (int) $line['lead_time_days'] : null,
                'amount' => round((float) $line['price'] * $quantity, 2),
            ];
        }
        if ($rule['split'] === 'none' && ! $rule['partial'] && count($out) !== $items->count()) {
            $errors['lines'] = __('Price every item of this tender.');
        }
        if (! $out) {
            $errors['lines'] = __('Price at least one item.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($out);
    }

    /**
     * Answers to the specs of the items bid on; a spec left unanswered counts as failed.
     *
     * @param  list<array<string, mixed>>  $specs
     * @param  list<int>  $itemIds
     */
    public static function fillSpecs(User $by, Tender $tender, Bid $bid, array $specs, array $itemIds): void
    {
        $answers = collect($specs)->keyBy(fn ($s) => (int) $s['spec_id']);
        $tender->loadMissing('items.specs');
        foreach ($tender->items->whereIn('id', $itemIds) as $item) {
            foreach ($item->specs as $spec) {
                $answer = $answers->get($spec->id, []);
                $contentId = null;
                if (($answer['file'] ?? null) instanceof UploadedFile) {
                    Tenders::checkFile($answer['file']);
                    $contentId = Content::create([
                        'physical_path' => $answer['file']->store('market/bids', 'local'),
                        'file_name' => $answer['file']->getClientOriginalName(),
                        'file_extension' => strtolower($answer['file']->getClientOriginalExtension()),
                        'file_size' => $answer['file']->getSize(),
                        'is_public' => false,
                        'created_by' => $by->id,
                    ])->id;
                }
                $bid->specs()->create([
                    'tender_spec_id' => $spec->id,
                    'value' => $answer['value'] ?? null,
                    'claim' => in_array($answer['claim'] ?? null, Bid::CLAIMS, true) ? $answer['claim'] : 'fails',
                    'content_id' => $contentId,
                ]);
            }
        }
    }
}
