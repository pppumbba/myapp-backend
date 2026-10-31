<?php

namespace App\Support\Market;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Payment;
use App\Models\Rating;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Contracts between a buyer and a vendor: drafting and editing, who may see and act, and what
 * each side sees. Each side is a company (its members browse, those with the sign right act)
 * or a verified person. Nothing here trusts the UI to hide anything.
 */
class Contracts
{
    /** Statuses in which the terms can still change. */
    public const EDITABLE = ['draft', 'signing'];

    /** Statuses in which either side can still walk away. */
    public const CANCELLABLE = ['draft', 'signing', 'awaiting_payment'];

    /**
     * A new draft. The user is the buyer, for a company they may sign for or as themselves; the
     * vendor is found by registry number (a market company's 7 digits, or a verified person's).
     *
     * @param  array<string, mixed>  $data
     */
    public static function draft(User $by, array $data): Contract
    {
        abort_unless($by->isVerified(), 403, __('Verify yourself with DAN first.'));
        $buyerCompany = null;
        if (! empty($data['buyer_company_id'])) {
            $buyerCompany = Company::findOrFail($data['buyer_company_id']);
            abort_unless(MarketAccess::canActFor($by, $buyerCompany, 'sign'), 403, __('You may not sign for this company.'));
        }
        [$vendorCompany, $vendorUser] = self::vendor((string) ($data['vendor_registry_number'] ?? ''));
        abort_if(
            ($buyerCompany && $vendorCompany?->is($buyerCompany)) || (! $buyerCompany && $vendorUser?->is($by)),
            422,
            __('The buyer and the vendor must be different.'),
        );

        return DB::transaction(function () use ($by, $data, $buyerCompany, $vendorCompany, $vendorUser) {
            $contract = Contract::create([
                'source' => $data['source'] ?? 'direct',
                'source_id' => $data['source_id'] ?? null,
                'title' => $data['title'],
                'buyer_company_id' => $buyerCompany?->id,
                'buyer_user_id' => $buyerCompany ? null : $by->id,
                'vendor_company_id' => $vendorCompany?->id,
                'vendor_user_id' => $vendorUser?->id,
                'status' => 'draft',
                'version' => 1,
                'middleman' => 'off',
                'created_by' => $by->id,
            ]);
            self::fill($contract, $data);
            self::log($contract, $by, 'created', null, 'draft');
            self::notifySide($contract, 'vendor', __('A contract to review: :title', ['title' => $contract->title]), __('Check the terms, then sign or ask for changes.'));

            return $contract;
        });
    }

    /**
     * Either side changes the terms before both have signed. The version goes up, and any
     * signature (made on the old terms) is dropped.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(User $by, Contract $contract, array $data): Contract
    {
        $side = self::requireSigner($by, $contract);
        abort_unless(in_array($contract->status, self::EDITABLE, true), 422, __('The contract can no longer be changed.'));

        return DB::transaction(function () use ($by, $contract, $data, $side) {
            $from = $contract->status;
            $contract->signatures()->delete();
            $contract->forceFill(['version' => $contract->version + 1, 'status' => 'draft'])->save();
            self::fill($contract, $data);
            self::log($contract, $by, 'edited', $from, 'draft');
            self::notifySide($contract, self::other($side), __('The contract :number was changed', ['number' => $contract->number]), __('Signatures were cleared: check the new terms and sign again.'));

            return $contract;
        });
    }

    /** Either side walks away before the contract is in force. */
    public static function cancel(User $by, Contract $contract, ?string $reason): Contract
    {
        $side = self::requireSigner($by, $contract);
        abort_unless(in_array($contract->status, self::CANCELLABLE, true), 422, __('The contract can no longer be cancelled.'));

        return DB::transaction(function () use ($by, $contract, $reason, $side) {
            $from = $contract->status;
            $contract->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            Payment::where(['subject_type' => $contract->getMorphClass(), 'subject_id' => $contract->id, 'status' => 'pending'])
                ->update(['status' => 'cancelled']);
            self::log($contract, $by, 'cancelled', $from, 'cancelled', $reason);
            self::notifySide($contract, self::other($side), __('The contract :number was cancelled', ['number' => $contract->number]), $reason);

            return $contract;
        });
    }

    /** Where to send the signer: a fresh DAN sign-in confirms the signature on this version. */
    public static function startSigning(User $by, Contract $contract): string
    {
        $side = self::requireSigner($by, $contract);
        abort_unless(in_array($contract->status, self::EDITABLE, true), 422, __('The contract can no longer be signed.'));
        abort_if(self::signedBy($contract, $side), 422, __('Your side has already signed this version.'));

        return Verification::start($by, ['type' => 'sign', 'contract' => $contract->id, 'version' => $contract->version, 'side' => $side]);
    }

    /**
     * Back from DAN: the person who signed in must be the user who started, still able to sign
     * for that side, on the same version of the terms.
     *
     * @param  array<string, mixed>  $purpose
     * @param  array{registry_number: string, name: string}  $citizen
     */
    public static function completeSigning(User $user, array $purpose, array $citizen): Contract
    {
        abort_unless($citizen['registry_number'] === $user->registry_number, 422, __('You signed in to DAN as someone else.'));

        return DB::transaction(function () use ($user, $purpose, $citizen) {
            $contract = Contract::lockForUpdate()->findOrFail($purpose['contract']);
            $side = self::requireSigner($user, $contract, $purpose['side']);
            abort_unless(
                (int) $purpose['version'] === $contract->version && in_array($contract->status, self::EDITABLE, true),
                422,
                __('The contract changed while you were signing. Check it and sign again.'),
            );
            abort_if(self::signedBy($contract, $side), 422, __('Your side has already signed this version.'));

            $contract->signatures()->where('side', $side)->delete();
            $contract->signatures()->create([
                'side' => $side,
                'user_id' => $user->id,
                'registry_number' => $citizen['registry_number'],
                'verified_name' => $citizen['name'],
                'version' => $contract->version,
                'signed_at' => now(),
            ]);

            if (! self::signedBy($contract, self::other($side))) {
                $contract->forceFill(['status' => 'signing'])->save();
                self::log($contract, $user, 'signed', 'draft', 'signing');
                self::notifySide($contract, self::other($side), __('The contract :number is waiting for your signature', ['number' => $contract->number]));

                return $contract;
            }

            return self::bothSigned($contract, $user);
        });
    }

    /** A paid fee invoice puts the contract in force. */
    public static function paymentPaid(Payment $payment): void
    {
        $contract = $payment->subject;
        if (! $contract instanceof Contract || $contract->status !== 'awaiting_payment') {
            return;
        }
        $contract->forceFill(['status' => 'active'])->save();
        self::log($contract, $payment->user, 'fees_paid', 'awaiting_payment', 'active');
        self::announceInForce($contract);
    }

    /** The vendor starts the work. */
    public static function start(User $by, Contract $contract): Contract
    {
        return self::move($by, $contract, 'vendor', ['active'], 'in_progress', 'started', __('Work on :number has started', ['number' => $contract->number]));
    }

    /** The vendor says it is delivered or done; the buyer is asked to confirm. */
    public static function deliver(User $by, Contract $contract, ?string $note): Contract
    {
        return self::move($by, $contract, 'vendor', ['active', 'in_progress'], 'delivered', 'delivered', __(':number is delivered: please confirm', ['number' => $contract->number]), $note);
    }

    /** The buyer confirms the delivery: the contract is complete. */
    public static function confirm(User $by, Contract $contract): Contract
    {
        $contract = self::move($by, $contract, 'buyer', ['delivered'], 'completed', 'completed', __(':number is complete', ['number' => $contract->number]));
        $contract->forceFill(['completed_at' => now()])->save();
        if ($contract->middleman === 'escrow') {
            // The buyer's confirmation is the release point for whatever is still held.
            Escrow::releaseHeld($contract, $by);
        }

        return $contract;
    }

    /** The buyer turns the delivery down, saying why; the vendor works on. */
    public static function reject(User $by, Contract $contract, string $reason): Contract
    {
        return self::move($by, $contract, 'buyer', ['delivered'], 'in_progress', 'rejected', __('The delivery of :number was not accepted', ['number' => $contract->number]), $reason);
    }

    /** One side rates the other after completion, once. */
    public static function rate(User $by, Contract $contract, int $stars, ?string $comment): Contract
    {
        $side = self::requireSigner($by, $contract);
        abort_unless($contract->status === 'completed', 422, __('Only a completed contract can be rated.'));
        abort_if($contract->ratings()->where('side', $side)->exists(), 422, __('Your side has already rated this contract.'));
        $other = self::other($side);
        $contract->ratings()->create([
            'side' => $side,
            'rater_user_id' => $by->id,
            'rated_company_id' => $other === 'buyer' ? $contract->buyer_company_id : $contract->vendor_company_id,
            'rated_user_id' => $other === 'buyer' ? $contract->buyer_user_id : $contract->vendor_user_id,
            'stars' => $stars,
            'comment' => $comment,
        ]);
        self::notifySide($contract, $other, __('You were rated :stars/5 on :number', ['stars' => $stars, 'number' => $contract->number]), $comment);

        return $contract->unsetRelation('ratings');
    }

    /**
     * The average rating a company or person has received, and from how many contracts.
     *
     * @return array{average: float|null, count: int}
     */
    public static function ratingOf(?Company $company, ?User $user): array
    {
        $query = $company ? Rating::where('rated_company_id', $company->id) : Rating::whereNull('rated_company_id')->where('rated_user_id', $user?->id);
        $count = (clone $query)->count();

        return ['average' => $count ? round((float) $query->avg('stars'), 1) : null, 'count' => $count];
    }

    /** The PDF of the signed contract, made once both sides signed. */
    public static function pdf(Contract $contract): string
    {
        $data = self::show($contract, new User);
        $data['signatures'] = $contract->signatures()->where('version', $contract->version)->get();

        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('market.contract', ['c' => $data])
            ->output();
    }

    /** 'buyer', 'vendor', or null when the user is on neither side. */
    public static function sideOf(?User $user, Contract $contract): ?string
    {
        foreach (['buyer', 'vendor'] as $side) {
            if (self::belongs($user, $contract, $side, 'browse')) {
                return $side;
            }
        }

        return null;
    }

    /** Whether the user may act (sign, change, confirm) for this side. */
    public static function canActAs(?User $user, Contract $contract, string $side): bool
    {
        return self::belongs($user, $contract, $side, 'sign');
    }

    /** 403 unless the user is on a side; returns it. */
    public static function requireSide(?User $user, Contract $contract): string
    {
        $side = self::sideOf($user, $contract);
        abort_unless($side, 403, __('This contract is not yours.'));

        return $side;
    }

    /** 403 unless the user may act for their side; returns it. */
    public static function requireSigner(?User $user, Contract $contract, ?string $side = null): string
    {
        $mine = self::requireSide($user, $contract);
        abort_if($side !== null && $mine !== $side, 403, __('Only the :side can do this.', ['side' => self::sideLabel($side ?? $mine)]));
        abort_unless(self::canActAs($user, $contract, $mine), 403, __('You may not sign for this company.'));

        return $mine;
    }

    /** The user's contracts on either side, newest first. */
    public static function listFor(User $user): LengthAwarePaginator
    {
        $companies = $user->isVerified()
            ? $user->companyMemberships()->whereHas('company', fn (Builder $q) => $q->whereNull('frozen_at'))->pluck('company_id')
            : collect();

        return Contract::with('buyerCompany', 'buyerUser', 'vendorCompany', 'vendorUser')
            ->where(function (Builder $q) use ($user, $companies) {
                $q->whereIn('buyer_company_id', $companies)->orWhereIn('vendor_company_id', $companies);
                if ($user->isVerified()) {
                    $q->orWhere('buyer_user_id', $user->id)->orWhere('vendor_user_id', $user->id);
                }
            })
            ->latest('updated_at')->latest('id')
            ->paginate(25);
    }

    /** A row of the user's contract list. */
    public static function summary(Contract $contract, User $user): array
    {
        $side = self::sideOf($user, $contract);

        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'title' => $contract->title,
            'status' => $contract->status,
            'side' => $side,
            'other' => self::party($contract, $side ? self::other($side) : 'vendor'),
            'total' => (float) $contract->total,
            'updated_at' => $contract->updated_at,
        ];
    }

    /** Everything a side sees of the contract, and what this user may do now. */
    public static function show(Contract $contract, User $user): array
    {
        $contract->loadMissing('items', 'steps', 'signatures', 'events.user', 'ratings', 'disputes');
        $side = self::sideOf($user, $contract);
        $acts = $side && self::canActAs($user, $contract, $side);
        $subtotal = (float) $contract->items->sum('amount');

        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'title' => $contract->title,
            'source' => $contract->source,
            'status' => $contract->status,
            'version' => $contract->version,
            'side' => $side,
            'buyer' => self::party($contract, 'buyer'),
            'vendor' => self::party($contract, 'vendor'),
            'items' => $contract->items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'description' => $i->description, 'quantity' => (float) $i->quantity,
                'unit' => $i->unit, 'unit_price' => (float) $i->unit_price, 'amount' => (float) $i->amount,
            ])->values(),
            'subtotal' => $subtotal,
            'discount' => (float) $contract->discount,
            'total' => (float) $contract->total,
            'delivery_date' => $contract->delivery_date?->toDateString(),
            'payment_terms' => $contract->payment_terms,
            'pledge' => $contract->pledge,
            'pledge_amount' => $contract->pledge_amount === null ? null : (float) $contract->pledge_amount,
            'middleman' => $contract->middleman,
            'steps' => $contract->steps->map(fn ($s) => [
                'id' => $s->id, 'label' => $s->label, 'percent' => (float) $s->percent, 'amount' => (float) $s->amount,
                'status' => $s->status, 'requested_at' => $s->requested_at, 'auto_release_at' => $s->auto_release_at,
                'released_at' => $s->released_at, 'note' => $s->note,
                // The escrow invoice this user has open for the step.
                'payment' => ($p = $user->id ? Payment::where(['contract_step_id' => $s->id, 'kind' => 'escrow', 'status' => 'pending', 'user_id' => $user->id])->latest('id')->first() : null) ? Payments::show($p) : null,
                'can' => self::stepCan($contract, $s->status, $side, $acts),
            ])->values(),
            'disputes' => $contract->disputes->sortByDesc('id')->map(fn ($d) => [
                'id' => $d->id, 'side' => $d->side, 'reason' => $d->reason, 'status' => $d->status, 'resolution' => $d->resolution,
                'decision' => $d->decision, 'created_at' => $d->created_at, 'decided_at' => $d->decided_at,
            ])->values(),
            'signatures' => $contract->signatures->where('version', $contract->version)->map(fn ($s) => [
                'side' => $s->side, 'verified_name' => $s->verified_name, 'signed_at' => $s->signed_at,
            ])->values(),
            'fees' => self::feesDue($contract),
            'events' => $contract->events->map(fn (ContractEvent $e) => [
                'type' => $e->type, 'by' => $e->user?->verified_name ?? $e->user?->name, 'from' => $e->from_status,
                'to' => $e->to_status, 'note' => $e->note, 'at' => $e->created_at,
            ])->values(),
            'has_pdf' => $contract->pdf_path !== null,
            'signed_at' => $contract->signed_at,
            'completed_at' => $contract->completed_at,
            // The fee invoice, to the buyer's signer who has it to pay.
            'payment' => ($payment = Payment::where(['subject_type' => $contract->getMorphClass(), 'subject_id' => $contract->id, 'user_id' => $user->id, 'status' => 'pending'])
                ->whereIn('kind', ['contract', 'middleman'])->latest('id')->first()) ? Payments::show($payment) : null,
            'can' => [
                'edit' => $acts && in_array($contract->status, self::EDITABLE, true),
                'cancel' => $acts && in_array($contract->status, self::CANCELLABLE, true),
                'sign' => $acts && in_array($contract->status, self::EDITABLE, true) && ! self::signedBy($contract, $side),
                'start' => $acts && $side === 'vendor' && $contract->status === 'active',
                'deliver' => $acts && $side === 'vendor' && in_array($contract->status, ['active', 'in_progress'], true),
                'confirm' => $acts && $side === 'buyer' && $contract->status === 'delivered',
                'rate' => $acts && $contract->status === 'completed' && ! $contract->ratings->contains('side', $side),
                'dispute' => $acts && $contract->middleman !== 'off' && in_array($contract->status, Escrow::RUNNING, true)
                    && ! $contract->disputes->contains('status', 'open'),
            ],
            'ratings' => $contract->ratings->map(fn (Rating $r) => [
                'side' => $r->side, 'stars' => $r->stars, 'comment' => $r->comment, 'created_at' => $r->created_at,
            ])->values(),
        ];
    }

    /**
     * What the buyer pays the market on this contract: the contract fee (not on a tender's
     * contract, whose poster paid to post) and the middleman fee when the service is on.
     *
     * @return array{contract: int, middleman: int, total: int}
     */
    public static function feesDue(Contract $contract): array
    {
        $value = (float) $contract->total;
        $fee = $contract->source === 'tender' ? 0 : Fees::quote('contract', $value);
        $middleman = $contract->middleman === 'off' ? 0 : Fees::quote('middleman', $value);

        return ['contract' => $fee, 'middleman' => $middleman, 'total' => $fee + $middleman];
    }

    /** The people who act for a side: the person, or the company's members who may sign. */
    public static function signersOf(Contract $contract, string $side): array
    {
        $company = $side === 'buyer' ? $contract->buyerCompany : $contract->vendorCompany;
        if ($company) {
            return $company->members()->where('can_sign', true)->with('user')->get()->pluck('user')->all();
        }
        $user = $side === 'buyer' ? $contract->buyerUser : $contract->vendorUser;

        return $user ? [$user] : [];
    }

    /** Tells a side's signers what happened, with a link to the contract. */
    public static function notifySide(Contract $contract, string $side, string $title, ?string $body = null, string $type = 'info'): void
    {
        foreach (self::signersOf($contract, $side) as $user) {
            MarketNotify::send($user, $title, $body, "/market/contracts/{$contract->id}", $type);
        }
    }

    /** Adds a line to the contract's timeline. */
    public static function log(Contract $contract, ?User $by, string $type, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        $contract->events()->create(['user_id' => $by?->id, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    public static function other(string $side): string
    {
        return $side === 'buyer' ? 'vendor' : 'buyer';
    }

    /** @return array{type: string, id: int|null, name: string|null, registry_number: string|null}|null */
    public static function party(Contract $contract, string $side): ?array
    {
        $company = $side === 'buyer' ? $contract->buyerCompany : $contract->vendorCompany;
        if ($company) {
            return ['type' => 'company', 'id' => $company->id, 'name' => $company->name, 'registry_number' => $company->registry_number, 'rating' => self::ratingOf($company, null)];
        }
        $user = $side === 'buyer' ? $contract->buyerUser : $contract->vendorUser;

        return $user ? ['type' => 'person', 'id' => $user->id, 'name' => $user->verified_name, 'registry_number' => $user->registry_number, 'rating' => self::ratingOf(null, $user)] : null;
    }

    /**
     * Writes the terms that were sent: items and steps are replaced as a whole, and the total
     * and each step's amount are worked out again.
     *
     * @param  array<string, mixed>  $data
     */
    private static function fill(Contract $contract, array $data): void
    {
        $contract->fill(array_intersect_key($data, array_flip(['title', 'delivery_date', 'payment_terms', 'pledge', 'pledge_amount', 'middleman', 'discount'])));

        if (array_key_exists('items', $data)) {
            $contract->items()->delete();
            foreach (array_values($data['items']) as $i => $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price'] ?? 0);
                $contract->items()->create([
                    'position' => $i,
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $quantity,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $price,
                    'amount' => round($quantity * $price, 2),
                ]);
            }
        }
        $subtotal = (float) $contract->items()->sum('amount');
        abort_if((float) $contract->discount > $subtotal, 422, __('The discount is more than the items cost.'));
        $contract->total = round($subtotal - (float) $contract->discount, 2);
        $contract->save();

        $steps = array_key_exists('steps', $data) ? array_values($data['steps'] ?? [])
            : $contract->steps()->get(['label', 'percent'])->toArray();
        if ($steps === []) {
            $steps = [['label' => __('Full payment'), 'percent' => 100]];
        }
        abort_unless(abs(array_sum(array_map(fn ($s) => (float) $s['percent'], $steps)) - 100) < 0.001, 422, __('The payment steps must add up to 100%.'));
        $contract->steps()->delete();
        $left = (float) $contract->total;
        foreach ($steps as $i => $step) {
            // The last step takes what is left, so the steps always add up to the total.
            $amount = $i === count($steps) - 1 ? $left : round((float) $contract->total * (float) $step['percent'] / 100, 2);
            $left = round($left - $amount, 2);
            $contract->steps()->create(['position' => $i, 'label' => $step['label'], 'percent' => $step['percent'], 'amount' => $amount, 'status' => 'pending']);
        }
        $contract->unsetRelation('items')->unsetRelation('steps');
    }

    /**
     * Both sides signed the same version: keep the PDF, then ask the buyer's signer for the
     * fees, or put the contract in force when there are none.
     */
    private static function bothSigned(Contract $contract, User $by): Contract
    {
        $from = $contract->status;
        $contract->forceFill(['signed_at' => now()])->save();
        $path = "market/contracts/{$contract->id}-v{$contract->version}.pdf";
        Storage::disk('local')->put($path, self::pdf($contract));
        $contract->forceFill(['pdf_path' => $path])->save();

        $fees = self::feesDue($contract);
        if ($fees['total'] <= 0) {
            $contract->forceFill(['status' => 'active'])->save();
            self::log($contract, $by, 'signed', $from, 'active');
            self::announceInForce($contract);

            return $contract;
        }

        $contract->forceFill(['status' => 'awaiting_payment'])->save();
        self::log($contract, $by, 'signed', $from, 'awaiting_payment');
        $payer = $contract->signatures()->where('side', 'buyer')->first()->user;
        $lines = array_values(array_filter([
            ['kind' => 'contract', 'amount' => $fees['contract']],
            ['kind' => 'middleman', 'amount' => $fees['middleman']],
        ], fn ($line) => $line['amount'] > 0));
        Payments::invoice($payer, $contract->buyerCompany, $fees['contract'] > 0 ? 'contract' : 'middleman', $fees['total'], $contract, null, $lines);
        MarketNotify::send($payer, __('Pay the fees for contract :number', ['number' => $contract->number]), __('Both sides signed. The contract is in force once the fees are paid.'), "/market/contracts/{$contract->id}", 'alert');

        return $contract;
    }

    /** One side moves the contract from one of these statuses to the next, and the other side hears of it. */
    private static function move(User $by, Contract $contract, string $side, array $from, string $to, string $event, string $title, ?string $note = null): Contract
    {
        self::requireSigner($by, $contract, $side);

        return DB::transaction(function () use ($by, $contract, $side, $from, $to, $event, $title, $note) {
            $contract = Contract::lockForUpdate()->findOrFail($contract->id);
            abort_unless(in_array($contract->status, $from, true), 422, __('The contract is not at that stage.'));
            $was = $contract->status;
            $contract->forceFill(['status' => $to])->save();
            self::log($contract, $by, $event, $was, $to, $note);
            self::notifySide($contract, self::other($side), $title, $note);

            return $contract;
        });
    }

    /**
     * What this user may do with an escrow step now.
     *
     * @return array{fund: bool, request: bool, release: bool, reject: bool}
     */
    private static function stepCan(Contract $contract, string $status, ?string $side, bool $acts): array
    {
        $escrow = $acts && $contract->middleman === 'escrow';
        $running = in_array($contract->status, Escrow::RUNNING, true);

        return [
            'fund' => $escrow && $running && $side === 'buyer' && $status === 'pending',
            'request' => $escrow && $side === 'vendor' && $status === 'funded',
            'release' => $escrow && $side === 'buyer' && in_array($status, ['funded', 'requested'], true),
            'reject' => $escrow && $side === 'buyer' && $status === 'requested',
        ];
    }

    /** Tells both sides the contract is in force. */
    private static function announceInForce(Contract $contract): void
    {
        foreach (['buyer', 'vendor'] as $side) {
            self::notifySide($contract, $side, __('The contract :number is in force', ['number' => $contract->number]), null, 'success');
        }
        if ($contract->source === 'tender') {
            Awards::contractInForce($contract);
        }
    }

    /** Whether this side has signed the current version. */
    private static function signedBy(Contract $contract, string $side): bool
    {
        return $contract->signatures()->where(['side' => $side, 'version' => $contract->version])->exists();
    }

    /** @return array{0: Company|null, 1: User|null} */
    private static function vendor(string $number): array
    {
        $number = mb_strtoupper(trim($number));
        if (preg_match(Companies::REGISTRY_NUMBER, $number) === 1) {
            $company = Company::where('registry_number', $number)->first();
            abort_unless($company, 422, __('This company is not on the market yet. Ask them to add it.'));
            abort_if($company->isFrozen(), 422, __('This company is frozen: ХУР says it is no longer active.'));

            return [$company, null];
        }
        $user = $number === '' ? null : User::where('registry_number', $number)->whereNotNull('verified_at')->first();
        abort_unless($user, 422, __('No one on the market has this registry number.'));

        return [null, $user];
    }

    private static function belongs(?User $user, Contract $contract, string $side, string $right): bool
    {
        if (! $user?->isVerified()) {
            return false;
        }
        $company = $side === 'buyer' ? $contract->buyerCompany : $contract->vendorCompany;
        if ($company) {
            return MarketAccess::canActFor($user, $company, $right);
        }

        return ($side === 'buyer' ? $contract->buyer_user_id : $contract->vendor_user_id) === $user->id;
    }

    private static function sideLabel(string $side): string
    {
        return $side === 'buyer' ? __('buyer') : __('vendor');
    }
}
