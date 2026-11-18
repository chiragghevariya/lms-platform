<?php

namespace App\Affiliates;

use App\Models\Affiliate;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;

/**
 * Signals that a referral isn't genuine. "block" signals reject the referral outright (no commission ever);
 * "review" signals let it through but hold every commission for an admin.
 */
class FraudGuard
{
    public const BLOCK = ['self_referral', 'same_device'];

    /** @return list<string> */
    public function atSignup(Affiliate $affiliate, User $newUser, ?string $ipHash, ?string $deviceHash): array
    {
        $flags = [];
        $owner = $affiliate->user;
        $days = (int) config('affiliates.fraud.affiliate_ip_lookback_days');

        if ($owner->id === $newUser->id || Identity::canonicalEmail($owner->email) === Identity::canonicalEmail($newUser->email)) {
            $flags[] = 'self_referral';
        }
        if ($deviceHash && in_array($deviceHash, Identity::userDeviceHashes($owner, $days), true)) {
            $flags[] = 'same_device';
        }
        if ($ipHash && in_array($ipHash, Identity::userIpHashes($owner, $days), true)) {
            $flags[] = 'same_ip_as_affiliate';
        }
        $recent = Referral::where('affiliate_id', $affiliate->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= (int) config('affiliates.fraud.max_signups_per_hour')) {
            $flags[] = 'velocity_hourly';
        }
        if ($ipHash && Referral::where('affiliate_id', $affiliate->id)->where('signup_ip_hash', $ipHash)
            ->where('created_at', '>=', now()->subDay())->count() >= (int) config('affiliates.fraud.max_signups_per_ip_per_day')) {
            $flags[] = 'velocity_ip';
        }

        return $flags;
    }

    /** The referred customer paid with the same card / UPI handle the affiliate uses. */
    public function sharedPaymentMethod(Affiliate $affiliate, Payment $payment): bool
    {
        return $payment->payment_fingerprint !== null && Payment::where('user_id', $affiliate->user_id)
            ->where('payment_fingerprint', $payment->payment_fingerprint)->exists();
    }

    /** @param list<string> $flags */
    public static function blocks(array $flags): bool
    {
        return array_intersect($flags, self::BLOCK) !== [];
    }
}
