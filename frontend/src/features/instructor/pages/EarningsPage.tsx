import { useQuery } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { formatMinor, monthName } from '@/lib/format'
import type { Statement } from '@/types/instructor'
import { teachingApi } from '../api'

const hours = (s: number) => `${(s / 3600).toLocaleString(undefined, { maximumFractionDigits: 1 })} h`
const STATUS: Record<string, string> = {
  draft: 'Being prepared',
  finalized: 'Approved · awaiting payment',
  paid: 'Paid',
}

export default function EarningsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['instructor', 'earnings'],
    queryFn: teachingApi.earnings,
  })
  const [open, setOpen] = useState<string | null>(null)

  return (
    <>
      <Seo title="Earnings" noindex />
      <PageHeader title="Earnings & payouts" description="Monthly statements, paid after the month closes." />
      {isLoading || !data ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="grid gap-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Info className="size-4" aria-hidden /> How you earn
              </CardTitle>
              <CardDescription>
                <strong>Membership pool:</strong> {data.pool_percent}% of membership revenue (after tax) is
                shared between instructors by how many minutes paying members watch their courses.{' '}
                <strong>Course sales:</strong> you keep {data.revenue_share_percent}% of each one-time sale of
                your courses (after tax).
              </CardDescription>
            </CardHeader>
          </Card>

          <section aria-labelledby="cur-h" className="grid gap-3">
            <h2 id="cur-h" className="font-semibold">
              {monthName(data.current.period)} so far (estimate)
            </h2>
            {data.current.estimate.length === 0 ? (
              <p className="text-muted-foreground text-sm">No earnings yet this month.</p>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2">
                {data.current.estimate.map((s) => (
                  <Summary key={s.currency} s={s} />
                ))}
              </div>
            )}
          </section>

          <section aria-labelledby="st-h" className="grid gap-3">
            <h2 id="st-h" className="font-semibold">
              Statements
            </h2>
            {data.statements.length === 0 ? (
              <p className="text-muted-foreground text-sm">Statements appear here after each month closes.</p>
            ) : (
              <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[620px] text-sm">
                  <thead className="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                      <th className="p-3 font-medium">Month</th>
                      <th className="p-3 text-right font-medium">Membership pool</th>
                      <th className="p-3 text-right font-medium">Course sales</th>
                      <th className="p-3 text-right font-medium">Total</th>
                      <th className="p-3 font-medium">Status</th>
                      <th className="p-3" />
                    </tr>
                  </thead>
                  <tbody className="divide-y tabular-nums">
                    {data.statements.map((s) => (
                      <tr key={s.id}>
                        <td className="p-3">
                          {monthName(s.period!)}{' '}
                          <span className="text-muted-foreground text-xs">{s.currency}</span>
                        </td>
                        <td className="p-3 text-right">{formatMinor(s.subscription_earnings, s.currency)}</td>
                        <td className="p-3 text-right">{formatMinor(s.sales_earnings, s.currency)}</td>
                        <td className="p-3 text-right font-semibold">{formatMinor(s.total, s.currency)}</td>
                        <td className="p-3">
                          <Badge variant={s.status === 'paid' ? 'default' : 'secondary'}>
                            {STATUS[s.status!]}
                          </Badge>
                          {s.paid_reference && (
                            <span className="text-muted-foreground ml-2 text-xs">Ref {s.paid_reference}</span>
                          )}
                        </td>
                        <td className="p-3 text-right">
                          <Button size="sm" variant="ghost" onClick={() => setOpen(s.id!)}>
                            Details
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      )}
      {open && <StatementDialog id={open} onClose={() => setOpen(null)} />}
    </>
  )
}

function Summary({ s }: { s: Statement }) {
  return (
    <Card>
      <CardContent className="grid gap-1 p-5">
        <p className="text-muted-foreground text-sm">{s.currency}</p>
        <p className="text-3xl font-bold tabular-nums">{formatMinor(s.total, s.currency)}</p>
        <p className="text-muted-foreground text-xs">
          Pool {formatMinor(s.subscription_earnings, s.currency)} ({hours(s.watch_seconds)} of{' '}
          {hours(s.total_watch_seconds)} member watch time) · Sales{' '}
          {formatMinor(s.sales_earnings, s.currency)}
        </p>
      </CardContent>
    </Card>
  )
}

function StatementDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const { data: s } = useQuery({
    queryKey: ['instructor', 'statement', id],
    queryFn: () => teachingApi.statement(id),
  })
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{s ? `${monthName(s.period!)} · ${s.currency}` : 'Statement'}</DialogTitle>
          {s && (
            <DialogDescription>
              Membership revenue {formatMinor(s.subscription_revenue, s.currency)} × {s.pool_percent}% = pool{' '}
              {formatMinor(s.pool_amount, s.currency)}. Your share: {hours(s.watch_seconds)} of{' '}
              {hours(s.total_watch_seconds)} watched. Course sales at {s.revenue_share_percent}%.
            </DialogDescription>
          )}
        </DialogHeader>
        {s?.lines && (
          <table className="w-full text-sm">
            <thead className="text-muted-foreground text-left">
              <tr>
                <th className="py-2 font-medium">Item</th>
                <th className="py-2 text-right font-medium">Based on</th>
                <th className="py-2 text-right font-medium">You earn</th>
              </tr>
            </thead>
            <tbody className="divide-y tabular-nums">
              {s.lines.map((l, i) => (
                <tr key={i}>
                  <td className="py-2">
                    {l.description}
                    {l.type === 'subscription_pool' && (
                      <span className="text-muted-foreground block text-xs">
                        {hours(l.watch_seconds)} watched
                      </span>
                    )}
                  </td>
                  <td className="py-2 text-right">{formatMinor(l.gross, s.currency)}</td>
                  <td className="py-2 text-right font-medium">{formatMinor(l.amount, s.currency)}</td>
                </tr>
              ))}
              <tr>
                <td className="py-2 font-semibold" colSpan={2}>
                  Total
                </td>
                <td className="py-2 text-right font-semibold">{formatMinor(s.total, s.currency)}</td>
              </tr>
            </tbody>
          </table>
        )}
      </DialogContent>
    </Dialog>
  )
}
