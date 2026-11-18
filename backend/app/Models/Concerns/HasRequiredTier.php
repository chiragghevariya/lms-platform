<?php

namespace App\Models\Concerns;

use App\Enums\MembershipTier;

/**
 * For models with a nullable `required_tier` column (1-3). Implements GatedContent::requiredTier().
 *
 * @property int|null $required_tier
 */
trait HasRequiredTier
{
    public function requiredTier(): ?MembershipTier
    {
        return $this->required_tier ? MembershipTier::from((int) $this->required_tier) : null;
    }
}
