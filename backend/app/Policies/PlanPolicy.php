<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::PlansManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::PlansManage->value);
    }

    public function update(User $actor, Plan $plan): bool
    {
        return $actor->can(Permission::PlansManage->value);
    }

    public function delete(User $actor, Plan $plan): bool
    {
        return $actor->can(Permission::PlansManage->value);
    }
}
