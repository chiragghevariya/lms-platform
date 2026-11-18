import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import { UpgradePrompt } from '@/features/membership/components/TierGate'
import { canAccessTier, hasAnyRole, homePathFor } from '@/lib/membership'
import { can, type Permission } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import type { MembershipTier, Role } from '@/types/api'

function useResolvedUser() {
  const { user, status } = useAuthStore()
  return { user, pending: status === 'idle' || status === 'loading' }
}

/** Requires a signed-in user; remembers where they were going. */
export function ProtectedRoute({ children }: { children?: ReactNode }) {
  const { user, pending } = useResolvedUser()
  const location = useLocation()
  if (pending) return <FullPageSpinner />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  return children ? <>{children}</> : <Outlet />
}

/** Requires one of the given roles (super_admin always passes). */
export function RoleRoute({ roles, children }: { roles: Role[]; children?: ReactNode }) {
  const { user, pending } = useResolvedUser()
  if (pending) return <FullPageSpinner />
  if (!user) return <Navigate to="/login" replace />
  if (!hasAnyRole(user, [...roles, 'super_admin'])) return <Navigate to={homePathFor(user)} replace />
  return children ? <>{children}</> : <Outlet />
}

/** Admin pages: the API enforces permissions; this just explains instead of showing empty errors. */
export function RequirePermission({
  permission,
  children,
}: {
  permission: Permission
  children?: ReactNode
}) {
  const { user, pending } = useResolvedUser()
  if (pending) return <FullPageSpinner />
  if (!can(user, permission))
    return (
      <div role="alert" className="mx-auto max-w-md py-16 text-center">
        <h1 className="text-xl font-semibold">You don't have access to this page</h1>
        <p className="text-muted-foreground mt-2">
          Ask a super admin to grant the “{permission}” permission.
        </p>
      </div>
    )
  return children ? <>{children}</> : <Outlet />
}

/** Requires a membership tier (higher tiers inherit; staff bypass). Shows an upgrade prompt otherwise. */
export function RequireTier({ tier, children }: { tier: MembershipTier; children?: ReactNode }) {
  const { user, pending } = useResolvedUser()
  if (pending) return <FullPageSpinner />
  if (!canAccessTier(user, tier)) return <UpgradePrompt tier={tier} />
  return children ? <>{children}</> : <Outlet />
}

/** For login/register pages: bounce signed-in users to their home area. */
export function GuestOnly({ children }: { children?: ReactNode }) {
  const { user, pending } = useResolvedUser()
  if (pending) return <FullPageSpinner />
  if (user) return <Navigate to={homePathFor(user)} replace />
  return children ? <>{children}</> : <Outlet />
}

/** @deprecated use ProtectedRoute */
export const RequireAuth = ProtectedRoute
/** @deprecated use RoleRoute */
export const RequireRole = RoleRoute
