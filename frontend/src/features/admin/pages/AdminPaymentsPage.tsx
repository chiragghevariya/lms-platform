import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { useState } from 'react'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { ExportButton } from '@/components/data-table/ExportButton'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { formatDate, formatMinor } from '@/lib/format'
import type { AdminPayment, PaymentFilters } from '@/types/admin'
import { adminApi, clean } from '../api'
import { PaymentDrawer } from '../components/PaymentDrawer'
import { CountryInput } from '../components/CountryInput'
import { StatusBadge } from '../components/StatusBadge'

const columns: ColumnDef<AdminPayment, unknown>[] = [
  {
    id: 'date',
    header: 'Date',
    enableHiding: false,
    cell: ({ row: { original: p } }) => (
      <span className="whitespace-nowrap">
        {formatDate(p.paid_at ?? p.created_at)}
        <span className="text-muted-foreground block text-xs">{p.invoice_number ?? '—'}</span>
      </span>
    ),
  },
  {
    id: 'customer',
    header: 'Customer',
    cell: ({ row: { original: p } }) => (
      <span className="min-w-36">
        {p.user?.name ?? '—'}
        <span className="text-muted-foreground block text-xs">{p.user?.email}</span>
      </span>
    ),
  },
  {
    id: 'amount',
    header: 'Amount',
    enableHiding: false,
    cell: ({ row: { original: p } }) => (
      <span className="tabular-nums">
        {p.amount.formatted}
        {p.refunded_minor > 0 && (
          <span className="text-muted-foreground block text-xs">
            −{formatMinor(p.refunded_minor, p.currency)} refunded
          </span>
        )}
      </span>
    ),
  },
  { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  { id: 'type', header: 'Type', cell: ({ row }) => <span className="capitalize">{row.original.type}</span> },
  {
    id: 'gateway',
    header: 'Gateway',
    cell: ({ row }) => <span className="capitalize">{row.original.gateway}</span>,
  },
  { id: 'country', header: 'Country', cell: ({ row }) => row.original.billing_country ?? '—' },
]

const EMPTY: PaymentFilters = {
  search: '',
  status: '',
  gateway: '',
  type: '',
  currency: '',
  country: '',
  refunded: '',
  from: '',
  to: '',
  page: 1,
}

export default function AdminPaymentsPage() {
  const [f, setF] = useState<PaymentFilters>(EMPTY)
  const [open, setOpen] = useState<string | null>(null)
  const set = (patch: Partial<PaymentFilters>) => setF((s) => ({ ...s, page: 1, ...patch }))
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'payments', f],
    queryFn: () => adminApi.payments(f),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <Seo title="Payments & refunds" noindex />
      <PageHeader
        title="Payments & refunds"
        description="Every charge across gateways. Open a payment to refund it."
      />
      {data && data.meta.totals.length > 0 && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.meta.totals.map((t) => (
            <Card key={t.currency}>
              <CardContent className="p-4">
                <p className="text-muted-foreground text-sm">Net collected · {t.currency}</p>
                <p className="text-2xl font-bold tabular-nums">
                  {formatMinor(t.gross - t.refunded, t.currency)}
                </p>
                <p className="text-muted-foreground text-xs tabular-nums">
                  {formatMinor(t.gross, t.currency)} gross · {formatMinor(t.refunded, t.currency)} refunded ·{' '}
                  {t.count} payments
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      <DataTable
        label="Payments"
        storageKey="admin-payments"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(p) => p.id}
        onPageChange={(page) => setF((s) => ({ ...s, page }))}
        onRowClick={(p) => setOpen(p.id)}
        actions={<ExportButton path="/admin/payments/export" params={clean({ ...f, page: undefined })} />}
        toolbar={
          <>
            <Input
              type="search"
              aria-label="Search payments"
              placeholder="Invoice, email or gateway id"
              className="w-full sm:w-60"
              value={f.search}
              onChange={(e) => set({ search: e.target.value })}
            />
            <select
              aria-label="Status"
              className={toolbarSelect}
              value={f.status}
              onChange={(e) => set({ status: e.target.value as PaymentFilters['status'] })}
            >
              <option value="">Any status</option>
              <option value="succeeded">Succeeded</option>
              <option value="refunded">Refunded</option>
              <option value="failed">Failed</option>
              <option value="pending">Pending</option>
            </select>
            <select
              aria-label="Gateway"
              className={toolbarSelect}
              value={f.gateway}
              onChange={(e) => set({ gateway: e.target.value as PaymentFilters['gateway'] })}
            >
              <option value="">Any gateway</option>
              <option value="stripe">Stripe</option>
              <option value="razorpay">Razorpay</option>
              <option value="manual">Manual</option>
            </select>
            <select
              aria-label="Type"
              className={toolbarSelect}
              value={f.type}
              onChange={(e) => set({ type: e.target.value as PaymentFilters['type'] })}
            >
              <option value="">Memberships & courses</option>
              <option value="membership">Memberships</option>
              <option value="course">Course sales</option>
            </select>
            <select
              aria-label="Currency"
              className={toolbarSelect}
              value={f.currency}
              onChange={(e) => set({ currency: e.target.value })}
            >
              <option value="">Any currency</option>
              <option value="INR">INR</option>
              <option value="USD">USD</option>
            </select>
            <select
              aria-label="Refunds"
              className={toolbarSelect}
              value={f.refunded}
              onChange={(e) => set({ refunded: e.target.value as PaymentFilters['refunded'] })}
            >
              <option value="">Refunded or not</option>
              <option value="1">Has a refund</option>
              <option value="0">No refund</option>
            </select>
            <CountryInput value={f.country} onChange={(v) => set({ country: v })} />
            <Input
              aria-label="Paid from"
              title="Paid from"
              type="date"
              className="w-40"
              value={f.from}
              onChange={(e) => set({ from: e.target.value })}
            />
            <Input
              aria-label="Paid to"
              title="Paid to"
              type="date"
              className="w-40"
              value={f.to}
              onChange={(e) => set({ to: e.target.value })}
            />
          </>
        }
      />
      <PaymentDrawer paymentId={open} onClose={() => setOpen(null)} />
    </>
  )
}
