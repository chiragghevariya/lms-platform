<?php

namespace App\Billing;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Events\PaymentDisputed;
use App\Events\PaymentRefunded;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Refunds, chargebacks and payment fingerprints reported by gateway webhooks. Idempotent:
 * refunds are recorded as the gateway's cumulative refunded total, so replays don't double count.
 */
class RefundRecorder
{
    /** @param list<string> $refs */
    public function find(Gateway $gateway, array $refs): ?Payment
    {
        $refs = array_values(array_filter($refs));
        if ($refs === []) {
            return null;
        }

        return Payment::where('gateway', $gateway)
            ->where(fn ($q) => $q->whereIn('gateway_payment_id', $refs)->orWhereIn('gateway_reference', $refs))
            ->first();
    }

    /**
     * @param  list<string>  $refs
     * @param  int|null  $cumulative  total refunded so far according to the gateway
     * @param  int  $increment  used when the gateway only reports this refund's amount
     */
    public function refund(Gateway $gateway, array $refs, ?int $cumulative, int $increment = 0): ?Payment
    {
        $result = DB::transaction(function () use ($gateway, $refs, $cumulative, $increment) {
            $payment = $this->find($gateway, $refs);
            if (! $payment || ! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true)) {
                return null;
            }
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $total = min($payment->amount, $cumulative ?? $payment->refunded_amount + $increment);
            $delta = $total - $payment->refunded_amount;
            if ($delta <= 0) {
                return null; // replay or out-of-order event
            }
            $full = $total >= $payment->amount;
            $payment->forceFill([
                'refunded_amount' => $total,
                'refunded_at' => now(),
                'status' => $full ? PaymentStatus::Refunded : $payment->status,
            ])->save();

            return [$payment, $delta, $full];
        });

        if ($result) {
            [$payment, $delta, $full] = $result;
            event(new PaymentRefunded($payment, $delta, $full));

            return $payment;
        }

        return null;
    }

    /** @param list<string> $refs */
    public function dispute(Gateway $gateway, array $refs): ?Payment
    {
        $payment = $this->find($gateway, $refs);
        if (! $payment || $payment->disputed_at) {
            return null;
        }
        $payment->forceFill(['disputed_at' => now()])->save();
        event(new PaymentDisputed($payment));

        return $payment;
    }

    /** @param list<string> $refs */
    public function fingerprint(Gateway $gateway, array $refs, ?string $fingerprint): void
    {
        if ($fingerprint && ($payment = $this->find($gateway, $refs)) && ! $payment->payment_fingerprint) {
            $payment->forceFill(['payment_fingerprint' => strtolower($gateway->value).':'.$fingerprint])->save();
        }
    }
}
