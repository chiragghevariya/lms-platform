import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { useState } from 'react'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Input } from '@/components/ui/input'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AdminSubscription, SubscriptionFilters } from '@/types/admin'
import type { SubscriptionStatus } from '@/types/api'
import { adminApi } from '../api'
import { StatusBadge } from '../components/StatusBadge'
import { SubscriptionDrawer } from '../components/SubscriptionDrawer'

const STATUSES: SubscriptionStatus[] = [
  'active',
  'trialing',
  'past_due',
  'cancelled',
  'expired',
  'incomplete',
]

const columns: ColumnDef<AdminSubscription, unknown>[] = [
  {
    id: 'member',
    header: 'Member',
    enableHiding: false,
    cell: ({ row: { original: s } }) => (
      <div className="min-w-40">
        <p className="font-medium">{s.user?.name ?? '—'}</p>
        <p className="text-muted-foreground text-xs">{s.user?.email}</p>
      </div>
    ),
  },
  {
    id: 'plan',
    header: 'Plan',
    cell: ({ row: { original: s } }) => (
      <span>
        {s.plan.name}
        <span className="text-muted-foreground block text-xs">
          {s.is_complimentary ? 'complimentary' : (s.price?.interval ?? (s.is_lifetime ? 'lifetime' : ''))}
        </span>
      </span>
    ),
  },
  { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  {
    id: 'gateway',
    header: 'Gateway',
    cell: ({ row }) => <span className="capitalize">{row.original.gateway}</span>,
  },
  {
    id: 'period_end',
    header: 'Renews / ends',
    cell: ({ row: { original: s } }) =>
      s.is_lifetime
        ? 'Lifetime'
        : `${s.cancel_at_period_end ? 'Ends ' : ''}${formatDate(s.current_period_end)}`,
  },
  { id: 'started', header: 'Started', cell: ({ row }) => formatDate(row.original.created_at) },
]

export default function AdminSubscriptionsPage() {
  const [f, setF] = useState<SubscriptionFilters>({
    search: '',
    status: '',
    gateway: '',
    tier: '',
    renewing: '',
    page: 1,
  })
  const [open, setOpen] = useState<string | null>(null)
  const set = (patch: Partial<SubscriptionFilters>) => setF((s) => ({ ...s, page: 1, ...patch }))
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'subscriptions', f],
    queryFn: () => adminApi.subscriptions(f),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <Seo title="Subscriptions" noindex />
      <PageHeader title="Subscriptions" description="Every membership, paid or complimentary." />
      {data && (
        <div role="group" aria-label="Filter by status" className="mb-4 flex flex-wrap gap-2">
          {STATUSES.filter((s) => data.meta.counts[s]).map((s) => (
            <button
              key={s}
              type="button"
              aria-pressed={f.status === s}
              onClick={() => set({ status: f.status === s ? '' : s })}
              className={cn(
                'rounded-full border px-3 py-1 text-sm capitalize',
                f.status === s ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
              )}
            >
              {s.replace('_', ' ')} <span className="tabular-nums opacity-80">{data.meta.counts[s]}</span>
            </button>
          ))}
        </div>
      )}
      <DataTable
        label="Subscriptions"
        storageKey="admin-subscriptions"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(s) => s.id}
        onPageChange={(page) => setF((s) => ({ ...s, page }))}
        onRowClick={(s) => setOpen(s.id)}
        toolbar={
          <>
            <Input
              type="search"
              aria-label="Search subscriptions"
              placeholder="Member name, email or gateway id"
              className="w-full sm:w-64"
              value={f.search}
              onChange={(e) => set({ search: e.target.value })}
            />
            <select
              aria-label="Status"
              className={toolbarSelect}
              value={f.status}
              onChange={(e) => set({ status: e.target.value as SubscriptionFilters['status'] })}
            >
              <option value="">Any status</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s.replace('_', ' ')}
                </option>
              ))}
            </select>
            <select
              aria-label="Gateway"
              className={toolbarSelect}
              value={f.gateway}
              onChange={(e) => set({ gateway: e.target.value as SubscriptionFilters['gateway'] })}
            >
              <option value="">Any gateway</option>
              <option value="stripe">Stripe</option>
              <option value="razorpay">Razorpay</option>
              <option value="manual">Complimentary</option>
            </select>
            <select
              aria-label="Tier"
              className={toolbarSelect}
              value={f.tier}
              onChange={(e) => set({ tier: e.target.value as SubscriptionFilters['tier'] })}
            >
              <option value="">Any tier</option>
              <option value="1">Starter</option>
              <option value="2">Pro</option>
              <option value="3">Elite</option>
            </select>
            <select
              aria-label="Renewal"
              className={toolbarSelect}
              value={f.renewing}
              onChange={(e) => set({ renewing: e.target.value as SubscriptionFilters['renewing'] })}
            >
              <option value="">Renewing or not</option>
              <option value="1">Will renew</option>
              <option value="0">Cancelling at period end</option>
            </select>
          </>
        }
      />
      <SubscriptionDrawer subscriptionId={open} onClose={() => setOpen(null)} />
    </>
  )
}
