<?php

namespace App\Support\Market\Pay;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Test payments: the invoice is a page on this backend with a Pay button. Never in production. */
class FakePaymentDriver implements PaymentDriver
{
    public function name(): string
    {
        return 'fake';
    }

    public function createInvoice(Payment $payment): array
    {
        $url = url("/market/pay/fake/{$payment->id}");

        return ['invoice_id' => 'fake-'.Str::random(20), 'qr_text' => $url, 'pay_url' => $url];
    }

    public function isPaid(Payment $payment): bool
    {
        return Cache::get("market-fake-pay:{$payment->invoice_id}") === true;
    }

    /** The test pay page's button: the invoice counts as paid from now on. */
    public static function pay(Payment $payment): void
    {
        Cache::forever("market-fake-pay:{$payment->invoice_id}", true);
    }
}
