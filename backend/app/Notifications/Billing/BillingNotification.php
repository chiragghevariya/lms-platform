<?php

namespace App\Notifications\Billing;

use App\Notify\PlatformNotification;

abstract class BillingNotification extends PlatformNotification
{
    protected function billingUrl(): string
    {
        return config('lms.frontend_url').'/app/billing';
    }
}
