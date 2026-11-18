<?php

namespace App\Contracts;

use App\Enums\MembershipTier;

/** Anything that can be locked behind a membership tier (courses, lessons, live classes…). */
interface GatedContent
{
    /** Minimum tier required, or null for free content. */
    public function requiredTier(): ?MembershipTier;
}
