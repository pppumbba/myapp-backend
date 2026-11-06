<?php

namespace Tests\Feature\Market;

use App\Models\Payment;
use App\Support\Market\Pay\PaymentDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Payments for tests: no real money; a test marks an invoice paid with pay(). */
class TestPaymentDriver implements PaymentDriver
{
    public function name(): string
    {
        return 'test';
    }

    public function createInvoice(Payment $payment): array
    {
        $url = "https://pay.test/{$payment->id}";

        return ['invoice_id' => 'test-'.Str::random(20), 'qr_text' => $url, 'pay_url' => $url];
    }

    public function isPaid(Payment $payment): bool
    {
        return Cache::get("market-test-pay:{$payment->invoice_id}") === true;
    }

    /** The invoice counts as paid from now on. */
    public static function pay(Payment $payment): void
    {
        Cache::forever("market-test-pay:{$payment->invoice_id}", true);
    }
}
