<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** A chargeback / dispute was opened on a payment. */
class PaymentDisputed
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
