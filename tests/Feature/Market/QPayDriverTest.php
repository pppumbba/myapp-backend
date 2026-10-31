<?php

namespace Tests\Feature\Market;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Support\Market\Pay\QPayDriver;
use App\Support\Market\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class QPayDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['market.payments.qpay' => ['url' => 'https://qpay.test', 'client_id' => 'ID', 'client_secret' => 'SECRET', 'invoice_code' => 'SHOP_INVOICE']]);
    }

    private function payment(): Payment
    {
        return Payment::create(['user_id' => User::factory()->create()->id, 'kind' => 'contract', 'amount' => 12346, 'status' => 'pending', 'driver' => 'qpay', 'invoice_id' => 'INV-1']);
    }

    public function test_an_invoice_is_opened_with_a_token_and_the_answer_is_kept(): void
    {
        Http::fake([
            'qpay.test/v2/auth/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            'qpay.test/v2/invoice' => Http::response(['invoice_id' => 'INV-9', 'qr_text' => 'QR', 'qr_image' => 'BASE64', 'qPay_shortUrl' => 'https://s.qpay.mn/x']),
        ]);
        $payment = $this->payment();

        $invoice = (new QPayDriver)->createInvoice($payment);
        (new QPayDriver)->createInvoice($payment);

        $this->assertSame(['invoice_id' => 'INV-9', 'qr_text' => 'QR', 'qr_image' => 'data:image/png;base64,BASE64', 'pay_url' => 'https://s.qpay.mn/x'], $invoice);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://qpay.test/v2/invoice'
            && $r->hasHeader('Authorization', 'Bearer TOKEN')
            && $r['invoice_code'] === 'SHOP_INVOICE'
            && $r['amount'] === 12346.0
            && $r['sender_invoice_no'] === (string) $payment->id
            && str_ends_with($r['callback_url'], "/api/market/payments/{$payment->id}/callback"));
        // The token is reused for the second invoice.
        Http::assertSentCount(3);
    }

    public function test_paid_means_qpay_received_the_full_amount(): void
    {
        Http::fake([
            'qpay.test/v2/auth/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            'qpay.test/v2/payment/check' => Http::sequence()
                ->push(['count' => 0, 'paid_amount' => 0, 'rows' => []])
                ->push(['count' => 1, 'paid_amount' => 10000, 'rows' => [['payment_status' => 'PAID']]])
                ->push(['count' => 1, 'paid_amount' => 12346, 'rows' => [['payment_status' => 'PAID']]]),
        ]);
        $payment = $this->payment();
        $driver = new QPayDriver;

        $this->assertFalse($driver->isPaid($payment));
        $this->assertFalse($driver->isPaid($payment));
        $this->assertTrue($driver->isPaid($payment));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://qpay.test/v2/payment/check' && $r['object_type'] === 'INVOICE' && $r['object_id'] === 'INV-1');
    }

    public function test_qpay_down_when_opening_an_invoice_says_try_again_and_leaves_nothing_pending(): void
    {
        config(['market.payments.driver' => 'qpay']);
        Http::fake(['qpay.test/*' => Http::response('', 503)]);

        try {
            Payments::invoice(User::factory()->create(), null, 'contract', 5000, Contract::factory()->create());
            $this->fail('Expected the invoice to fail.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(['cancelled'], Payment::pluck('status')->all());
    }

    public function test_qpay_down_while_checking_leaves_the_payment_pending(): void
    {
        config(['market.payments.driver' => 'qpay']);
        Http::fake(['qpay.test/*' => Http::response('', 503)]);

        $this->assertSame('pending', Payments::refresh($this->payment())->status);
    }

    public function test_a_qpay_error_is_not_read_as_paid(): void
    {
        Http::fake([
            'qpay.test/v2/auth/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            'qpay.test/v2/payment/check' => Http::response(['error' => 'NO'], 500),
        ]);

        $this->assertFalse((new QPayDriver)->isPaid($this->payment()));
    }
}
