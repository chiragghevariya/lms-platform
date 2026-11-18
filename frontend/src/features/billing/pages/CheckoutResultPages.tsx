import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { CheckCircle2, Loader2, XCircle } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useRefreshSession } from '@/features/auth/hooks'
import { formatDate } from '@/lib/format'
import { billingApi } from '../api'

const Shell = ({ children }: { children: React.ReactNode }) => (
  <div className="flex min-h-[calc(100dvh-8rem)] items-center justify-center px-4 py-10">
    <Card className="w-full max-w-md text-center">{children}</Card>
  </div>
)

/**
 * Stripe returns here with ?session_id; we sync it server-side (webhook may lag) and poll briefly.
 * Razorpay arrives here after the signature was already verified.
 */
export function CheckoutSuccessPage() {
  const [params] = useSearchParams()
  const sessionId = params.get('session_id')
  const refresh = useRefreshSession()
  const qc = useQueryClient()
  const [polls, setPolls] = useState(0)
  const MAX_POLLS = 10

  const status = useQuery({
    queryKey: ['billing', 'checkout-status', sessionId ?? 'razorpay'],
    queryFn: async () => {
      setPolls((n) => n + 1)
      const sub = sessionId
        ? (await billingApi.syncStripe(sessionId)).subscription
        : await billingApi.subscription()
      if (sub?.is_active) {
        await refresh()
        void qc.invalidateQueries({ queryKey: ['billing', 'subscription'] })
      }
      return sub
    },
    refetchInterval: (q) => (q.state.data?.is_active || q.state.dataUpdateCount >= MAX_POLLS ? false : 2000),
  })

  const sub = status.data
  const active = !!sub?.is_active
  const gaveUp = !active && !status.isFetching && polls >= MAX_POLLS

  return (
    <Shell>
      <Seo title="Payment successful" noindex />
      <CardHeader className="items-center">
        {active ? (
          <CheckCircle2 className="text-accent size-12" aria-hidden />
        ) : (
          <Loader2 className="text-primary size-12 animate-spin" aria-hidden />
        )}
        <CardTitle className="text-2xl" role="status" aria-live="polite">
          {active ? `Welcome to ${sub.plan.name}!` : gaveUp ? 'Payment received' : 'Confirming your payment…'}
        </CardTitle>
        <CardDescription>
          {active
            ? sub.is_lifetime
              ? 'You have lifetime access.'
              : sub.status === 'trialing'
                ? `Your free trial runs until ${formatDate(sub.trial_ends_at ?? sub.current_period_end)}.`
                : `Your membership renews on ${formatDate(sub.current_period_end)}.`
            : gaveUp
              ? "It's taking a little longer than usual. Your access will appear within a few minutes — no need to pay again."
              : 'This usually takes a few seconds.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <Button asChild>
          <Link to="/app">Start learning</Link>
        </Button>
        <Button asChild variant="outline">
          <Link to="/app/billing">View billing</Link>
        </Button>
      </CardContent>
    </Shell>
  )
}

export function CheckoutFailedPage() {
  const [params] = useSearchParams()
  const reason = params.get('reason')

  return (
    <Shell>
      <Seo title="Payment not completed" noindex />
      <CardHeader className="items-center">
        <XCircle className="text-destructive size-12" aria-hidden />
        <CardTitle className="text-2xl">Payment not completed</CardTitle>
        <CardDescription>
          {reason ?? 'The payment was cancelled or declined.'} You have not been charged.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <Button asChild>
          <Link to="/pricing">Try again</Link>
        </Button>
        <Button asChild variant="outline">
          <Link to="/contact">Contact support</Link>
        </Button>
      </CardContent>
    </Shell>
  )
}
