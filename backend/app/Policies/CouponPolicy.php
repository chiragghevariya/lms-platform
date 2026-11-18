<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class CouponPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->can(Permission::PlansManage->value);
    }
}
