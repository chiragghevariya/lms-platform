<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** A payment was (partly) refunded. $amount is the newly refunded part, in the payment's minor units. */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public Payment $payment, public int $amount, public bool $full) {}
}
