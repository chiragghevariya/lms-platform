<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function create(User $actor): bool
    {
        return $actor->can(Permission::SubscriptionsManage->value);
    }

    /** Owners manage their own; admins can manage any. */
    public function manage(User $actor, Subscription $subscription): bool
    {
        return $actor->id === $subscription->user_id || $actor->can(Permission::SubscriptionsManage->value);
    }

    public function cancel(User $actor, Subscription $subscription): bool
    {
        return $this->manage($actor, $subscription);
    }
}
