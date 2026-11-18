<?php

namespace App\Policies;

use App\Contracts\GatedContent;
use App\Models\User;
use App\Services\MembershipService;

/**
 * Shared policy logic for tier-gated content. Content policies (courses, lessons, live
 * classes) call `canAccessContent()`; it is also exposed as the `access-content` gate.
 */
class GatedContentPolicy
{
    public function __construct(private readonly MembershipService $membership) {}

    public function access(?User $user, GatedContent $content): bool
    {
        return $this->membership->canAccess($user, $content);
    }
}
