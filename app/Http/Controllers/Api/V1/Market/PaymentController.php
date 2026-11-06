<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Market\Payments;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The user's own market payments (with receipts) and the provider's callback. */
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

    private function requirePayer(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id === $request->user()->id, 403, __('This is not your payment.'));
    }
}
