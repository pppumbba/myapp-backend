<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\BidSpec;
use App\Models\Tender;
use App\Models\TenderSpec;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Evaluation: after the close the poster sees every current bid checked against the specs,
 * scored and ranked by the tender's award rule. A failed must-have rules a bid out unless the
 * poster overturns it; the poster may also correct a bidder's claim. Both are logged.
 */
class Evaluation
{
    /** Quality points per claim on a nice-to-have spec. */
    public const CLAIM_POINTS = ['meets' => 1.0, 'exceeds' => 1.2, 'fails' => 0.0];

    /** The poster opens evaluation of a closed tender. */
    public static function start(User $by, Tender $tender): Tender
    {
        Tenders::requirePoster($by, $tender);
        abort_unless($tender->status === 'closed', 422, __('Only a closed tender can be evaluated.'));
        $tender->update(['status' => 'evaluating']);
        Tenders::log($tender, $by, 'evaluation_started');
        self::apply($tender);

        return $tender->fresh();
    }

    /** The poster corrects what a bidder claimed for one spec. */
    public static function overrideClaim(User $by, BidSpec $answer, string $claim, ?string $note): BidSpec
    {
        $bid = $answer->bid;
        $tender = $bid->tender;
        self::requireEvaluating($by, $tender);
        $answer->update(['override_claim' => $claim, 'override_by' => $by->id, 'override_note' => $note]);
        Tenders::log($tender, $by, 'claim_overridden', $note, ['bid_id' => $bid->id, 'bid_spec_id' => $answer->id, 'claim' => $claim]);
        self::apply($tender);

        return $answer->fresh();
    }

    /** The poster keeps a bid in although a must-have failed — or takes that back. */
    public static function overturn(User $by, Bid $bid, bool $overturn, ?string $note): Bid
    {
        $tender = $bid->tender;
        self::requireEvaluating($by, $tender);
        abort_unless(in_array($bid->status, Tenders::CURRENT_BIDS, true), 422, __('This bid is not in the evaluation.'));
        $bid->update(['overturned' => $overturn]);
        Tenders::log($tender, $by, $overturn ? 'bid_overturned' : 'bid_overturn_removed', $note, ['bid_id' => $bid->id]);
        self::apply($tender);

        return $bid->fresh();
    }

    /**
     * Every current bid of the tender's round: compliance, quality, scores and rank; and per item
     * the lowest price among bids still in.
     *
     * @return array{rule: array<string, mixed>, bids: list<array<string, mixed>>, items: list<array{id: int, name: string, best_bid_id: ?int, best_price: ?float}>}
     */
    public static function of(Tender $tender): array
    {
        $rule = $tender->rule;
        $tender->loadMissing('items');
        $bids = self::currentBids($tender);

        $rows = $bids->map(function (Bid $bid) {
            $specs = $bid->specs->map(fn (BidSpec $answer) => [
                'id' => $answer->id,
                'spec_id' => $answer->tender_spec_id,
                'label' => $answer->spec?->label,
                'required' => $answer->spec ? trim(($answer->spec->operator ? self::operatorSign($answer->spec->operator).' ' : '').$answer->spec->value.' '.$answer->spec->unit) : null,
                'must' => (bool) $answer->spec?->must,
                'value' => $answer->value,
                'bidder_claim' => $answer->claim,
                'claim' => self::effectiveClaim($answer),
                'override_note' => $answer->override_note,
                'overridden' => $answer->override_claim !== null,
                'file_id' => $answer->content_id,
            ]);
            $failedMust = $specs->where('must', true)->where('claim', 'fails')->pluck('label')->values()->all();
            $nice = $specs->where('must', false);
            $quality = $nice->isEmpty() ? 100.0 : round($nice->avg(fn ($s) => self::CLAIM_POINTS[$s['claim']] ?? 0) * 100, 2);

            return [
                'id' => $bid->id,
                'party' => $bid->company ? ['type' => 'company', 'id' => $bid->company->id, 'name' => $bid->company->name, 'registry_number' => $bid->company->registry_number]
                    : ['type' => 'person', 'id' => $bid->user_id, 'name' => $bid->user?->verified_name, 'registry_number' => null],
                'total' => (float) $bid->total,
                'lead_time_days' => (int) $bid->lead_time_days,
                'quality_score' => $quality,
                'failed_musts' => $failedMust,
                'overturned' => (bool) $bid->overturned,
                'compliant' => ! $failedMust,
                'in' => ! $failedMust || $bid->overturned,
                'shortlisted' => (bool) $bid->shortlisted,
                'lines' => $bid->lines->map(fn ($l) => ['item_id' => $l->tender_item_id, 'price' => (float) $l->price, 'quantity' => (float) $l->quantity, 'amount' => (float) $l->amount])->all(),
                'specs' => $specs->values()->all(),
            ];
        });

        $rows = self::rank($rows, $rule);
        $items = $tender->items->map(function ($item) use ($rows) {
            $best = $rows->where('in', true)->map(fn ($r) => ['bid' => $r['id'], 'line' => collect($r['lines'])->firstWhere('item_id', $item->id)])
                ->filter(fn ($x) => $x['line'] !== null)->sortBy(fn ($x) => $x['line']['price'])->first();

            return ['id' => $item->id, 'name' => $item->name, 'best_bid_id' => $best['bid'] ?? null, 'best_price' => $best ? $best['line']['price'] : null];
        });

        return ['rule' => $rule, 'bids' => $rows->values()->all(), 'items' => $items->values()->all()];
    }

    /** Writes each bid's quality score and in/out status from the evaluation. */
    public static function apply(Tender $tender): void
    {
        foreach (self::of($tender->fresh())['bids'] as $row) {
            Bid::whereKey($row['id'])->update([
                'quality_score' => $row['quality_score'],
                'status' => $row['in'] ? 'submitted' : 'ruled_out',
                'ruled_out_reason' => $row['in'] ? null : __('Fails: :specs', ['specs' => implode(', ', $row['failed_musts'])]),
            ]);
        }
    }

    /** Stops unless the user is the poster and the tender is under evaluation. */
    public static function requireEvaluating(?User $user, Tender $tender): void
    {
        Tenders::requirePoster($user, $tender);
        abort_unless(in_array($tender->status, ['evaluating', 'negotiating'], true), 422, __('The tender is not under evaluation.'));
    }

    /** The claim that counts: the poster's correction, else a failed numeric check, else the bidder's word. */
    public static function effectiveClaim(BidSpec $answer): string
    {
        if ($answer->override_claim !== null) {
            return $answer->override_claim;
        }
        if ($answer->spec?->kind === 'number' && ! self::numberMeets($answer->spec, $answer->value)) {
            return 'fails';
        }

        return in_array($answer->claim, ['meets', 'exceeds', 'fails'], true) ? $answer->claim : 'fails';
    }

    /** Whether an offered value passes a number spec; a blank or non-number fails. */
    public static function numberMeets(TenderSpec $spec, ?string $value): bool
    {
        if ($value === null || ! is_numeric(trim($value)) || ! is_numeric($spec->value)) {
            return false;
        }
        $offered = (float) trim($value);
        $wanted = (float) $spec->value;

        return match ($spec->operator) {
            'gte' => $offered >= $wanted - 1e-9,
            'lte' => $offered <= $wanted + 1e-9,
            default => abs($offered - $wanted) < 1e-9,
        };
    }

    /**
     * The bids that take part: sent and not replaced or withdrawn. A revision supersedes its
     * party's earlier bid, so each party has one current bid whatever round it came from.
     */
    public static function currentBids(Tender $tender): Collection
    {
        return $tender->bids()->whereIn('status', Tenders::CURRENT_BIDS)
            ->with('specs.spec', 'lines', 'company', 'user')->orderBy('id')->get();
    }

    /**
     * Scores and ranks bids still in by the award rule; ruled-out bids get no rank.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function rank(Collection $rows, array $rule): Collection
    {
        $in = $rows->where('in', true);
        $minTotal = (float) ($in->min('total') ?? 0);
        $minLead = (int) ($in->min('lead_time_days') ?? 0);
        $maxQuality = (float) ($in->max('quality_score') ?? 0);
        $weights = $rule['weights'];

        $rows = $rows->map(function (array $row) use ($minTotal, $minLead, $maxQuality, $weights) {
            $price = $row['total'] > 0 ? $minTotal / $row['total'] * 100 : 100;
            $lead = $row['lead_time_days'] > 0 ? $minLead / $row['lead_time_days'] * 100 : 100;
            $quality = $maxQuality > 0 ? $row['quality_score'] / $maxQuality * 100 : 100;
            $row['scores'] = ['price' => round($price, 2), 'lead' => round($lead, 2), 'quality' => round($quality, 2)];
            $row['score'] = round(($weights['price'] * $price + $weights['lead'] * $lead + $weights['quality'] * $quality) / 100, 2);

            return $row;
        });

        $key = match ($rule['award']) {
            'fastest' => fn ($r) => [$r['lead_time_days'], $r['total']],
            'highest_quality' => fn ($r) => [-$r['quality_score'], $r['total']],
            'best_value' => fn ($r) => [-$r['score'], $r['total']],
            default => fn ($r) => [$r['total'], $r['lead_time_days']],
        };
        $ordered = $rows->where('in', true)->sort(fn ($a, $b) => $key($a) <=> $key($b))->values();
        $ranks = $ordered->pluck('id')->flip();

        return $rows->map(fn ($r) => $r + ['rank' => isset($ranks[$r['id']]) ? $ranks[$r['id']] + 1 : null]);
    }

    private static function operatorSign(string $operator): string
    {
        return ['gte' => '≥', 'lte' => '≤', 'eq' => '='][$operator] ?? $operator;
    }
}
