import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Eye, KeyRound, ShieldAlert, ShieldCheck, ShieldOff } from 'lucide-react'
import { useId, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { toolbarSelect } from '@/components/data-table/DataTable'
import { ApiError } from '@/lib/api-client'
import { formatDate, formatMinor } from '@/lib/format'
import { homePathFor } from '@/lib/membership'
import { can } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import type { AdminUserDetail, UserStatus } from '@/types/admin'
import type { Role } from '@/types/api'
import { adminApi, useAdminPlans, useAdminUser, useInvalidatePeople, useUpdateRoles } from '../api'
import { ReasonDialog } from './ReasonDialog'
import { StatusBadge } from './StatusBadge'

const ROLES: Role[] = ['student', 'instructor', 'admin', 'super_admin']
const roleLabel = (r: string) => r.replace('_', ' ')
const errorText = (e: unknown) =>
  e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Something went wrong.'

/** Everything support needs about one user, with permission-gated actions. */
export function UserDrawer({ userId, onClose }: { userId: string | null; onClose: () => void }) {
  const { data: user, isLoading } = useAdminUser(userId)

  return (
    <Sheet open={!!userId} onOpenChange={(o) => !o && onClose()}>
      <SheetContent aria-describedby={undefined}>
        {isLoading || !user ? (
          <>
            <SheetHeader>
              <SheetTitle>Loading user…</SheetTitle>
            </SheetHeader>
            <SheetBody>
              <Skeleton className="h-24" />
              <Skeleton className="h-40" />
            </SheetBody>
          </>
        ) : (
          <UserDetail user={user} />
        )}
      </SheetContent>
    </Sheet>
  )
}

function UserDetail({ user }: { user: AdminUserDetail }) {
  const me = useAuthStore((s) => s.user)
  const setUser = useAuthStore((s) => s.setUser)
  const qc = useQueryClient()
  const navigate = useNavigate()
  const invalidate = useInvalidatePeople()
  const [statusAction, setStatusAction] = useState<UserStatus | null>(null)

  const isSelf = me?.id === user.id
  const isSuper = me?.roles.includes('super_admin') ?? false
  // Staff accounts are only managed by super admins (the API enforces the same rule).
  const manageable = !isSelf && (isSuper || !user.is_staff)
  const canManage = can(me, 'users.manage') && manageable
  const canImpersonate = can(me, 'users.impersonate') && !isSelf && !user.is_staff && user.status === 'active'

  const status = useMutation({
    mutationFn: ({ next, reason }: { next: UserStatus; reason?: string }) =>
      adminApi.setStatus(user.id, next, reason),
    onSuccess: (u) => {
      toast.success(u.status === 'active' ? 'Account reactivated' : `Account ${u.status}`)
      setStatusAction(null)
      void invalidate()
    },
  })
  const reset = useMutation({
    mutationFn: () => adminApi.passwordReset(user.id),
    onSuccess: (r) => toast.success(r.message),
    onError: (e) => toast.error(errorText(e)),
  })
  const impersonate = useMutation({
    mutationFn: () => adminApi.impersonate(user.id),
    onSuccess: (u) => {
      qc.clear()
      setUser(u)
      toast.success(`Now viewing as ${u.name}`)
      navigate(homePathFor(u))
    },
    onError: (e) => toast.error(errorText(e)),
  })

  const statusCopy: Record<UserStatus, { title: string; label: string; description: string }> = {
    suspended: {
      title: `Suspend ${user.name}?`,
      label: 'Suspend',
      description: 'They are signed out everywhere and cannot sign in until reactivated.',
    },
    banned: {
      title: `Ban ${user.name}?`,
      label: 'Ban',
      description: 'Permanent block. They are signed out everywhere and cannot sign in.',
    },
    active: {
      title: `Reactivate ${user.name}?`,
      label: 'Reactivate',
      description: 'They can sign in again.',
    },
  }

  return (
    <>
      <SheetHeader>
        <SheetTitle>{user.name}</SheetTitle>
        <SheetDescription>{user.email}</SheetDescription>
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <StatusBadge status={user.status} />
          {user.roles.map((r) => (
            <Badge key={r} variant="secondary" className="capitalize">
              {roleLabel(r)}
            </Badge>
          ))}
          {user.membership && (
            <Badge variant="outline">
              {user.membership.plan}
              {user.membership.complimentary ? ' · complimentary' : ''}
            </Badge>
          )}
        </div>
        {user.status_reason && (
          <p className="text-muted-foreground text-sm">
            Reason: {user.status_reason} ({formatDate(user.status_changed_at)})
          </p>
        )}
      </SheetHeader>
      <SheetBody>
        {(canManage || canImpersonate) && (
          <section aria-label="Actions" className="flex flex-wrap gap-2">
            {canImpersonate && (
              <Button size="sm" onClick={() => impersonate.mutate()} disabled={impersonate.isPending}>
                <Eye aria-hidden /> View as user
              </Button>
            )}
            {canManage && (
              <>
                <Button size="sm" variant="outline" onClick={() => reset.mutate()} disabled={reset.isPending}>
                  <KeyRound aria-hidden /> Send password reset
                </Button>
                {user.status === 'active' ? (
                  <>
                    <Button size="sm" variant="outline" onClick={() => setStatusAction('suspended')}>
                      <ShieldAlert aria-hidden /> Suspend
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setStatusAction('banned')}>
                      <ShieldOff aria-hidden /> Ban
                    </Button>
                  </>
                ) : (
                  <Button size="sm" variant="outline" onClick={() => setStatusAction('active')}>
                    <ShieldCheck aria-hidden /> Reactivate
                  </Button>
                )}
              </>
            )}
          </section>
        )}

        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <Fact
            label="Phone"
            value={`${user.phone ?? '—'}${user.phone && user.phone_verified ? ' ✓' : ''}`}
          />
          <Fact label="Country" value={user.country ?? '—'} />
          <Fact label="Joined" value={formatDate(user.created_at)} />
          <Fact label="Last login" value={formatDate(user.last_login_at)} />
          <Fact label="Email verified" value={user.email_verified ? 'Yes' : 'No'} />
          <Fact label="Two-factor" value={user.two_factor ? 'On' : 'Off'} />
          <Fact label="Timezone" value={user.timezone} />
        </dl>

        <section aria-labelledby="ud-stats" className="grid gap-2">
          <h3 id="ud-stats" className="font-semibold">
            Activity
          </h3>
          <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
            <Stat label="Enrolments" value={user.stats.enrollments} />
            <Stat label="Completed" value={user.stats.completed_courses} />
            <Stat label="Certificates" value={user.stats.certificates} />
            <Stat label="Watch time" value={`${(user.stats.watch_seconds / 3600).toFixed(1)} h`} />
            {Object.entries(user.stats.lifetime_value).map(([cur, v]) => (
              <Stat key={cur} label={`Lifetime value (${cur})`} value={formatMinor(v, cur)} />
            ))}
          </dl>
        </section>

        {can(me, 'users.manage') && manageable && <RolesEditor user={user} isSuper={isSuper} />}

        <Memberships user={user} />

        <section aria-labelledby="ud-pay" className="grid gap-2">
          <h3 id="ud-pay" className="font-semibold">
            Recent payments
          </h3>
          {user.payments.length === 0 ? (
            <p className="text-muted-foreground text-sm">No payments.</p>
          ) : (
            <ul className="divide-y rounded-lg border text-sm">
              {user.payments.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                  <span>
                    {p.description ?? p.invoice_number ?? 'Payment'}
                    <span className="text-muted-foreground block text-xs">
                      {formatDate(p.paid_at ?? p.created_at)} · {p.gateway}
                    </span>
                  </span>
                  <span className="flex items-center gap-2 tabular-nums">
                    {p.amount.formatted} <StatusBadge status={p.status} />
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section aria-labelledby="ud-log" className="grid gap-2">
          <h3 id="ud-log" className="font-semibold">
            Recent sign-ins & audit trail
          </h3>
          <ul className="text-muted-foreground grid gap-1 text-sm">
            {user.logins.map((l, i) => (
              <li key={`l${i}`}>
                {formatDate(l.at)} · {l.event.replace(/_/g, ' ')} {l.ip ? `from ${l.ip}` : ''}
              </li>
            ))}
            {user.activity.map((a) => (
              <li key={a.id}>
                {formatDate(a.created_at)} · {a.description}
                {a.causer ? ` (by ${a.causer})` : ''}
              </li>
            ))}
            {user.logins.length + user.activity.length === 0 && <li>Nothing recorded yet.</li>}
          </ul>
        </section>
      </SheetBody>

      {statusAction && (
        <ReasonDialog
          open
          onOpenChange={(o) => !o && setStatusAction(null)}
          title={statusCopy[statusAction].title}
          description={statusCopy[statusAction].description}
          confirmLabel={statusCopy[statusAction].label}
          destructive={statusAction !== 'active'}
          required={statusAction !== 'active'}
          pending={status.isPending}
          error={status.error ? errorText(status.error) : null}
          onConfirm={(reason) => status.mutate({ next: statusAction, reason })}
        />
      )}
    </>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="bg-muted/40 rounded-lg p-3">
      <dt className="text-muted-foreground text-xs">{label}</dt>
      <dd className="text-lg font-semibold tabular-nums">{value}</dd>
    </div>
  )
}

function RolesEditor({ user, isSuper }: { user: AdminUserDetail; isSuper: boolean }) {
  const [roles, setRoles] = useState<Role[]>(user.roles)
  const update = useUpdateRoles()
  const assignable: Role[] = isSuper ? ROLES : ['student', 'instructor']
  const changed = roles.slice().sort().join() !== user.roles.slice().sort().join()

  return (
    <section aria-labelledby="ud-roles" className="grid gap-2">
      <h3 id="ud-roles" className="font-semibold">
        Roles
      </h3>
      <div className="flex flex-wrap gap-3">
        {assignable.map((r) => (
          <label key={r} className="flex items-center gap-2 text-sm capitalize">
            <input
              type="checkbox"
              checked={roles.includes(r)}
              onChange={(e) =>
                setRoles((cur) => (e.target.checked ? [...cur, r] : cur.filter((x) => x !== r)))
              }
            />
            {roleLabel(r)}
          </label>
        ))}
      </div>
      {changed && (
        <div className="flex gap-2">
          <Button
            size="sm"
            disabled={roles.length === 0 || update.isPending}
            onClick={() =>
              update.mutate(
                { id: user.id, roles },
                {
                  onSuccess: () => toast.success('Roles updated'),
                  onError: (e) => toast.error(errorText(e)),
                },
              )
            }
          >
            Save roles
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setRoles(user.roles)}>
            Undo
          </Button>
        </div>
      )}
    </section>
  )
}

function Memberships({ user }: { user: AdminUserDetail }) {
  const me = useAuthStore((s) => s.user)
  const id = useId()
  const invalidate = useInvalidatePeople()
  const canManage = can(me, 'subscriptions.manage')
  const plans = useAdminPlans()
  const [granting, setGranting] = useState(false)
  const [form, setForm] = useState({ plan_id: '', ends_at: '', notes: '' })

  const grant = useMutation({
    mutationFn: () =>
      adminApi.grantMembership(user.id, {
        plan_id: form.plan_id,
        ends_at: form.ends_at || null,
        notes: form.notes || null,
      }),
    onSuccess: () => {
      toast.success('Complimentary membership granted')
      setGranting(false)
      setForm({ plan_id: '', ends_at: '', notes: '' })
      void invalidate()
    },
  })
  const revoke = useMutation({
    mutationFn: (subId: string) => adminApi.revokeMembership(user.id, subId),
    onSuccess: () => {
      toast.success('Membership revoked')
      void invalidate()
    },
    onError: (e) => toast.error(errorText(e)),
  })

  return (
    <section aria-labelledby="ud-subs" className="grid gap-2">
      <div className="flex items-center justify-between gap-2">
        <h3 id="ud-subs" className="font-semibold">
          Memberships
        </h3>
        {canManage && !granting && (
          <Button size="sm" variant="outline" onClick={() => setGranting(true)}>
            Grant complimentary
          </Button>
        )}
      </div>
      {user.subscriptions.length === 0 ? (
        <p className="text-muted-foreground text-sm">No memberships.</p>
      ) : (
        <ul className="divide-y rounded-lg border text-sm">
          {user.subscriptions.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
              <span>
                {s.plan.name} {s.price ? `· ${s.price.interval}` : ''} · {s.gateway}
                {s.is_complimentary ? ' · complimentary' : ''}
                <span className="text-muted-foreground block text-xs">
                  {s.is_lifetime
                    ? 'Lifetime'
                    : s.current_period_end
                      ? `${s.cancel_at_period_end ? 'Ends' : 'Renews'} ${formatDate(s.current_period_end)}`
                      : 'No end date'}
                </span>
              </span>
              <span className="flex items-center gap-2">
                <StatusBadge status={s.status} />
                {canManage && s.is_complimentary && s.is_active && (
                  <Button
                    size="sm"
                    variant="ghost"
                    disabled={revoke.isPending}
                    onClick={() => revoke.mutate(s.id)}
                  >
                    Revoke
                  </Button>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
      {granting && (
        <form
          className="grid gap-3 rounded-lg border p-3"
          onSubmit={(e) => {
            e.preventDefault()
            grant.mutate()
          }}
        >
          <div className="grid gap-1">
            <Label htmlFor={`${id}-plan`}>Plan</Label>
            <select
              id={`${id}-plan`}
              className={toolbarSelect}
              required
              value={form.plan_id}
              onChange={(e) => setForm((f) => ({ ...f, plan_id: e.target.value }))}
            >
              <option value="">Choose a plan</option>
              {plans.data?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label htmlFor={`${id}-ends`}>Ends on (empty = until revoked)</Label>
            <Input
              id={`${id}-ends`}
              type="date"
              value={form.ends_at}
              onChange={(e) => setForm((f) => ({ ...f, ends_at: e.target.value }))}
            />
          </div>
          <div className="grid gap-1">
            <Label htmlFor={`${id}-notes`}>Internal note</Label>
            <Input
              id={`${id}-notes`}
              maxLength={500}
              value={form.notes}
              onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
            />
          </div>
          {grant.error && (
            <p role="alert" className="text-destructive text-sm">
              {errorText(grant.error)}
            </p>
          )}
          <div className="flex gap-2">
            <Button size="sm" type="submit" disabled={!form.plan_id || grant.isPending}>
              Grant
            </Button>
            <Button size="sm" type="button" variant="ghost" onClick={() => setGranting(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}
    </section>
  )
}
