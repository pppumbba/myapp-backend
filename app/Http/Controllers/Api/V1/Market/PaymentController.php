<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Market\Pay\FakePaymentDriver;
use App\Support\Market\Payments;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The user's own market payments (with receipts), the provider's callback, and the fake
 * provider's pay page used until QPay is connected.
 */
class PaymentController extends Controller
{
    /** GET /api/market/payments — the signed-in user's payments, newest first. */
    public function index(Request $request): JsonResponse
    {
        $page = Payment::with('subject')->where('user_id', $request->user()->id)->latest()->latest('id')->paginate(25);

        return response()->json($page->through(fn (Payment $p) => Payments::show($p)));
    }

    /** GET /api/market/payments/{payment} — asks the provider again, then shows it. */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->requirePayer($request, $payment);

        return response()->json(Payments::show(Payments::refresh($payment)));
    }

    /** GET /api/market/payments/{payment}/receipt — the receipt of a paid payment, as a PDF. */
    public function receipt(Request $request, Payment $payment): Response
    {
        $this->requirePayer($request, $payment);
        abort_unless($payment->status === 'paid', 422, __('There is a receipt once the payment is paid.'));

        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('market.receipt', ['payment' => $payment->load('user', 'company', 'subject'), 'show' => Payments::show($payment)])
            ->download("{$payment->receipt_number}.pdf");
    }

    /**
     * /market/payments/{payment}/callback — the provider says something changed. Its word is not
     * taken: the provider is asked again.
     */
    public function callback(Payment $payment): Response
    {
        Payments::refresh($payment);

        return response('ok');
    }

    /** GET /market/pay/fake/{payment} — the test pay page. */
    public function fake(Payment $payment): View
    {
        $this->requireFake($payment);

        return view('market.pay-fake', ['payment' => $payment]);
    }

    /** GET /market/pay/fake/{payment}/pay — the test page's Pay button. */
    public function fakePay(Payment $payment): RedirectResponse
    {
        $this->requireFake($payment);
        FakePaymentDriver::pay($payment);
        Payments::refresh($payment);

        return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/market/payments?paid={$payment->id}");
    }

    private function requirePayer(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id === $request->user()->id, 403, __('This is not your payment.'));
    }

    private function requireFake(Payment $payment): void
    {
        abort_if(app()->isProduction() || $payment->driver !== 'fake', 404);
    }
}
