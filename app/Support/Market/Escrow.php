<?php

namespace App\Support\Market;

use App\Models\Contract;
use App\Models\ContractDispute;
use App\Models\ContractStep;
use App\Models\EscrowEntry;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Money the market holds for a contract's payment steps (test mode until a licensed partner is
 * signed). Held money moves only when the buyer releases it, when a requested step waits too
 * long, or when staff decide a dispute. Every move is a line in the escrow ledger.
 */
class Escrow
{
    /** Statuses in which the vendor is working and money can be held. */
    public const RUNNING = ['active', 'in_progress', 'delivered'];

    /** The buyer pays a step into escrow: an invoice for its amount. */
    public static function fund(User $by, ContractStep $step): Payment
    {
        $contract = $step->contract;
        Contracts::requireSigner($by, $contract, 'buyer');
        abort_unless($contract->middleman === 'escrow', 422, __('The market holds money only when the contract uses escrow.'));
        abort_unless(in_array($contract->status, self::RUNNING, true), 422, __('The contract is not at that stage.'));
        abort_unless($step->status === 'pending', 422, __('This payment step is already paid in.'));

        $open = Payment::where(['contract_step_id' => $step->id, 'kind' => 'escrow', 'status' => 'pending', 'user_id' => $by->id])->first();

        return $open ?? Payments::invoice($by, $contract->buyerCompany, 'escrow', (float) $step->amount, $contract, $step);
    }

    /** A paid escrow invoice: the market now holds the step's money. */
    public static function paymentPaid(Payment $payment): void
    {
        $step = $payment->step;
        if (! $step || $step->status !== 'pending') {
            return;
        }
        $step->update(['status' => 'funded']);
        self::entry($step, 'held', $payment->user, $payment);
        Contracts::log($step->contract, $payment->user, 'step_funded', null, null, $step->label);
        Contracts::notifySide($step->contract, 'vendor', __('The market now holds :amount₮ for ":step"', ['amount' => number_format((float) $step->amount), 'step' => $step->label]));
    }

    /** The vendor asks for a held step to be paid out; it pays out by itself if the buyer doesn't answer. */
    public static function request(User $by, ContractStep $step): ContractStep
    {
        Contracts::requireSigner($by, $step->contract, 'vendor');

        return self::move($step, ['funded'], function (ContractStep $step) use ($by) {
            $days = (int) config('market.escrow.auto_release_days', 7);
            $step->update(['status' => 'requested', 'requested_at' => now(), 'auto_release_at' => now()->addDays($days), 'note' => null]);
            Contracts::log($step->contract, $by, 'step_requested', null, null, $step->label);
            Contracts::notifySide($step->contract, 'buyer', __('The vendor asks to be paid for ":step"', ['step' => $step->label]), __('Release it, or say why not. Without an answer it is paid out in :days days.', ['days' => $days]), 'alert');
        });
    }

    /** The buyer pays a held step out to the vendor. */
    public static function release(User $by, ContractStep $step): ContractStep
    {
        Contracts::requireSigner($by, $step->contract, 'buyer');

        return self::move($step, ['funded', 'requested'], fn (ContractStep $step) => self::payOut($step, $by));
    }

    /** The buyer refuses a request, saying why; the money stays held. */
    public static function rejectRelease(User $by, ContractStep $step, string $reason): ContractStep
    {
        Contracts::requireSigner($by, $step->contract, 'buyer');

        return self::move($step, ['requested'], function (ContractStep $step) use ($by, $reason) {
            $step->update(['status' => 'funded', 'auto_release_at' => null, 'note' => $reason]);
            Contracts::log($step->contract, $by, 'step_refused', null, null, $reason);
            Contracts::notifySide($step->contract, 'vendor', __('The buyer did not release ":step"', ['step' => $step->label]), $reason);
        });
    }

    /** Pays out every held step the buyer's confirmation covers (on completion). */
    public static function releaseHeld(Contract $contract, User $by): void
    {
        foreach ($contract->steps()->whereIn('status', ['funded', 'requested'])->get() as $step) {
            self::payOut($step, $by);
        }
    }

    /** Requested steps whose wait is over are paid out; frozen ones never. Returns how many. */
    public static function autoRelease(): int
    {
        $due = ContractStep::where('status', 'requested')->where('auto_release_at', '<=', now())->pluck('id');
        $released = 0;
        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$released) {
                $step = ContractStep::lockForUpdate()->find($id);
                if ($step?->status === 'requested' && $step->auto_release_at <= now()) {
                    self::payOut($step, null, __('Released after :days days without an answer.', ['days' => (int) config('market.escrow.auto_release_days', 7)]));
                    $released++;
                }
            });
        }

        return $released;
    }

    /** Either side raises a problem; held money is frozen until staff decide. */
    public static function openDispute(User $by, Contract $contract, string $reason): ContractDispute
    {
        $side = Contracts::requireSigner($by, $contract);
        abort_if($contract->middleman === 'off', 422, __('Disputes are decided by the market only when the middleman service is on.'));
        abort_unless(in_array($contract->status, self::RUNNING, true), 422, __('The contract is not at that stage.'));

        return DB::transaction(function () use ($by, $contract, $reason, $side) {
            Contract::lockForUpdate()->find($contract->id);
            abort_if($contract->disputes()->where('status', 'open')->exists(), 422, __('A dispute on this contract is already open.'));
            $dispute = $contract->disputes()->create(['opened_by' => $by->id, 'side' => $side, 'reason' => $reason, 'status' => 'open']);
            foreach ($contract->steps()->whereIn('status', ['funded', 'requested'])->get() as $step) {
                $step->update(['status' => 'frozen', 'auto_release_at' => null]);
                self::entry($step, 'frozen', $by);
            }
            Contracts::log($contract, $by, 'dispute_opened', null, null, $reason);
            Contracts::notifySide($contract, Contracts::other($side), __('A dispute was opened on :number', ['number' => $contract->number]), $reason, 'danger');
            foreach (User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->get() as $admin) {
                MarketNotify::send($admin, __('A dispute to decide on :number', ['number' => $contract->number]), $reason, '/admin/market-disputes', 'alert');
            }

            return $dispute;
        });
    }

    /**
     * Staff decide: frozen money is paid out to the vendor (release), back to the buyer
     * (refund), or held again while the contract goes on (continue).
     */
    public static function decide(User $admin, ContractDispute $dispute, string $resolution, string $decision): ContractDispute
    {
        abort_unless($dispute->status === 'open', 422, __('This dispute has already been decided.'));

        return DB::transaction(function () use ($admin, $dispute, $resolution, $decision) {
            $contract = $dispute->contract;
            foreach ($contract->steps()->where('status', 'frozen')->get() as $step) {
                match ($resolution) {
                    'release' => self::payOut($step, $admin, $decision),
                    'refund' => self::refund($step, $admin, $decision),
                    default => self::unfreeze($step, $admin),
                };
            }
            $dispute->update(['status' => 'decided', 'resolution' => $resolution, 'decision' => $decision, 'decided_by' => $admin->id, 'decided_at' => now()]);
            Contracts::log($contract, $admin, 'dispute_decided', null, null, $decision);
            foreach (['buyer', 'vendor'] as $side) {
                Contracts::notifySide($contract, $side, __('The dispute on :number was decided', ['number' => $contract->number]), $decision);
            }

            return $dispute;
        });
    }

    /** Locks the step, checks its status, then changes it. */
    private static function move(ContractStep $step, array $from, callable $change): ContractStep
    {
        return DB::transaction(function () use ($step, $from, $change) {
            $locked = ContractStep::lockForUpdate()->findOrFail($step->id);
            abort_unless(in_array($locked->status, $from, true), 422, __('This payment step is not at that stage.'));
            $change($locked);

            return $locked;
        });
    }

    private static function payOut(ContractStep $step, ?User $by, ?string $note = null): void
    {
        $step->update(['status' => 'released', 'released_at' => now(), 'auto_release_at' => null]);
        self::entry($step, 'released', $by, null, $note);
        Contracts::log($step->contract, $by, 'step_released', null, null, $step->label);
        Contracts::notifySide($step->contract, 'vendor', __('":step" was paid out to you', ['step' => $step->label]), $note, 'success');
    }

    private static function refund(ContractStep $step, User $by, string $note): void
    {
        $step->update(['status' => 'refunded', 'auto_release_at' => null]);
        self::entry($step, 'refunded', $by, null, $note);
        Payment::where(['contract_step_id' => $step->id, 'kind' => 'escrow', 'status' => 'paid'])->update(['status' => 'refunded']);
    }

    private static function unfreeze(ContractStep $step, User $by): void
    {
        $step->update(['status' => 'funded']);
        self::entry($step, 'unfrozen', $by);
    }

    private static function entry(ContractStep $step, string $type, ?User $by, ?Payment $payment = null, ?string $note = null): void
    {
        EscrowEntry::create([
            'contract_id' => $step->contract_id,
            'contract_step_id' => $step->id,
            'payment_id' => $payment?->id,
            'type' => $type,
            'amount' => $step->amount,
            'user_id' => $by?->id,
            'note' => $note,
        ]);
    }
}
