import { AlertTriangle, CalendarClock, CreditCard, Crown, Gift } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
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
import { Skeleton } from '@/components/ui/skeleton'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatDate, intervalSuffix, trimMoney } from '@/lib/format'
import type { Subscription } from '@/types/api'
import { billingApi, useBillingMutation, useSubscription } from '../api'
import { BillingDetailsCard } from '../components/BillingDetailsCard'
import { ChangePlanDialog } from '../components/ChangePlanDialog'
import { InvoicesCard } from '../components/InvoicesCard'

function StatusBadge({ sub }: { sub: Subscription }) {
  if (sub.is_lifetime) return <Badge variant="elite">Lifetime</Badge>
  if (sub.is_complimentary) return <Badge variant="secondary">Complimentary</Badge>
  if (sub.status === 'past_due')
    return (
      <Badge variant="outline" className="text-destructive">
        Payment failed
      </Badge>
    )
  if (sub.status === 'trialing') return <Badge variant="secondary">Free trial</Badge>
  if (sub.cancel_at_period_end) return <Badge variant="outline">Cancels at period end</Badge>
  if (!sub.is_active) return <Badge variant="outline">{sub.status}</Badge>
  return <Badge>Active</Badge>
}

export default function BillingPage() {
  const [params, setParams] = useSearchParams()
  const { data: sub, isLoading } = useSubscription()
  const [changing, setChanging] = useState<boolean>(!!params.get('change'))
  const [confirmCancel, setConfirmCancel] = useState(false)
  const cancel = useBillingMutation(billingApi.cancel)
  const resume = useBillingMutation(billingApi.resume)
  const keepPlan = useBillingMutation(billingApi.changePlan)
  const portal = useBillingMutation(billingApi.portal)

  const manageable = !!sub && sub.is_active && !sub.is_lifetime && !sub.is_complimentary
  const pastDue = sub?.status === 'past_due'

  return (
    <>
      <Seo title="Billing" noindex />
      <PageHeader title="Billing" description="Your membership, payments and invoices." />

      {isLoading ? (
        <Skeleton className="h-48" />
      ) : !sub || (!sub.is_active && !pastDue) ? (
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>No active membership</CardTitle>
            <CardDescription>
              {sub
                ? `Your ${sub.plan.name} membership ended on ${formatDate(sub.ended_at)}.`
                : 'Choose a plan to unlock courses and live classes.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button asChild>
              <Link to="/pricing">See plans</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="mb-6 grid gap-4">
          {pastDue && (
            <Alert variant="destructive" className="flex gap-3">
              <AlertTriangle className="size-5 shrink-0" aria-hidden />
              <div>
                <p className="font-semibold">We couldn't renew your membership.</p>
                <p>
                  We'll retry automatically. Update your payment method before {formatDate(sub.grace_ends_at)}{' '}
                  to keep access.
                </p>
              </div>
            </Alert>
          )}

          <Card>
            <CardHeader className="flex-row flex-wrap items-start justify-between gap-4">
              <div className="grid gap-1">
                <CardDescription>Current plan</CardDescription>
                <CardTitle className="flex flex-wrap items-center gap-2 text-2xl">
                  {sub.is_lifetime ? (
                    <Crown className="text-tier-elite size-5" aria-hidden />
                  ) : sub.is_complimentary ? (
                    <Gift className="size-5" aria-hidden />
                  ) : null}
                  {sub.plan.name}
                  <TierBadge tier={sub.tier} />
                  <StatusBadge sub={sub} />
                </CardTitle>
                {sub.price && (
                  <p className="text-muted-foreground">
                    {trimMoney(sub.price.price.formatted)}
                    {intervalSuffix[sub.price.interval]} · via{' '}
                    {sub.gateway === 'stripe'
                      ? 'Stripe'
                      : sub.gateway === 'razorpay'
                        ? 'Razorpay'
                        : 'our team'}
                  </p>
                )}
              </div>
              {manageable && !sub.cancel_at_period_end && (
                <Button onClick={() => setChanging(true)}>Change plan</Button>
              )}
            </CardHeader>
            <CardContent className="grid gap-4">
              {!sub.is_lifetime && sub.current_period_end && (
                <p className="flex items-center gap-2 text-sm">
                  <CalendarClock className="text-muted-foreground size-4" aria-hidden />
                  {sub.cancel_at_period_end || sub.is_complimentary
                    ? `Access until ${formatDate(sub.current_period_end)}`
                    : sub.status === 'trialing'
                      ? `Trial ends ${formatDate(sub.trial_ends_at ?? sub.current_period_end)} — first charge then`
                      : `Renews ${formatDate(sub.current_period_end)}`}
                </p>
              )}

              {sub.scheduled_change?.plan && (
                <Alert variant="info" className="flex flex-wrap items-center justify-between gap-3">
                  <span>
                    Switching to <strong>{sub.scheduled_change.plan.name}</strong> on{' '}
                    {formatDate(sub.scheduled_change.effective_at)}.
                  </span>
                  <Button
                    size="sm"
                    variant="outline"
                    loading={keepPlan.isPending}
                    onClick={() =>
                      sub.price &&
                      keepPlan.mutate(sub.price.id, {
                        onSuccess: () => toast.success(`You'll stay on ${sub.plan.name}`),
                        onError: (e) => toast.error(e.message),
                      })
                    }
                  >
                    Keep {sub.plan.name}
                  </Button>
                </Alert>
              )}

              {manageable && (
                <div className="flex flex-wrap gap-2">
                  {sub.gateway === 'stripe' && (
                    <Button
                      variant="outline"
                      loading={portal.isPending}
                      onClick={() =>
                        portal.mutate(undefined, {
                          onSuccess: (url) => window.location.assign(url),
                          onError: (e) => toast.error(e.message),
                        })
                      }
                    >
                      <CreditCard aria-hidden /> Update card
                    </Button>
                  )}
                  {sub.cancel_at_period_end ? (
                    sub.can_resume ? (
                      <Button
                        variant="accent"
                        loading={resume.isPending}
                        onClick={() =>
                          resume.mutate(undefined, {
                            onSuccess: () => toast.success('Welcome back! Your membership will renew.'),
                            onError: (e) => toast.error(e.message),
                          })
                        }
                      >
                        Resume membership
                      </Button>
                    ) : (
                      <p className="text-muted-foreground text-sm">
                        Cancellation is final this close to renewal. You can subscribe again after it ends.
                      </p>
                    )
                  ) : (
                    <Button
                      variant="ghost"
                      className="text-destructive"
                      onClick={() => setConfirmCancel(true)}
                    >
                      Cancel membership
                    </Button>
                  )}
                </div>
              )}
              {sub.gateway === 'razorpay' && manageable && (
                <p className="text-muted-foreground text-xs">
                  To change the card or UPI mandate used by Razorpay, cancel and subscribe again, or contact
                  support.
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      )}

      <div className="grid gap-6">
        <InvoicesCard />
        <BillingDetailsCard />
      </div>

      {changing && sub && manageable && (
        <ChangePlanDialog
          subscription={sub}
          initialPriceId={params.get('change')}
          onOpenChange={(open) => {
            setChanging(open)
            if (!open && params.get('change')) setParams({}, { replace: true })
          }}
        />
      )}

      <Dialog open={confirmCancel} onOpenChange={setConfirmCancel}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel your membership?</DialogTitle>
            <DialogDescription>
              You'll keep {sub?.plan.name} until {formatDate(sub?.current_period_end)}. After that your access
              ends and you won't be charged again.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmCancel(false)}>
              Keep membership
            </Button>
            <Button
              variant="destructive"
              loading={cancel.isPending}
              onClick={() =>
                cancel.mutate(undefined, {
                  onSuccess: () => {
                    toast.success('Your membership will end at the end of this period.')
                    setConfirmCancel(false)
                  },
                  onError: (e) => toast.error(e.message),
                })
              }
            >
              Cancel at period end
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
