import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { adminApi } from '../api'
import { StatusBadge } from './StatusBadge'

/** One subscription with its billing history and a link to the member. */
export function SubscriptionDrawer({
  subscriptionId,
  onClose,
}: {
  subscriptionId: string | null
  onClose: () => void
}) {
  const { data: s, isLoading } = useQuery({
    queryKey: ['admin', 'subscription', subscriptionId],
    queryFn: () => adminApi.subscription(subscriptionId!),
    enabled: !!subscriptionId,
  })

  return (
    <Sheet open={!!subscriptionId} onOpenChange={(o) => !o && onClose()}>
      <SheetContent aria-describedby={undefined}>
        {isLoading || !s ? (
          <>
            <SheetHeader>
              <SheetTitle>Loading subscription…</SheetTitle>
            </SheetHeader>
            <SheetBody>
              <Skeleton className="h-40" />
            </SheetBody>
          </>
        ) : (
          <>
            <SheetHeader>
              <SheetTitle>
                {s.plan.name}
                {s.price ? ` · ${s.price.interval}` : ''}
              </SheetTitle>
              <SheetDescription>
                {s.user ? (
                  <Link
                    className="text-primary underline-offset-2 hover:underline"
                    to={`/admin/users?open=${s.user.id}`}
                  >
                    {s.user.name} ({s.user.email})
                  </Link>
                ) : (
                  'Member'
                )}
              </SheetDescription>
              <div className="flex flex-wrap gap-2 pt-1">
                <StatusBadge status={s.status} />
                {s.is_complimentary && <StatusBadge status="complimentary" />}
                {s.cancel_at_period_end && <StatusBadge status="cancelling" />}
              </div>
            </SheetHeader>
            <SheetBody>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <Fact label="Gateway" value={s.gateway} />
                <Fact label="Gateway id" value={s.gateway_subscription_id ?? '—'} mono />
                <Fact label="Started" value={formatDate(s.created_at)} />
                <Fact
                  label="Current period"
                  value={`${formatDate(s.current_period_start)} – ${s.is_lifetime ? 'lifetime' : formatDate(s.current_period_end)}`}
                />
                <Fact label="Trial ends" value={formatDate(s.trial_ends_at)} />
                <Fact
                  label="Renews"
                  value={
                    s.is_lifetime ? 'Lifetime' : s.cancel_at_period_end ? 'No — ends at period end' : 'Yes'
                  }
                />
                {s.past_due && <Fact label="Grace ends" value={formatDate(s.grace_ends_at)} />}
                {s.ended_at && <Fact label="Ended" value={formatDate(s.ended_at)} />}
                {s.scheduled_change && (
                  <Fact
                    label="Scheduled change"
                    value={`${s.scheduled_change.plan?.name ?? ''} on ${formatDate(s.scheduled_change.effective_at)}`}
                  />
                )}
                {s.notes && <Fact label="Notes" value={s.notes} />}
              </dl>
              <section aria-labelledby="sd-pay" className="grid gap-2">
                <h3 id="sd-pay" className="font-semibold">
                  Payments
                </h3>
                {s.payments.length === 0 ? (
                  <p className="text-muted-foreground text-sm">No payments yet.</p>
                ) : (
                  <ul className="divide-y rounded-lg border text-sm">
                    {s.payments.map((p) => (
                      <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                        <span>
                          {formatDate(p.paid_at ?? p.created_at)}
                          <span className="text-muted-foreground block text-xs">
                            {p.invoice_number ?? p.gateway}
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
            </SheetBody>
          </>
        )}
      </SheetContent>
    </Sheet>
  )
}

function Fact({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={mono ? 'font-mono text-xs break-all' : 'font-medium'}>{value}</dd>
    </div>
  )
}
