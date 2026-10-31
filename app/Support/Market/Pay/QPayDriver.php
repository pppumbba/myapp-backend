<?php

namespace App\Support\Market\Pay;

use App\Models\Payment;
use App\Support\Market\Payments;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * QPay merchant API v2: a bearer token from the client id and secret (kept until it expires),
 * invoices the payer pays with a bank app's QR, and a payment check by invoice.
 */
class QPayDriver implements PaymentDriver
{
    public function name(): string
    {
        return 'qpay';
    }

    public function createInvoice(Payment $payment): array
    {
        $response = $this->client()->post('/v2/invoice', [
            'invoice_code' => $this->config('invoice_code'),
            'sender_invoice_no' => (string) $payment->id,
            'invoice_receiver_code' => (string) $payment->user_id,
            'invoice_description' => Payments::kindLabel($payment->kind).' #'.$payment->id,
            'amount' => (float) $payment->amount,
            'callback_url' => url("/api/market/payments/{$payment->id}/callback"),
        ]);
        if (! $response->successful() || ! $response->json('invoice_id')) {
            throw new RuntimeException('QPay did not open the invoice.');
        }
        $image = $response->json('qr_image');

        return [
            'invoice_id' => (string) $response->json('invoice_id'),
            'qr_text' => $response->json('qr_text'),
            'qr_image' => $image ? 'data:image/png;base64,'.$image : null,
            'pay_url' => $response->json('qPay_shortUrl'),
        ];
    }

    public function isPaid(Payment $payment): bool
    {
        $response = $this->client()->post('/v2/payment/check', [
            'object_type' => 'INVOICE',
            'object_id' => $payment->invoice_id,
            'offset' => ['page_number' => 1, 'page_limit' => 100],
        ]);

        return $response->successful() && (float) $response->json('paid_amount', 0) >= (float) $payment->amount;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config('url'), '/'))->acceptJson()->timeout(15)->withToken($this->token());
    }

    /** The bearer token, asked for again shortly before it expires. */
    private function token(): string
    {
        $cached = Cache::get('market-qpay-token');
        if ($cached) {
            return $cached;
        }
        $response = Http::baseUrl(rtrim((string) $this->config('url'), '/'))->acceptJson()->timeout(15)
            ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
            ->post('/v2/auth/token');
        $token = $response->json('access_token');
        if (! $response->successful() || ! $token) {
            throw new RuntimeException('QPay refused the merchant credentials.');
        }
        Cache::put('market-qpay-token', $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }

    private function config(string $key): mixed
    {
        return config("market.payments.qpay.{$key}");
    }
}
