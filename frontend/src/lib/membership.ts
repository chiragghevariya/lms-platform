import type { MembershipTier, Role, User } from '@/types/api'

export const TIER_RANK: Record<MembershipTier, number> = { starter: 1, pro: 2, elite: 3 }
export const TIERS: MembershipTier[] = ['starter', 'pro', 'elite']

/** Higher tiers inherit lower-tier access; staff bypass gating. Mirrors App\Models\User::canAccessTier. */
export function canAccessTier(user: User | null | undefined, required: MembershipTier | null | undefined) {
  if (!required) return true
  if (!user) return false
  if (user.is_staff) return true
  return user.membership.tier_rank >= TIER_RANK[required]
}

export function hasAnyRole(user: User | null | undefined, roles: Role[]) {
  return !!user && user.roles.some((r) => roles.includes(r))
}

/** Where a user lands after login. */
export function homePathFor(user: User): string {
  if (hasAnyRole(user, ['super_admin', 'admin'])) return '/admin'
  if (hasAnyRole(user, ['instructor'])) return '/instructor'
  return '/app'
}

export const tierLabel = (t: MembershipTier) => t.charAt(0).toUpperCase() + t.slice(1)
