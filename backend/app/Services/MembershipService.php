<?php

namespace App\Services;

use App\Contracts\GatedContent;
use App\Enums\Gateway;
use App\Enums\MembershipTier;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionActivated;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    /**
     * Single access decision used by the `tier` middleware, policies and resources.
     * Staff always pass; free content (null tier) is open to everyone signed in.
     */
    public function canAccess(?User $user, GatedContent|MembershipTier|int|null $resource): bool
    {
        $required = match (true) {
            $resource instanceof GatedContent => $resource->requiredTier(),
            is_int($resource) => MembershipTier::from($resource),
            default => $resource,
        };

        if ($required === null) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        return $user->currentTier()?->includes($required) ?? false;
    }

    /** Admin-granted complimentary membership (no gateway). Null end = until revoked. */
    public function grantComplimentary(User $user, Plan $plan, ?Carbon $endsAt, ?User $grantedBy, ?string $notes = null): Subscription
    {
        $sub = DB::transaction(fn () => $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'gateway' => Gateway::Manual,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => $endsAt,
            'granted_by' => $grantedBy?->id,
            'notes' => $notes,
        ]));

        $user->unsetRelation('liveSubscriptions');
        event(new SubscriptionActivated($sub));

        return $sub;
    }

    public function revokeComplimentary(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'ended_at' => now(),
        ])->save();

        return $subscription;
    }
}
