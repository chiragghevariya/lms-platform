<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete'; // checkout started, not yet paid
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Statuses that can grant access (past_due only within the grace period — see MembershipService). */
    public function isLive(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }
}
