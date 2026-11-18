import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { adminInstructorApi } from '@/features/instructor/api'
import { formatMinor, monthName } from '@/lib/format'

const lastMonth = () => {
  const d = new Date()
  d.setUTCDate(1)
  d.setUTCMonth(d.getUTCMonth() - 1)
  return d.toISOString().slice(0, 7)
}

export default function AdminPayoutsPage() {
  const qc = useQueryClient()
  const [period, setPeriod] = useState(lastMonth)
  const [paying, setPaying] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const { data } = useQuery({
    queryKey: ['admin', 'payouts', period],
    queryFn: () => adminInstructorApi.payouts(period),
  })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['admin', 'payouts'] })
  const drafts = data?.data.filter((s) => s.status === 'draft').length ?? 0

  const run = async (kind: 'generate' | 'finalize') => {
    setBusy(kind)
    try {
      const r =
        kind === 'generate'
          ? await adminInstructorApi.generate(period)
          : await adminInstructorApi.finalize(period)
      toast.success(
        kind === 'generate'
          ? `${'written' in r ? r.written : 0} statement(s) computed`
          : `${'finalized' in r ? r.finalized : 0} statement(s) finalized`,
      )
      refresh()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Failed')
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <Seo title="Instructor payouts" noindex />
      <PageHeader
        title="Instructor payouts"
        description="Compute → review → finalize → pay. Finalized statements never change."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <label htmlFor="po-period" className="text-sm">
              Month
            </label>
            <Input
              id="po-period"
              type="month"
              className="w-40"
              value={period}
              onChange={(e) => e.target.value && setPeriod(e.target.value)}
            />
            <Button variant="outline" loading={busy === 'generate'} onClick={() => void run('generate')}>
              Compute
            </Button>
            <Button loading={busy === 'finalize'} disabled={!drafts} onClick={() => void run('finalize')}>
              Finalize {drafts || ''}
            </Button>
          </div>
        }
      />
      {data && Object.keys(data.meta.totals).length > 0 && (
        <div className="mb-4 flex flex-wrap gap-4">
          {Object.entries(data.meta.totals).map(([cur, t]) => (
            <Card key={cur} className="min-w-48">
              <CardContent className="p-4">
                <p className="text-muted-foreground text-sm">
                  {monthName(period)} · {cur}
                </p>
                <p className="text-2xl font-bold tabular-nums">{formatMinor(t.total, cur)}</p>
                <p className="text-muted-foreground text-xs">{formatMinor(t.paid, cur)} paid</p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      {data?.data.length === 0 && (
        <p className="text-muted-foreground mb-6 text-sm">
          No statements for {monthName(period)}. Click “Compute” once the month has ended.
        </p>
      )}
      {!!data?.data.length && (
        <div className="mb-8 overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th className="p-3 font-medium">Instructor</th>
                <th className="p-3 text-right font-medium">Pool</th>
                <th className="p-3 text-right font-medium">Sales</th>
                <th className="p-3 text-right font-medium">Total</th>
                <th className="p-3 font-medium">Payout to</th>
                <th className="p-3 font-medium">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody className="divide-y tabular-nums">
              {data.data.map((s) => (
                <tr key={s.id}>
                  <td className="p-3">
                    <p className="font-medium">{s.instructor.name}</p>
                    <p className="text-muted-foreground text-xs">{s.instructor.email}</p>
                  </td>
                  <td className="p-3 text-right">{formatMinor(s.subscription_earnings, s.currency)}</td>
                  <td className="p-3 text-right">{formatMinor(s.sales_earnings, s.currency)}</td>
                  <td className="p-3 text-right font-semibold">{formatMinor(s.total, s.currency)}</td>
                  <td className="p-3 text-xs">
                    {s.payout.summary ?? <span className="text-destructive">Missing</span>}
                  </td>
                  <td className="p-3">
                    <Badge variant={s.status === 'paid' ? 'default' : 'secondary'} className="capitalize">
                      {s.status}
                    </Badge>
                  </td>
                  <td className="p-3 text-right">
                    {s.status === 'finalized' && (
                      <Button size="sm" onClick={() => setPaying(s.id!)}>
                        Mark paid
                      </Button>
                    )}
                    {s.status === 'paid' && (
                      <span className="text-muted-foreground text-xs">Ref {s.paid_reference}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <SharesCard />
      {paying && <PayDialog id={paying} onClose={() => setPaying(null)} onDone={refresh} />}
    </>
  )
}

function PayDialog({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: () => void }) {
  const { data: s } = useQuery({
    queryKey: ['admin', 'payout', id],
    queryFn: () => adminInstructorApi.payout(id),
    gcTime: 0,
  })
  const [ref, setRef] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Pay {s?.instructor.name}</DialogTitle>
          <DialogDescription>
            {s &&
              `${formatMinor(s.total, s.currency)} for ${monthName(s.period!)}. Send the transfer, then record its reference.`}
          </DialogDescription>
        </DialogHeader>
        {s && (
          <dl className="bg-muted grid grid-cols-[8rem_1fr] gap-1 rounded-md p-3 text-sm">
            <dt className="text-muted-foreground">Method</dt>
            <dd className="uppercase">{s.payout.method ?? '—'}</dd>
            {Object.entries(s.payout.details ?? {}).map(([k, v]) => (
              <div key={k} className="contents">
                <dt className="text-muted-foreground capitalize">{k.replace(/_/g, ' ')}</dt>
                <dd className="font-mono">{v}</dd>
              </div>
            ))}
            {s.payout.tax_id && (
              <>
                <dt className="text-muted-foreground">Tax ID</dt>
                <dd className="font-mono">{s.payout.tax_id}</dd>
              </>
            )}
          </dl>
        )}
        {s && !s.payout.method && (
          <p
            role="alert"
            className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border p-2 text-sm"
          >
            This instructor hasn't added payout details yet. Only mark this paid if you settled it another
            way.
          </p>
        )}
        <label htmlFor="pay-ref" className="text-sm font-medium">
          Transfer reference (UTR / transaction ID)
        </label>
        <Input id="pay-ref" maxLength={120} value={ref} onChange={(e) => setRef(e.target.value)} />
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            loading={busy}
            disabled={!ref.trim()}
            onClick={async () => {
              setBusy(true)
              try {
                await adminInstructorApi.markPaid(id, ref.trim())
                toast.success('Marked as paid')
                onDone()
                onClose()
              } catch (e) {
                toast.error(e instanceof Error ? e.message : 'Failed')
              } finally {
                setBusy(false)
              }
            }}
          >
            Mark paid
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function SharesCard() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['admin', 'instructors'], queryFn: adminInstructorApi.instructors })
  const [edit, setEdit] = useState<Record<string, string>>({})
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Revenue share</CardTitle>
        <CardDescription>
          Instructor share of course sales (default {data?.meta.default_share}%). The membership pool is{' '}
          {data?.meta.pool_percent}% of net membership revenue (EARNINGS_POOL_PERCENT). Changes apply to
          statements computed afterwards.
        </CardDescription>
      </CardHeader>
      <CardContent className="overflow-x-auto">
        <table className="w-full min-w-[520px] text-sm">
          <tbody className="divide-y">
            {data?.data.map((i) => (
              <tr key={i.id}>
                <td className="py-2">
                  <p className="font-medium">{i.name}</p>
                  <p className="text-muted-foreground text-xs">
                    {i.email}
                    {!i.payout_ready && ' · no payout details yet'}
                  </p>
                </td>
                <td className="py-2">
                  <div className="flex items-center justify-end gap-2">
                    <label htmlFor={`share-${i.id}`} className="sr-only">
                      Revenue share for {i.name}
                    </label>
                    <Input
                      id={`share-${i.id}`}
                      type="number"
                      min={0}
                      max={100}
                      className="w-20"
                      value={edit[i.id] ?? String(i.revenue_share_percent)}
                      onChange={(e) => setEdit({ ...edit, [i.id]: e.target.value })}
                    />
                    <span>%</span>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={edit[i.id] === undefined}
                      onClick={async () => {
                        try {
                          await adminInstructorApi.setShare(
                            i.id,
                            edit[i.id] === '' ? null : Number(edit[i.id]),
                          )
                          toast.success('Saved')
                          setEdit(({ [i.id]: _, ...rest }) => rest)
                          void qc.invalidateQueries({ queryKey: ['admin', 'instructors'] })
                        } catch (e) {
                          toast.error(e instanceof Error ? e.message : 'Failed')
                        }
                      }}
                    >
                      Save
                    </Button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  )
}
