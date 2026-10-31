<?php

namespace Tests\Feature\Market;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Support\Market\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://front.test']);
        $this->payer = User::factory()->create();
    }

    private function invoice(float $amount = 10000): Payment
    {
        return Payments::invoice($this->payer, null, 'posting', $amount, Contract::factory()->create());
    }

    public function test_paying_through_the_fake_provider_marks_the_payment_paid_once(): void
    {
        $payment = $this->invoice();
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->pay_url);

        Sanctum::actingAs($this->payer);
        $this->getJson("/api/market/payments/{$payment->id}")->assertOk()->assertJsonPath('status', 'pending');

        $this->get("/market/pay/fake/{$payment->id}")->assertOk()->assertSee('10,000');
        $this->get("/market/pay/fake/{$payment->id}/pay")->assertRedirect("http://front.test/market/payments?paid={$payment->id}");

        $paid = $this->getJson("/api/market/payments/{$payment->id}")->assertOk()->assertJsonPath('status', 'paid')->json();
        $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', $paid['receipt_number']);

        // The provider calling back again changes nothing.
        $this->travel(5)->minutes();
        $this->post("/api/market/payments/{$payment->id}/callback")->assertOk();
        $this->assertSame($paid['paid_at'], $this->getJson("/api/market/payments/{$payment->id}")->json('paid_at'));
    }

    public function test_a_callback_alone_does_not_mark_an_unpaid_invoice_paid(): void
    {
        $payment = $this->invoice();

        $this->post("/api/market/payments/{$payment->id}/callback", ['status' => 'PAID'])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_nothing_to_pay_is_paid_at_once(): void
    {
        $payment = $this->invoice(0);

        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->receipt_number);
        $this->assertNull($payment->pay_url);
    }

    public function test_only_the_payer_sees_a_payment_and_its_receipt(): void
    {
        $payment = $this->invoice();
        Sanctum::actingAs($this->payer);
        $this->get("/api/market/payments/{$payment->id}/receipt")->assertStatus(422);
        $this->get("/market/pay/fake/{$payment->id}/pay");

        $this->get("/api/market/payments/{$payment->id}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/market/payments')->assertOk()->assertJsonPath('data.0.id', $payment->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/market/payments/{$payment->id}")->assertForbidden();
        $this->get("/api/market/payments/{$payment->id}/receipt")->assertForbidden();
        $this->getJson('/api/market/payments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_fake_pay_page_is_not_there_in_production(): void
    {
        $payment = $this->invoice();
        $this->app['env'] = 'production';

        $this->get("/market/pay/fake/{$payment->id}")->assertNotFound();
        $this->get("/market/pay/fake/{$payment->id}/pay")->assertNotFound();
    }
}
