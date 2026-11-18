<?php

namespace App\Notifications\Platform;

use App\Notify\PlatformNotification;
use App\Support\Money;

/** Affiliate commission earned (after a referred payment) or paid out (payout marked paid). */
class CommissionNotification extends PlatformNotification
{
    public function __construct(public string $kind, public int $amount, public string $currency, public ?string $reference = null) {}

    public function event(): string
    {
        return $this->kind === 'paid' ? 'commission_paid' : 'commission_earned';
    }

    /** @return array<string, mixed> */
    public function vars(object $notifiable): array
    {
        return [
            'amount' => Money::of($this->amount, $this->currency)->formatted(),
            'reference' => $this->reference,
            'url' => config('lms.frontend_url').'/app/affiliate',
        ];
    }
}
