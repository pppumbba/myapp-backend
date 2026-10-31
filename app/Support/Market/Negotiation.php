<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\Tender;
use App\Models\TenderMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Negotiation (procedure "negotiated"): the poster shortlists bids, talks to each bidder
 * privately, shortlisted bidders revise without a new fee, and a best-and-final round with a
 * deadline ends it; then the tender is evaluated again.
 */
class Negotiation
{
    /**
     * The poster picks the bids to negotiate with. The tender moves to round 2 (or the next).
     *
     * @param  list<int>  $bidIds
     */
    public static function shortlist(User $by, Tender $tender, array $bidIds): Tender
    {
        Tenders::requirePoster($by, $tender);
        abort_unless($tender->rule['procedure'] === 'negotiated', 422, __('This tender has no negotiation.'));
        abort_unless($tender->status === 'evaluating', 422, __('Shortlist while evaluating.'));
        $bids = Evaluation::currentBids($tender)->whereIn('id', $bidIds)->where('status', 'submitted');
        abort_if($bids->isEmpty() || $bids->count() !== count(array_unique($bidIds)), 422, __('Pick bids that are still in.'));

        return DB::transaction(function () use ($by, $tender, $bids) {
            $tender->bids()->update(['shortlisted' => false]);
            Bid::whereIn('id', $bids->pluck('id'))->update(['shortlisted' => true]);
            $tender->update(['status' => 'negotiating', 'round' => ($tender->round ?? 1) + 1, 'final_round_closes_at' => null]);
            Tenders::log($tender, $by, 'shortlisted', null, ['bid_ids' => $bids->pluck('id')->all()]);
            foreach ($bids as $bid) {
                MarketNotify::send(User::findOrFail($bid->submitted_by), __('You are shortlisted: :title', ['title' => $tender->title]), __('The organizer may ask you to improve your offer.'), Tenders::link($tender), 'success');
            }

            return $tender->fresh();
        });
    }

    /** One line in the private talk between the poster and one bidder. */
    public static function message(User $by, Bid $bid, string $body): TenderMessage
    {
        $tender = $bid->tender;
        $poster = Tenders::isPoster($by, $tender);
        abort_unless($poster || Bids::isBidder($by, $bid), 404);
        abort_unless($tender->status === 'negotiating' && $bid->shortlisted, 422, __('Messages are open while negotiating with shortlisted bidders.'));

        $message = TenderMessage::create(['tender_id' => $tender->id, 'bid_id' => $bid->id, 'user_id' => $by->id, 'side' => $poster ? 'poster' : 'bidder', 'body' => $body]);
        $to = $poster ? User::whereKey($bid->submitted_by)->get() : User::whereIn('id', Tenders::posters($tender))->get();
        foreach ($to as $user) {
            MarketNotify::send($user, __('New message on :title', ['title' => $tender->title]), mb_strimwidth($body, 0, 140, '…'), Tenders::link($tender));
        }

        return $message;
    }

    /**
     * The talk about one bid, oldest first: for the poster and that bidder only.
     *
     * @return list<array{id: int, side: string, body: string, user: ?string, created_at: mixed}>
     */
    public static function messages(User $by, Bid $bid): array
    {
        abort_unless(Tenders::isPoster($by, $bid->tender) || Bids::isBidder($by, $bid), 404);

        return self::thread($bid)->get()->map(fn (TenderMessage $m) => [
            'id' => $m->id, 'side' => $m->side, 'body' => $m->body, 'user' => $m->user?->verified_name ?? $m->user?->name, 'created_at' => $m->created_at,
        ])->all();
    }

    /**
     * A shortlisted bidder sends a better offer. It replaces their bid without a new fee.
     *
     * @param  array{lead_time_days?: ?int, lines: list<array<string, mixed>>, specs?: list<array<string, mixed>>}  $data
     */
    public static function revise(User $by, Bid $bid, array $data): Bid
    {
        Bids::requireBidder($by, $bid);
        $tender = $bid->tender;
        abort_unless($tender->status === 'negotiating' && $bid->shortlisted && $bid->status === 'submitted', 422, __('Only a shortlisted bid can be revised while negotiating.'));
        abort_if($tender->final_round_closes_at && $tender->final_round_closes_at->isPast(), 422, __('The final round is over.'));
        $lines = Bids::checkLines($tender, $data['lines']);

        return DB::transaction(function () use ($by, $bid, $tender, $data, $lines) {
            $new = $tender->bids()->create([
                'company_id' => $bid->company_id,
                'user_id' => $bid->user_id,
                'submitted_by' => $by->id,
                'round' => $tender->round,
                'status' => 'submitted',
                'submitted_at' => now(),
                'shortlisted' => true,
                'lead_time_days' => (int) ($data['lead_time_days'] ?? max(array_column($lines, 'lead_time_days') ?: [(int) $bid->lead_time_days])),
                'valid_until' => $bid->valid_until,
                'total' => array_sum(array_column($lines, 'amount')),
            ]);
            foreach ($lines as $line) {
                $new->lines()->create($line);
            }
            $specs = $data['specs'] ?? $bid->specs->map(fn ($s) => ['spec_id' => $s->tender_spec_id, 'value' => $s->value, 'claim' => $s->claim])->all();
            Bids::fillSpecs($by, $tender, $new, $specs, array_column($lines, 'tender_item_id'));
            $carried = $bid->specs->keyBy('tender_spec_id');
            foreach ($new->specs as $answer) {
                if (! isset($data['specs']) && $carried->has($answer->tender_spec_id)) {
                    $answer->update(['content_id' => $carried[$answer->tender_spec_id]->content_id]);
                }
            }
            $bid->update(['status' => 'superseded']);
            TenderMessage::where('bid_id', $bid->id)->update(['bid_id' => $new->id]);
            Tenders::log($tender, $by, 'bid_revised', null, ['bid_id' => $new->id, 'replaces' => $bid->id]);
            foreach (User::whereIn('id', Tenders::posters($tender))->get() as $user) {
                MarketNotify::send($user, __('Revised offer on :title', ['title' => $tender->title]), null, Tenders::link($tender));
            }

            return $new->fresh();
        });
    }

    /** The poster asks the shortlist for best and final offers by a date (default 3 days). */
    public static function finalRound(User $by, Tender $tender, ?string $closesAt): Tender
    {
        Tenders::requirePoster($by, $tender);
        abort_unless($tender->status === 'negotiating', 422, __('The tender is not in negotiation.'));
        $until = $closesAt ? Carbon::parse($closesAt) : now()->addDays((int) config('market.tenders.final_round_days', 3));
        abort_unless($until->isFuture(), 422, __('The final round must end in the future.'));

        $tender->update(['round' => $tender->round + 1, 'final_round_closes_at' => $until]);
        Tenders::log($tender, $by, 'final_round', null, ['closes_at' => $until->toDateTimeString()]);
        foreach ($tender->bids()->where('shortlisted', true)->where('status', 'submitted')->get() as $bid) {
            MarketNotify::send(User::findOrFail($bid->submitted_by), __('Final offers due: :title', ['title' => $tender->title]), __('Send your best and final offer by :date.', ['date' => $until->toDateTimeString()]), Tenders::link($tender), 'warning');
        }

        return $tender->fresh();
    }

    /** The poster ends the negotiation; the tender goes back to evaluation. */
    public static function finish(User $by, Tender $tender): Tender
    {
        Tenders::requirePoster($by, $tender);
        abort_unless($tender->status === 'negotiating', 422, __('The tender is not in negotiation.'));
        $tender->update(['status' => 'evaluating']);
        Tenders::log($tender, $by, 'negotiation_finished');
        Evaluation::apply($tender);

        return $tender->fresh();
    }

    private static function thread(Bid $bid): Builder
    {
        return TenderMessage::where('bid_id', $bid->id)->with('user')->orderBy('id');
    }
}
