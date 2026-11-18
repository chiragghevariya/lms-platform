import type { ColumnDef } from '@tanstack/react-table'
import { useMutation } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { ExportButton } from '@/components/data-table/ExportButton'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatDate } from '@/lib/format'
import { can } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import type { AdminUser, AdminUserFilters, UserStatus } from '@/types/admin'
import type { Role } from '@/types/api'
import { adminApi, clean, useAdminUsers, useInvalidatePeople } from '../api'
import { ReasonDialog } from '../components/ReasonDialog'
import { CountryInput } from '../components/CountryInput'
import { TIER_NAMES } from '../components/helpers'
import { StatusBadge } from '../components/StatusBadge'
import { UserDrawer } from '../components/UserDrawer'

const ROLES: Role[] = ['student', 'instructor', 'admin', 'super_admin']
const STATUSES: UserStatus[] = ['active', 'suspended', 'banned']

const columns: ColumnDef<AdminUser, unknown>[] = [
  {
    id: 'user',
    header: 'User',
    enableHiding: false,
    cell: ({ row: { original: u } }) => (
      <div className="min-w-40">
        <p className="font-medium">{u.name}</p>
        <p className="text-muted-foreground text-xs">{u.email}</p>
      </div>
    ),
  },
  {
    id: 'roles',
    header: 'Roles',
    cell: ({ row }) => (
      <div className="flex flex-wrap gap-1">
        {row.original.roles.map((r) => (
          <Badge key={r} variant="secondary" className="capitalize">
            {r.replace('_', ' ')}
          </Badge>
        ))}
      </div>
    ),
  },
  { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  {
    id: 'membership',
    header: 'Membership',
    cell: ({ row: { original: u } }) =>
      u.membership ? (
        <span>
          {TIER_NAMES[u.membership.tier] ?? u.membership.plan}
          <span className="text-muted-foreground block text-xs">
            {u.membership.complimentary ? 'complimentary' : u.membership.status.replace('_', ' ')}
          </span>
        </span>
      ) : (
        <span className="text-muted-foreground">Free</span>
      ),
  },
  { id: 'country', header: 'Country', cell: ({ row }) => row.original.country ?? '—' },
  { id: 'joined', header: 'Joined', cell: ({ row }) => formatDate(row.original.created_at) },
  { id: 'last_login', header: 'Last login', cell: ({ row }) => formatDate(row.original.last_login_at) },
]

const FILTER_KEYS = ['search', 'role', 'status', 'tier', 'country', 'verified', 'sort'] as const

export default function AdminUsersPage() {
  const me = useAuthStore((s) => s.user)
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [suspendIds, setSuspendIds] = useState<string[] | null>(null)
  const invalidate = useInvalidatePeople()

  const filters: AdminUserFilters = useMemo(
    () => ({
      ...(Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])) as AdminUserFilters),
      page: Number(params.get('page') ?? 1),
      per_page: 25,
    }),
    [params],
  )
  const { data, isLoading } = useAdminUsers(filters)
  const openId = params.get('open')

  const set = (key: string, value: string) =>
    setParams((p) => {
      if (value) p.set(key, value)
      else p.delete(key)
      if (key !== 'page' && key !== 'open') p.delete('page')
      return p
    })

  // Debounced search into the URL so filtered views are shareable.
  useEffect(() => {
    if (search === (params.get('search') ?? '')) return
    const t = setTimeout(() => set('search', search), 300)
    return () => clearTimeout(t)
  }, [search]) // eslint-disable-line react-hooks/exhaustive-deps

  const bulk = useMutation({
    mutationFn: ({
      action,
      ids,
      reason,
    }: {
      action: 'suspend' | 'activate'
      ids: string[]
      reason?: string
    }) => adminApi.bulk(action, ids, reason),
    onSuccess: (r) => {
      toast.success(`${r.updated} updated${r.skipped ? `, ${r.skipped} skipped (staff or yourself)` : ''}`)
      setSuspendIds(null)
      void invalidate()
    },
    onError: () => toast.error('Bulk action failed.'),
  })

  const exportParams = clean({ ...filters, page: undefined, per_page: undefined })
  const canManage = can(me, 'users.manage')

  return (
    <>
      <Seo title="Users" noindex />
      <PageHeader
        title="Users"
        description={data ? `${data.meta.total.toLocaleString()} users` : 'Everyone on the platform.'}
      />
      <DataTable
        label="Users"
        storageKey="admin-users"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(u) => u.id}
        onPageChange={(p) => set('page', String(p))}
        onRowClick={(u) => set('open', u.id)}
        toolbar={
          <>
            <div className="relative w-full sm:w-64">
              <Search
                className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden
              />
              <Input
                type="search"
                aria-label="Search users"
                placeholder="Name, email, phone or id"
                className="pl-9"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <select
              aria-label="Role"
              className={toolbarSelect}
              value={filters.role}
              onChange={(e) => set('role', e.target.value)}
            >
              <option value="">All roles</option>
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {r.replace('_', ' ')}
                </option>
              ))}
            </select>
            <select
              aria-label="Status"
              className={toolbarSelect}
              value={filters.status}
              onChange={(e) => set('status', e.target.value)}
            >
              <option value="">Any status</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
            <select
              aria-label="Membership tier"
              className={toolbarSelect}
              value={filters.tier}
              onChange={(e) => set('tier', e.target.value)}
            >
              <option value="">Any membership</option>
              <option value="none">Free (no membership)</option>
              <option value="1">Starter</option>
              <option value="2">Pro</option>
              <option value="3">Elite</option>
            </select>
            <select
              aria-label="Email verified"
              className={toolbarSelect}
              value={filters.verified}
              onChange={(e) => set('verified', e.target.value)}
            >
              <option value="">Verified or not</option>
              <option value="1">Verified email</option>
              <option value="0">Unverified email</option>
            </select>
            <CountryInput value={filters.country} onChange={(v) => set('country', v)} />
            <select
              aria-label="Sort"
              className={toolbarSelect}
              value={filters.sort}
              onChange={(e) => set('sort', e.target.value)}
            >
              <option value="">Newest first</option>
              <option value="created_at">Oldest first</option>
              <option value="name">Name A–Z</option>
              <option value="-last_login_at">Recently active</option>
            </select>
          </>
        }
        actions={<ExportButton path="/admin/users/export" params={exportParams} />}
        bulkActions={(ids, clear) => (
          <>
            <ExportButton path="/admin/users/export" params={{ ids }} label="Export selected" />
            {canManage && (
              <>
                <Button size="sm" variant="outline" onClick={() => setSuspendIds(ids)}>
                  Suspend
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={bulk.isPending}
                  onClick={() => bulk.mutate({ action: 'activate', ids }, { onSuccess: clear })}
                >
                  Reactivate
                </Button>
              </>
            )}
          </>
        )}
      />
      <UserDrawer userId={openId} onClose={() => set('open', '')} />
      {suspendIds && (
        <ReasonDialog
          open
          onOpenChange={(o) => !o && setSuspendIds(null)}
          title={`Suspend ${suspendIds.length} ${suspendIds.length === 1 ? 'user' : 'users'}?`}
          description="They are signed out everywhere. Staff accounts and your own are skipped."
          confirmLabel="Suspend"
          destructive
          pending={bulk.isPending}
          onConfirm={(reason) => bulk.mutate({ action: 'suspend', ids: suspendIds, reason })}
        />
      )}
    </>
  )
}
