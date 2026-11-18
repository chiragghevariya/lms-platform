<?php

namespace App\Enums;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case UsersImpersonate = 'users.impersonate';
    case RolesAssign = 'roles.assign';
    case PlansManage = 'plans.manage';
    case SubscriptionsManage = 'subscriptions.manage';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case CoursesManageAny = 'courses.manage-any';
    case CoursesManageOwn = 'courses.manage-own';
    case ReviewsModerate = 'reviews.moderate';
    case LiveManageAny = 'live.manage-any';
    case LiveManageOwn = 'live.manage-own';
    case InstructorsManage = 'instructors.manage';
    case AffiliatesManage = 'affiliates.manage';
    case NotificationsManage = 'notifications.manage';
    case CmsManage = 'cms.manage';
    case SettingsManage = 'settings.manage';
    case SettingsSecrets = 'settings.secrets';
    case AnalyticsView = 'analytics.view';
    case AuditView = 'audit.view';

    /**
     * Role => permissions. super_admin gets everything via Gate::before, so the only
     * things an admin can't do are assigning roles and seeing/changing gateway secrets.
     *
     * @return array<string, list<self>>
     */
    public static function matrix(): array
    {
        return [
            Role::Admin->value => array_values(array_filter(
                self::cases(),
                fn (self $p) => ! in_array($p, [self::RolesAssign, self::SettingsSecrets, self::CoursesManageOwn, self::LiveManageOwn], true),
            )),
            Role::Instructor->value => [self::CoursesManageOwn, self::LiveManageOwn],
            Role::Student->value => [],
        ];
    }
}
