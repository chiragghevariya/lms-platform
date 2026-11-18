import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { useState } from 'react'
import { toast } from 'sonner'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { ExportButton } from '@/components/data-table/ExportButton'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError, http } from '@/lib/api-client'
import { formatDate, formatMinor } from '@/lib/format'
import type {
  AdminAffiliate,
  AffiliatePayoutRow,
  AffiliateStatus,
  CommissionTerms,
} from '@/types/admin-catalog'
import { amounts, affiliatesAdminApi } from '../affiliates-api'
import { ReasonDialog } from '../catalog/ReasonDialog'
import { Tabs } from '../catalog/Tabs'

type Tab = 'affiliates' | 'reviews' | 'payouts' | 'commissions'
type Action = 'approve' | 'reject' | 'suspend'

const errorOf = (e: unknown) => (e instanceof Error ? e.message : 'Something went wrong.')
const money = (v: Record<string, number> | []) =>
  amounts(v)
    .map(([c, n]) => formatMinor(n, c))
    .join(' · ') || '—'
const termsLabel = (t: CommissionTerms) =>
  t.type === 'percent'
    ? `${t.rate ?? 0}%`
    : Object.entries(t.fixed ?? {})
        .map(([c, n]) => formatMinor(n, c))
        .join(' / ') || 'Fixed'
const STATUS_VARIANT: Record<AffiliateStatus, 'default' | 'secondary' | 'outline'> = {
  approved: 'default',
  pending: 'secondary',
  rejected: 'outline',
  suspended: 'outline',
}

export default function AdminAffiliatesPage() {
  const [tab, setTab] = useState<Tab>('affiliates')
  return (
    <>
      <Seo title="Affiliates" noindex />
      <PageHeader
        title="Affiliates"
        description="Approve partners, set commission terms, clear fraud flags and pay out balances."
      />
      <Tabs
        label="Affiliate programme"
        idPrefix="aff"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'affiliates', label: 'Affiliates' },
          { value: 'reviews', label: 'Fraud review' },
          { value: 'payouts', label: 'Payouts' },
          { value: 'commissions', label: 'Commissions' },
        ]}
      />
      <div role="tabpanel" id="aff-panel" aria-labelledby={`aff-tab-${tab}`}>
        {tab === 'affiliates' && <AffiliatesTab />}
        {tab === 'reviews' && <ReviewsTab />}
        {tab === 'payouts' && <PayoutsTab />}
        {tab === 'commissions' && <CommissionsTab />}
      </div>
    </>
  )
}

function AffiliatesTab() {
  const qc = useQueryClient()
  const [status, setStatus] = useState<AffiliateStatus | ''>('pending')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'affiliates', status, page],
    queryFn: () => affiliatesAdminApi.list(status, page),
    placeholderData: keepPreviousData,
  })
  const [deciding, setDeciding] = useState<{ a: AdminAffiliate; action: Action } | null>(null)
  const [terms, setTerms] = useState<AdminAffiliate | null>(null)

  const columns: ColumnDef<AdminAffiliate, unknown>[] = [
    {
      id: 'affiliate',
      header: 'Affiliate',
      enableHiding: false,
      cell: ({ row: { original: a } }) => (
        <div>
          <p className="font-medium">{a.user.name}</p>
          <p className="text-muted-foreground text-xs">
            {a.user.email} · <code>{a.code}</code>
          </p>
          {a.website && (
            <a
              href={a.website}
              target="_blank"
              rel="noreferrer noopener"
              className="text-primary text-xs underline"
            >
              {a.website}
            </a>
          )}
        </div>
      ),
    },
    {
      id: 'status',
      header: 'Status',
      cell: ({ row }) => <Badge variant={STATUS_VARIANT[row.original.status]}>{row.original.status}</Badge>,
    },
    {
      id: 'terms',
      header: 'Terms',
      cell: ({ row: { original: a } }) => (
        <span>
          {termsLabel(a.terms)} <span className="text-muted-foreground text-xs">({a.tier})</span>
        </span>
      ),
    },
    {
      id: 'performance',
      header: 'Referrals / paid',
      cell: ({ row }) => (
        <span className="tabular-nums">
          {row.original.referrals} / {row.original.conversions}
        </span>
      ),
    },
    {
      id: 'balance',
      header: 'Unpaid balance',
      cell: ({ row }) => <span className="tabular-nums">{money(row.original.balances)}</span>,
    },
    { id: 'joined', header: 'Applied', cell: ({ row }) => formatDate(row.original.created_at) },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      cell: ({ row: { original: a } }) => (
        <div className="flex flex-wrap justify-end gap-1">
          {a.status !== 'approved' && (
            <Button size="sm" onClick={() => setDeciding({ a, action: 'approve' })}>
              Approve
            </Button>
          )}
          {a.status === 'pending' && (
            <Button size="sm" variant="outline" onClick={() => setDeciding({ a, action: 'reject' })}>
              Reject
            </Button>
          )}
          {a.status === 'approved' && (
            <Button size="sm" variant="outline" onClick={() => setDeciding({ a, action: 'suspend' })}>
              Suspend
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={() => setTerms(a)}>
            Terms
          </Button>
        </div>
      ),
    },
  ]

  const verb = { approve: 'Approve', reject: 'Reject', suspend: 'Suspend' }

  return (
    <>
      <DataTable
        label="Affiliates"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(a) => a.id}
        onPageChange={setPage}
        storageKey="admin-affiliates"
        toolbar={
          <select
            aria-label="Status"
            className={toolbarSelect}
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as AffiliateStatus | '')
              setPage(1)
            }}
          >
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="suspended">Suspended</option>
            <option value="rejected">Rejected</option>
          </select>
        }
      />
      <ReasonDialog
        open={!!deciding}
        onOpenChange={(o) => !o && setDeciding(null)}
        title={deciding ? `${verb[deciding.action]} ${deciding.a.user.name}?` : ''}
        description={
          deciding?.action === 'approve'
            ? 'They get their referral link and start earning commission.'
            : deciding?.action === 'suspend'
              ? 'Their link stops earning. Unpaid commissions stay on hold.'
              : 'They are told the application was not accepted.'
        }
        label={deciding?.action === 'approve' ? 'Note to the affiliate' : 'Reason (sent to the affiliate)'}
        required={deciding?.action !== 'approve'}
        destructive={deciding?.action !== 'approve'}
        confirmLabel={deciding ? verb[deciding.action] : 'Confirm'}
        onConfirm={async (notes) => {
          await affiliatesAdminApi.decide(deciding!.a.id, deciding!.action, notes)
          toast.success('Affiliate updated')
          await qc.invalidateQueries({ queryKey: ['admin', 'affiliates'] })
        }}
      />
      {terms && <TermsDialog affiliate={terms} onClose={() => setTerms(null)} />}
    </>
  )
}

function TermsDialog({ affiliate, onClose }: { affiliate: AdminAffiliate; onClose: () => void }) {
  const qc = useQueryClient()
  const [tier, setTier] = useState(affiliate.tier)
  const [type, setType] = useState<'' | 'percent' | 'fixed'>('')
  const [rate, setRate] = useState(String(affiliate.terms.rate ?? 20))
  const [fixed, setFixed] = useState({
    INR: String((affiliate.terms.fixed?.INR ?? 0) / 100),
    USD: String((affiliate.terms.fixed?.USD ?? 0) / 100),
  })
  const save = useMutation({
    mutationFn: () =>
      affiliatesAdminApi.updateTerms(affiliate.id, {
        tier,
        commission_type: type || null,
        commission_rate: type === 'percent' ? Number(rate) : null,
        commission_fixed:
          type === 'fixed'
            ? Object.fromEntries(Object.entries(fixed).map(([c, v]) => [c, Math.round(Number(v) * 100)]))
            : null,
      }),
    onSuccess: () => {
      toast.success('Commission terms saved')
      void qc.invalidateQueries({ queryKey: ['admin', 'affiliates'] })
      onClose()
    },
  })
  const err = (f: string) => (save.error instanceof ApiError ? save.error.errors[f]?.[0] : undefined)

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>Commission terms · {affiliate.user.name}</DialogTitle>
            <DialogDescription>
              Currently {termsLabel(affiliate.terms)}. A custom rate overrides the tier rate.
            </DialogDescription>
          </DialogHeader>
          <FormField id="aff-tier" label="Tier" error={err('tier')}>
            {(a) => (
              <select {...a} className={toolbarSelect} value={tier} onChange={(e) => setTier(e.target.value)}>
                {[...new Set(['standard', 'partner', affiliate.tier])].map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </select>
            )}
          </FormField>
          <FormField id="aff-type" label="Commission" error={err('commission_type')}>
            {(a) => (
              <select
                {...a}
                className={toolbarSelect}
                value={type}
                onChange={(e) => setType(e.target.value as typeof type)}
              >
                <option value="">Use the tier's rate</option>
                <option value="percent">Custom percentage</option>
                <option value="fixed">Fixed amount per sale</option>
              </select>
            )}
          </FormField>
          {type === 'percent' && (
            <FormField id="aff-rate" label="Rate (%)" error={err('commission_rate')}>
              {(a) => (
                <Input
                  {...a}
                  type="number"
                  min={0}
                  max={100}
                  value={rate}
                  onChange={(e) => setRate(e.target.value)}
                  className="w-28"
                />
              )}
            </FormField>
          )}
          {type === 'fixed' && (
            <div className="grid grid-cols-2 gap-3">
              {(['INR', 'USD'] as const).map((c) => (
                <FormField
                  key={c}
                  id={`aff-fixed-${c}`}
                  label={`${c} per sale`}
                  error={err(`commission_fixed.${c}`)}
                >
                  {(a) => (
                    <Input
                      {...a}
                      type="number"
                      min={0}
                      step="0.01"
                      value={fixed[c]}
                      onChange={(e) => setFixed((s) => ({ ...s, [c]: e.target.value }))}
                    />
                  )}
                </FormField>
              ))}
            </div>
          )}
          {save.error && !(save.error instanceof ApiError && save.error.isValidation) && (
            <p role="alert" className="text-destructive text-sm">
              {errorOf(save.error)}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={save.isPending}>
              Save terms
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function ReviewsTab() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'affiliate-reviews'],
    queryFn: affiliatesAdminApi.reviews,
  })
  const decide = useMutation({
    mutationFn: ({ id, approve }: { id: string; approve: boolean }) => affiliatesAdminApi.review(id, approve),
    onSuccess: (_, v) => {
      toast.success(
        v.approve
          ? 'Referral cleared; commissions released to the normal hold'
          : 'Referral rejected; commissions reversed',
      )
      void qc.invalidateQueries({ queryKey: ['admin', 'affiliate-reviews'] })
    },
    onError: (e) => toast.error(errorOf(e)),
  })

  if (isLoading) return <Skeleton className="h-40" />
  if (!data?.length) return <p className="text-muted-foreground text-sm">No flagged referrals. Nice.</p>

  return (
    <ul className="grid gap-3" aria-label="Flagged referrals">
      {data.map((r) => (
        <li
          key={r.id}
          className="bg-card flex flex-wrap items-start justify-between gap-3 rounded-xl border p-4"
        >
          <div className="grid gap-1 text-sm">
            <p>
              <span className="font-medium">{r.affiliate.name}</span>{' '}
              <code className="text-xs">{r.affiliate.code}</code> referred{' '}
              <span className="font-medium">{r.customer.name}</span>{' '}
              <span className="text-muted-foreground">({r.customer.email})</span>
            </p>
            <div className="flex flex-wrap gap-1">
              {r.flags.map((f) => (
                <Badge key={f} variant="outline" className="border-destructive/50 text-destructive">
                  {f.replaceAll('_', ' ')}
                </Badge>
              ))}
            </div>
            <p className="text-muted-foreground text-xs">
              Held commission: {money(r.held)} · {formatDate(r.created_at)}
            </p>
          </div>
          <div className="flex gap-2">
            <Button
              size="sm"
              disabled={decide.isPending}
              onClick={() => decide.mutate({ id: r.id, approve: true })}
            >
              Looks fine
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={decide.isPending}
              onClick={() => decide.mutate({ id: r.id, approve: false })}
            >
              Reject
            </Button>
          </div>
        </li>
      ))}
    </ul>
  )
}

const thisMonth = () => new Date().toISOString().slice(0, 7)

function PayoutsTab() {
  const qc = useQueryClient()
  const [period, setPeriod] = useState(thisMonth())
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'affiliate-payouts', period],
    queryFn: () => affiliatesAdminApi.payouts(period),
    enabled: /^\d{4}-\d{2}$/.test(period),
  })
  const [paying, setPaying] = useState<AffiliatePayoutRow | null>(null)
  const [failing, setFailing] = useState<AffiliatePayoutRow | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'affiliate-payouts'] })

  const batch = useMutation({
    mutationFn: () => affiliatesAdminApi.batch(period),
    onSuccess: (r) => {
      toast.success(r.created ? `${r.created} payout(s) created` : 'No balances above the payout minimum')
      void refresh()
    },
    onError: (e) => toast.error(errorOf(e)),
  })

  async function downloadCsv() {
    try {
      const res = await http.get<Blob>('/admin/affiliate-payouts/export', {
        params: { period },
        responseType: 'blob',
      })
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url
      a.download = `affiliate-payouts-${period}.csv`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast.error('Export failed.')
    }
  }

  const columns: ColumnDef<AffiliatePayoutRow, unknown>[] = [
    {
      id: 'affiliate',
      header: 'Affiliate',
      enableHiding: false,
      cell: ({ row: { original: p } }) => (
        <div>
          <p className="font-medium">{p.affiliate.name}</p>
          <p className="text-muted-foreground text-xs">{p.affiliate.email}</p>
        </div>
      ),
    },
    {
      id: 'amount',
      header: 'Amount',
      cell: ({ row: { original: p } }) => (
        <span className="tabular-nums">
          {formatMinor(p.amount, p.currency)}{' '}
          <span className="text-muted-foreground text-xs">({p.commissions_count} commissions)</span>
        </span>
      ),
    },
    { id: 'method', header: 'Method', cell: ({ row }) => row.original.method ?? '—' },
    {
      id: 'status',
      header: 'Status',
      cell: ({ row: { original: p } }) => (
        <div>
          <Badge variant={p.status === 'paid' ? 'default' : p.status === 'failed' ? 'outline' : 'secondary'}>
            {p.status}
          </Badge>
          {p.reference && <p className="text-muted-foreground text-xs">Ref {p.reference}</p>}
        </div>
      ),
    },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      cell: ({ row: { original: p } }) =>
        p.status !== 'pending' ? null : (
          <div className="flex justify-end gap-1">
            <Button size="sm" onClick={() => setPaying(p)}>
              Mark paid
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setFailing(p)}>
              Failed
            </Button>
          </div>
        ),
    },
  ]

  return (
    <>
      <DataTable
        label="Affiliate payouts"
        columns={columns}
        data={data}
        loading={isLoading}
        getRowId={(p) => p.id}
        storageKey="admin-affiliate-payouts"
        empty="No payouts for this month yet. Create the batch to pay approved balances above the minimum."
        toolbar={
          <Input
            type="month"
            aria-label="Payout month"
            className="w-44"
            value={period}
            onChange={(e) => setPeriod(e.target.value)}
          />
        }
        actions={
          <>
            <Button size="sm" variant="outline" onClick={downloadCsv} disabled={!data?.length}>
              Bank CSV
            </Button>
            <Button size="sm" disabled={batch.isPending} onClick={() => batch.mutate()}>
              Create payout batch
            </Button>
          </>
        }
      />
      <ReasonDialog
        open={!!paying}
        onOpenChange={(o) => !o && setPaying(null)}
        title="Mark payout as paid"
        description={
          paying ? `${formatMinor(paying.amount, paying.currency)} to ${paying.affiliate.name}` : undefined
        }
        label="Payment reference (UTR / transaction id)"
        multiline={false}
        confirmLabel="Mark paid"
        onConfirm={async (reference) => {
          await affiliatesAdminApi.markPaid(paying!.id, reference)
          toast.success('Payout marked paid; the affiliate is notified')
          await refresh()
        }}
      />
      <Dialog open={!!failing} onOpenChange={(o) => !o && setFailing(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Mark this payout as failed?</DialogTitle>
            <DialogDescription>
              Its commissions return to the affiliate's balance for the next batch.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFailing(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={async () => {
                try {
                  await affiliatesAdminApi.markFailed(failing!.id)
                  toast.success('Payout marked failed')
                  setFailing(null)
                  await refresh()
                } catch (e) {
                  toast.error(errorOf(e))
                }
              }}
            >
              Mark failed
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function CommissionsTab() {
  const [f, setF] = useState({ status: '', currency: '', from: '', to: '' })
  const set = (patch: Partial<typeof f>) => setF((s) => ({ ...s, ...patch }))
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v))

  return (
    <div className="bg-card grid max-w-2xl gap-4 rounded-xl border p-5">
      <p className="text-muted-foreground text-sm">
        Download every commission (with affiliate, rate, payment and status) for accounting or tax filing.
      </p>
      <div className="flex flex-wrap items-end gap-2">
        <select
          aria-label="Commission status"
          className={toolbarSelect}
          value={f.status}
          onChange={(e) => set({ status: e.target.value })}
        >
          <option value="">All statuses</option>
          <option value="pending">Pending (on hold)</option>
          <option value="approved">Approved (unpaid)</option>
          <option value="paid">Paid</option>
          <option value="reversed">Reversed</option>
        </select>
        <select
          aria-label="Currency"
          className={toolbarSelect}
          value={f.currency}
          onChange={(e) => set({ currency: e.target.value })}
        >
          <option value="">All currencies</option>
          <option value="INR">INR</option>
          <option value="USD">USD</option>
        </select>
        <Input
          type="date"
          aria-label="From"
          className="w-40"
          value={f.from}
          onChange={(e) => set({ from: e.target.value })}
        />
        <Input
          type="date"
          aria-label="To"
          className="w-40"
          value={f.to}
          onChange={(e) => set({ to: e.target.value })}
        />
        <ExportButton path="/admin/commissions/export" params={params} label="Export commissions" />
      </div>
    </div>
  )
}
