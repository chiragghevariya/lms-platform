<?php

namespace App\Listeners;

use App\Billing\InvoiceService;
use App\Events\PaymentSucceeded;
use App\Notifications\Billing\PaymentReceiptNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Renders the PDF invoice and emails the receipt after a successful payment. */
class IssueInvoice implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly InvoiceService $invoices) {}

    public function handle(PaymentSucceeded $event): void
    {
        $payment = $event->payment->fresh() ?? $event->payment;

        if ($payment->pdf_path === null && $payment->invoice_number !== null) {
            $this->invoices->generatePdf($payment);
        }

        $payment->user->notify(new PaymentReceiptNotification($payment));
    }
}
