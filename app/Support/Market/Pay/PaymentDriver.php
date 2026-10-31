<?php

namespace App\Support\Market\Pay;

use App\Models\Payment;

/** A payment provider: makes an invoice the payer pays (QR or link), and says whether it was paid. */
interface PaymentDriver
{
    /** The driver's name, kept on each payment. */
    public function name(): string;

    /**
     * Opens an invoice for the payment.
     *
     * @return array{invoice_id: string, qr_text?: string|null, qr_image?: string|null, pay_url?: string|null}
     */
    public function createInvoice(Payment $payment): array;

    /** Whether the provider says the invoice has been paid in full. */
    public function isPaid(Payment $payment): bool;
}
