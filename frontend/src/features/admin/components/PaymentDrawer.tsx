import { useQuery } from '@tanstack/react-query'
import { Undo2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, formatMinor } from '@/lib/format'
import { can } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { adminApi } from '../api'
import { RefundDialog } from './RefundDialog'
import { StatusBadge } from './StatusBadge'

/** One payment: amounts, tax, gateway ids, refund history and the refund action. */
export function PaymentDrawer({ paymentId, onClose }: { paymentId: string | null; onClose: () => void }) {
  const me = useAuthStore((s) => s.user)
  const [refunding, setRefunding] = useState(false)
  const { data: p, isLoading } = useQuery({
    queryKey: ['admin', 'payment', paymentId],
    queryFn: () => adminApi.payment(paymentId!),
    enabled: !!paymentId,
  })

  return (
    <Sheet open={!!paymentId} onOpenChange={(o) => !o && onClose()}>
      <SheetContent aria-describedby={undefined}>
        {isLoading || !p ? (
          <>
            <SheetHeader>
              <SheetTitle>Loading payment…</SheetTitle>
            </SheetHeader>
            <SheetBody>
              <Skeleton className="h-40" />
            </SheetBody>
          </>
        ) : (
          <>
            <SheetHeader>
              <SheetTitle>
                {p.amount.formatted} · {p.invoice_number ?? 'No invoice'}
              </SheetTitle>
              <SheetDescription>
                {p.description ?? (p.type === 'course' ? 'Course purchase' : 'Membership')}
              </SheetDescription>
              <div className="flex flex-wrap gap-2 pt-1">
                <StatusBadge status={p.status} />
                {p.refunded_minor > 0 && p.status !== 'refunded' && (
                  <StatusBadge status="refunded" className="opacity-80" />
                )}
                {p.disputed_at && <StatusBadge status="failed" className="normal-case" />}
              </div>
            </SheetHeader>
            <SheetBody>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <Fact label="Customer">
                  {p.user ? (
                    <Link
                      className="text-primary underline-offset-2 hover:underline"
                      to={`/admin/users?open=${p.user.id}`}
                    >
                      {p.user.name}
                    </Link>
                  ) : (
                    '—'
                  )}
                  {p.user && <span className="text-muted-foreground block text-xs">{p.user.email}</span>}
                </Fact>
                <Fact label="Paid">{formatDate(p.paid_at)}</Fact>
                <Fact label="Gateway">{p.gateway}</Fact>
                <Fact label="Gateway id">
                  <span className="font-mono text-xs break-all">{p.gateway_payment_id ?? '—'}</span>
                </Fact>
                <Fact label="Subtotal">{p.subtotal.formatted}</Fact>
                <Fact label="Discount">{p.discount.formatted}</Fact>
                <Fact label={`Tax${p.tax.name ? ` (${p.tax.name} ${p.tax.rate}%)` : ''}`}>
                  {p.tax.amount.formatted}
                </Fact>
                <Fact label="Country">{p.billing_country ?? '—'}</Fact>
                <Fact label="Refunded">{formatMinor(p.refunded_minor, p.currency)}</Fact>
                <Fact label="Refundable">{formatMinor(p.refundable_minor, p.currency)}</Fact>
                {p.period_start && (
                  <Fact label="Period">
                    {formatDate(p.period_start)} – {formatDate(p.period_end)}
                  </Fact>
                )}
                {p.failure_reason && <Fact label="Failure">{p.failure_reason}</Fact>}
              </dl>
              <section aria-labelledby="pd-hist" className="grid gap-2">
                <h3 id="pd-hist" className="font-semibold">
                  History
                </h3>
                {p.history.length === 0 ? (
                  <p className="text-muted-foreground text-sm">No admin actions on this payment.</p>
                ) : (
                  <ul className="text-muted-foreground grid gap-1 text-sm">
                    {p.history.map((h, i) => (
                      <li key={i}>
                        {formatDate(h.created_at)} · {h.description}
                        {h.causer ? ` by ${h.causer}` : ''}
                        {typeof h.properties.reason === 'string' ? ` — “${h.properties.reason}”` : ''}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </SheetBody>
            {can(me, 'payments.refund') && p.refundable_minor > 0 && (
              <SheetFooter>
                <Button variant="destructive" onClick={() => setRefunding(true)}>
                  <Undo2 aria-hidden /> Refund
                </Button>
              </SheetFooter>
            )}
            <RefundDialog payment={p} open={refunding} onOpenChange={setRefunding} />
          </>
        )}
      </SheetContent>
    </Sheet>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  )
}
