import { useQuery } from '@tanstack/react-query'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, formatMinor, intervalSuffix, trimMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Subscription } from '@/types/api'
import { billingApi, useBillingMutation, usePlans } from '../api'

/** Pick another recurring price in the same currency, preview proration, confirm. */
export function ChangePlanDialog({
  subscription,
  initialPriceId,
  onOpenChange,
}: {
  subscription: Subscription
  initialPriceId?: string | null
  onOpenChange: (open: boolean) => void
}) {
  const currency = (subscription.price?.currency ?? 'INR') as 'INR' | 'USD'
  const { data } = usePlans(currency)
  const [selectedId, setSelectedId] = useState<string | null>(initialPriceId ?? null)
  const change = useBillingMutation(billingApi.changePlan)

  const options = (data?.data ?? []).flatMap((plan) =>
    (plan.prices ?? [])
      .filter((p) => p.interval !== 'lifetime' && p.id !== subscription.price?.id)
      .map((price) => ({ plan, price })),
  )
  const selected = options.find((o) => o.price.id === selectedId)

  const preview = useQuery({
    queryKey: ['billing', 'preview', selectedId],
    queryFn: () => billingApi.previewChange(selectedId!),
    enabled: !!selectedId,
  })

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>Change plan</DialogTitle>
          <DialogDescription>
            Upgrades start now (you pay the prorated difference). Downgrades start at your next renewal on{' '}
            {formatDate(subscription.current_period_end)} — you keep {subscription.plan.name} until then.
          </DialogDescription>
        </DialogHeader>

        <div role="radiogroup" aria-label="New plan" className="grid gap-2">
          {!data && <Skeleton className="h-24" />}
          {options.map(({ plan, price }) => (
            <button
              key={price.id}
              role="radio"
              aria-checked={selectedId === price.id}
              onClick={() => setSelectedId(price.id)}
              className={cn(
                'flex items-center justify-between rounded-lg border p-3 text-left text-sm transition-colors',
                selectedId === price.id
                  ? 'border-primary bg-primary/5 ring-primary ring-1'
                  : 'hover:bg-muted',
              )}
            >
              <span className="flex items-center gap-2 font-medium">
                {plan.tier_level > subscription.tier_level ? (
                  <ArrowUp className="text-accent size-4" aria-hidden />
                ) : plan.tier_level < subscription.tier_level ? (
                  <ArrowDown className="text-muted-foreground size-4" aria-hidden />
                ) : null}
                {plan.name} · {price.interval}ly
              </span>
              <span>
                {trimMoney(price.price.formatted)}
                {intervalSuffix[price.interval]}
              </span>
            </button>
          ))}
        </div>

        {selected && (
          <div className="rounded-lg border p-4 text-sm" aria-live="polite">
            {preview.isLoading && <Skeleton className="h-10" />}
            {preview.error && <Alert variant="destructive">{preview.error.message}</Alert>}
            {preview.data &&
              (preview.data.effective === 'now' ? (
                <dl className="grid gap-1">
                  <div className="flex justify-between">
                    <dt className="text-muted-foreground">Credit for unused time</dt>
                    <dd>− {formatMinor(preview.data.credit, preview.data.currency)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-muted-foreground">
                      {selected.plan.name} for the rest of this period
                    </dt>
                    <dd>{formatMinor(preview.data.charge, preview.data.currency)}</dd>
                  </div>
                  <div className="flex justify-between border-t pt-1 font-semibold">
                    <dt>Estimated due now</dt>
                    <dd>{formatMinor(preview.data.amount_due_now, preview.data.currency)}</dd>
                  </div>
                  <p className="text-muted-foreground mt-1 text-xs">
                    Final amount is calculated by the payment provider.
                  </p>
                </dl>
              ) : (
                <p>
                  Switches to <strong>{selected.plan.name}</strong> on {formatDate(preview.data.effective_at)}
                  . Nothing to pay today.
                </p>
              ))}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            disabled={!selected || !preview.data}
            loading={change.isPending}
            onClick={() =>
              change.mutate(selectedId!, {
                onSuccess: () => {
                  toast.success(preview.data?.effective === 'now' ? 'Plan upgraded' : 'Plan change scheduled')
                  onOpenChange(false)
                },
                onError: (e) => toast.error(e.message),
              })
            }
          >
            Confirm change
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
