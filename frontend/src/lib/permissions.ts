import type { User } from '@/types/api'

/** Mirrors App\Enums\Permission on the backend. */
export type Permission =
  | 'users.view'
  | 'users.manage'
  | 'users.impersonate'
  | 'roles.assign'
  | 'plans.manage'
  | 'subscriptions.manage'
  | 'payments.view'
  | 'payments.refund'
  | 'courses.manage-any'
  | 'reviews.moderate'
  | 'live.manage-any'
  | 'instructors.manage'
  | 'affiliates.manage'
  | 'notifications.manage'
  | 'cms.manage'
  | 'settings.manage'
  | 'settings.secrets'
  | 'analytics.view'
  | 'audit.view'

/** UI-only convenience: the API enforces every permission itself. Super admins pass everything. */
export function can(user: User | null | undefined, permission: Permission): boolean {
  if (!user) return false
  if (user.roles.includes('super_admin')) return true
  return user.permissions?.includes(permission) ?? false
}
