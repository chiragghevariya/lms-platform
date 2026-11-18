<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->can(Permission::UsersView->value);
    }

    /**
     * Admins may assign instructor/student only. Granting or revoking staff roles
     * (admin/super_admin) is reserved for super admins (handled by Gate::before).
     * Nobody changes their own roles through this endpoint.
     *
     * @param  list<string>  $roles
     */
    public function updateRoles(User $actor, User $target, array $roles = []): bool
    {
        if ($actor->is($target) || ! $actor->can(Permission::UsersManage->value)) {
            return false;
        }

        $staff = [Role::SuperAdmin->value, Role::Admin->value];

        return ! $target->hasAnyRole($staff) && array_intersect($roles, $staff) === [];
    }

    /** Suspend / reactivate / reset password: never yourself, never staff (unless super admin). */
    public function manage(User $actor, User $target): bool
    {
        return ! $actor->is($target)
            && $actor->can(Permission::UsersManage->value)
            && ! $target->hasAnyRole([Role::SuperAdmin->value, Role::Admin->value]);
    }
}
