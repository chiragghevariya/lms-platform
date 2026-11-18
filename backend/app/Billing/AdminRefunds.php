<?php

namespace App\Billing;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Refunds started from the admin panel. The gateway is called first; the local row is then
 * updated through the same RefundRecorder the webhooks use (with the cumulative total), so
 * the later webhook is a no-op and commissions/earnings react exactly as for any refund.
 */
class AdminRefunds
{
    public function __construct(private readonly GatewayResolver $gateways, private readonly RefundRecorder $recorder) {}

    public function refund(Payment $payment, ?int $amount, string $reason, User $admin): Payment
    {
        $lock = Cache::lock('refund:'.$payment->id, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['amount' => 'Another refund for this payment is in progress.']);
        }

        try {
            $payment->refresh();
            $refundable = $payment->amount - $payment->refunded_amount;
            if ($payment->status !== PaymentStatus::Succeeded || $refundable <= 0) {
                throw ValidationException::withMessages(['amount' => 'Only successful payments with a remaining balance can be refunded.']);
            }
            $amount ??= $refundable;
            if ($amount < 1 || $amount > $refundable) {
                throw ValidationException::withMessages(['amount' => 'The amount must be between 1 and the refundable balance.']);
            }

            $refs = array_values(array_filter([$payment->gateway_payment_id, $payment->gateway_reference]));
            if ($refs === []) {
                throw ValidationException::withMessages(['amount' => 'This payment has no gateway reference to refund against.']);
            }

            $before = $payment->refunded_amount;
            // Manual (offline) payments: the money goes back outside the platform; we only record it.
            $refundId = $payment->gateway === Gateway::Manual
                ? 'manual'
                : $this->gateways->for($payment->gateway)->refund($payment, $amount, $reason);

            $this->recorder->refund($payment->gateway, $refs, $before + $amount);

            activity('admin')->causedBy($admin)->performedOn($payment)->event('payment.refunded')
                ->withProperties(['amount' => $amount, 'currency' => $payment->currency, 'reason' => $reason, 'gateway_refund_id' => $refundId])
                ->log('Refunded '.$payment->currency.' '.number_format($amount / 100, 2));

            return $payment->refresh();
        } finally {
            $lock->release();
        }
    }
}
