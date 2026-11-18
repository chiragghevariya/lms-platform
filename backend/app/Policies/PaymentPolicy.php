<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $actor, Payment $payment): bool
    {
        return $actor->id === $payment->user_id || $actor->can(Permission::SubscriptionsManage->value);
    }
}
