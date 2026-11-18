<?php

namespace App\Notifications\Billing;

use App\Models\Payment;
use Illuminate\Notifications\Messages\MailMessage;

class PaymentReceiptNotification extends BillingNotification
{
    public function __construct(public Payment $payment)
    {
        $this->onQueue('mail');
    }

    public function event(): string
    {
        return 'invoice';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return ['amount' => $this->payment->money($this->payment->amount)->formatted(), 'invoice_number' => $this->payment->invoice_number, 'url' => $this->billingUrl()];
    }

    protected function defaultMail(object $notifiable): ?MailMessage
    {
        return (new MailMessage)
            ->subject('Receipt '.$this->payment->invoice_number)
            ->line('Thanks for your payment of '.$this->payment->money($this->payment->amount)->formatted().'.')
            ->line('Invoice: '.$this->payment->invoice_number)
            ->action('View invoices', $this->billingUrl());
    }
}
