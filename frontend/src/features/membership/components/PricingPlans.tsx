import { Check } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { type Currency, guessCurrency, usePlans, useSubscription } from '@/features/billing/api'
import { CheckoutDialog } from '@/features/billing/components/CheckoutDialog'
import { intervalSuffix, trimMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import type { Plan, PlanPrice, PriceInterval } from '@/types/api'
import { ComparisonTable } from './ComparisonTable'

const INTERVAL_LABEL: Record<PriceInterval, string> = {
  month: 'Monthly',
  year: 'Yearly',
  lifetime: 'Lifetime',
}

export function PricingPlans({ showComparison = true }: { showComparison?: boolean }) {
  const user = useAuthStore((s) => s.user)
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const location = useLocation()
  const [currency, setCurrency] = useState<Currency | undefined>(user ? undefined : guessCurrency())
  const [interval, setInterval] = useState<PriceInterval>('month')
  const [selected, setSelected] = useState<{ plan: Plan; price: PlanPrice } | null>(null)
  const { data, isLoading, isError } = usePlans(currency)
  const { data: current } = useSubscription(!!user)
  const highlight = params.get('highlight')

  const plans = useMemo(() => data?.data ?? [], [data])
  const activeCurrency = data?.meta.currency ?? currency ?? 'INR'
  const intervals = (['month', 'year', 'lifetime'] as const).filter((i) =>
    plans.some((p) => p.prices?.some((pr) => pr.interval === i)),
  )
  const visible = plans
    .map((plan) => ({ plan, price: plan.prices?.find((p) => p.interval === interval) }))
    .filter((x): x is { plan: Plan; price: PlanPrice } => !!x.price)

  if (isError) return <p className="text-destructive text-center">Could not load plans. Please refresh.</p>

  const choose = (plan: Plan, price: PlanPrice) => {
    if (!user) {
      navigate('/login', { state: { from: location.pathname + location.search } })
      return
    }
    if (
      current?.is_active &&
      !current.is_lifetime &&
      !current.is_complimentary &&
      price.interval !== 'lifetime'
    ) {
      navigate(`/app/billing?change=${price.id}`)
      return
    }
    setSelected({ plan, price })
  }

  return (
    <div className="grid gap-10">
      <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
        <div
          role="radiogroup"
          aria-label="Billing interval"
          className="bg-muted inline-flex rounded-full p-1"
        >
          {intervals.map((i) => (
            <button
              key={i}
              role="radio"
              aria-checked={interval === i}
              onClick={() => setInterval(i)}
              className={cn(
                'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                interval === i ? 'bg-background shadow-sm' : 'text-muted-foreground',
              )}
            >
              {INTERVAL_LABEL[i]}
              {i === 'year' && <span className="text-accent ml-1 text-xs font-semibold">2 months free</span>}
            </button>
          ))}
        </div>
        <label className="text-muted-foreground flex items-center gap-2 text-sm">
          Currency
          <select
            className="border-input bg-background h-9 rounded-md border px-2"
            value={activeCurrency}
            onChange={(e) => setCurrency(e.target.value as Currency)}
          >
            <option value="INR">₹ INR</option>
            <option value="USD">$ USD</option>
          </select>
        </label>
      </div>

      <div className={cn('grid gap-6', visible.length >= 3 ? 'md:grid-cols-3' : 'mx-auto w-full max-w-md')}>
        {isLoading &&
          Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-96 rounded-xl" />)}
        {visible.map(({ plan, price }) => {
          const isCurrent = current?.is_active && current.price?.id === price.id
          const featured = highlight ? plan.tier === highlight : plan.tier === 'pro'
          return (
            <Card
              key={price.id}
              className={cn('flex flex-col', featured && 'border-primary ring-primary ring-1')}
            >
              <CardHeader>
                <div className="flex items-center justify-between gap-2">
                  <CardTitle>{plan.name}</CardTitle>
                  {featured && <Badge>{highlight ? 'Unlocks this' : 'Most popular'}</Badge>}
                </div>
                <CardDescription>{plan.description}</CardDescription>
                <p className="mt-2">
                  <span className="text-4xl font-bold">{trimMoney(price.price.formatted)}</span>
                  <span className="text-muted-foreground">{intervalSuffix[price.interval]}</span>
                </p>
                {price.trial_days > 0 && (
                  <p className="text-accent text-sm font-medium">{price.trial_days}-day free trial</p>
                )}
              </CardHeader>
              <CardContent className="flex-1">
                <ul className="grid gap-2 text-sm">
                  {plan.features
                    .filter((f) => f.value)
                    .map((f) => (
                      <li key={f.key} className="flex gap-2">
                        <Check className="text-accent mt-0.5 size-4 shrink-0" aria-hidden />
                        {typeof f.value === 'string' ? `${f.label}: ${f.value}` : f.label}
                      </li>
                    ))}
                </ul>
              </CardContent>
              <CardFooter>
                {isCurrent ? (
                  <Button variant="secondary" className="w-full" asChild>
                    <Link to="/app/billing">Current plan · Manage</Link>
                  </Button>
                ) : (
                  <Button
                    variant={featured ? 'default' : 'outline'}
                    className="w-full"
                    onClick={() => choose(plan, price)}
                  >
                    {current?.is_active && price.interval !== 'lifetime'
                      ? 'Switch to this plan'
                      : `Choose ${plan.name}`}
                  </Button>
                )}
              </CardFooter>
            </Card>
          )
        })}
      </div>

      {showComparison && plans.length > 1 && (
        <section aria-labelledby="compare-heading" className="grid gap-4">
          <h2 id="compare-heading" className="text-center text-2xl font-bold">
            Compare tiers
          </h2>
          <ComparisonTable plans={plans} />
        </section>
      )}

      <CheckoutDialog
        key={selected?.price.id ?? 'none'}
        plan={selected?.plan ?? null}
        price={selected?.price ?? null}
        initialCoupon={params.get('coupon') ?? ''}
        onOpenChange={(open) => !open && setSelected(null)}
      />
    </div>
  )
}
