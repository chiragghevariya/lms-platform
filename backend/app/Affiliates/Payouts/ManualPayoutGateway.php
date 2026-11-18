<?php

namespace App\Affiliates\Payouts;

use App\Models\AffiliatePayout;

class ManualPayoutGateway implements PayoutGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function supports(string $method): bool
    {
        return in_array($method, ['bank', 'upi', 'paypal'], true);
    }

    public function send(AffiliatePayout $payout): ?string
    {
        return null; // paid outside the platform; an admin marks it paid with the transfer reference
    }
}
