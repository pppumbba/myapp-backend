<?php

namespace App\Support\Market;

use App\Models\Bid;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractStep;
use App\Models\Payment;
use App\Models\Tender;
use App\Models\User;
use App\Support\Market\Pay\PaymentDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money paid to the market through the payment provider. A payment is marked paid only when the
 * provider says so (never on a callback's word), and only once: what it pays for is then applied.
 */
class Payments
{
    /**
     * Opens an invoice for the payer; nothing to pay is paid at once.
     *
     * @param  list<array{kind: string, amount: int|float}>|null  $lines  what the amount is made of, for the receipt
     */
    public static function invoice(User $payer, ?Company $company, string $kind, float $amount, Model $subject, ?ContractStep $step = null, ?array $lines = null): Payment
    {
        $driver = app(PaymentDriver::class);
        $payment = Payment::create([
            'user_id' => $payer->id,
            'company_id' => $company?->id,
            'kind' => $kind,
            'amount' => round($amount, 2),
            'lines' => $lines,
            'status' => 'pending',
            'driver' => $driver->name(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'contract_step_id' => $step?->id,
        ]);

        if ($amount <= 0) {
            return self::markPaid($payment);
        }
        try {
            $invoice = $driver->createInvoice($payment);
        } catch (RuntimeException|ConnectionException) {
            $payment->update(['status' => 'cancelled']);
            abort(503, __('The payment service is not answering. Try again in a moment.'));
        }
        $payment->update(array_intersect_key($invoice, array_flip(['invoice_id', 'qr_text', 'qr_image', 'pay_url'])));

        return $payment;
    }

    /** Asks the provider about a pending payment and marks it paid when it is. */
    public static function refresh(Payment $payment): Payment
    {
        if ($payment->status !== 'pending' || ! $payment->invoice_id) {
            return $payment;
        }
        try {
            $paid = app(PaymentDriver::class)->isPaid($payment);
        } catch (RuntimeException|ConnectionException) {
            // The provider can't be reached: still pending, asked again next time.
            return $payment;
        }

        return $paid ? self::markPaid($payment) : $payment;
    }

    /** Marks the payment paid and applies it, once even when called twice at the same time. */
    public static function markPaid(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'receipt_number' => sprintf('R-%s-%06d', now()->format('Y'), $locked->id),
            ])->save();

            match ($locked->kind) {
                'contract', 'middleman' => self::apply([Contracts::class, 'paymentPaid'], $locked),
                'escrow' => self::apply([Escrow::class, 'paymentPaid'], $locked),
                'posting' => self::apply([Tenders::class, 'paymentPaid'], $locked),
                'bidding', 'security' => self::apply([Bids::class, 'paymentPaid'], $locked),
                default => null,
            };

            return $locked;
        });
    }

    /** What the payer sees of a payment. */
    public static function show(Payment $payment): array
    {
        $subject = $payment->subject;

        return [
            'id' => $payment->id,
            'kind' => $payment->kind,
            'amount' => (float) $payment->amount,
            'status' => $payment->status,
            'qr_text' => $payment->qr_text,
            'qr_image' => $payment->qr_image,
            'pay_url' => $payment->pay_url,
            'receipt_number' => $payment->receipt_number,
            'paid_at' => $payment->paid_at,
            'created_at' => $payment->created_at,
            'subject' => match (true) {
                $subject instanceof Contract => ['type' => 'contract', 'id' => $subject->id, 'number' => $subject->number, 'title' => $subject->title],
                $subject instanceof Tender => ['type' => 'tender', 'id' => $subject->id, 'number' => $subject->code, 'title' => $subject->title],
                $subject instanceof Bid => ['type' => 'bid', 'id' => $subject->tender_id, 'number' => $subject->tender?->code, 'title' => $subject->tender?->title],
                default => null,
            },
        ];
    }

    /** What a payment of this kind is for, in the user's language. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'posting' => __('Posting fee'),
            'bidding' => __('Bidding fee'),
            'contract' => __('Contract fee'),
            'middleman' => __('Middleman fee'),
            'escrow' => __('Payment held by the market'),
            'security' => __('Bid security'),
            default => $kind,
        };
    }

    /** @param  array{0: class-string, 1: string}  $hook */
    private static function apply(array $hook, Payment $payment): void
    {
        if (is_callable($hook)) {
            $hook($payment);
        }
    }
}
