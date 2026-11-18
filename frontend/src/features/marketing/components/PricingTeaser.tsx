import { ArrowRight, Check } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { guessCurrency, usePlans } from '@/features/billing/api'
import { trimMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'

export function PricingTeaser() {
  const user = useAuthStore((s) => s.user)
  const { data, isLoading } = usePlans(user ? undefined : guessCurrency())

  return (
    <>
      <div className="grid gap-6 md:grid-cols-3">
        {isLoading &&
          Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-56 rounded-xl" />)}
        {data?.data.map((plan) => {
          const monthly = plan.prices?.find((p) => p.interval === 'month')
          if (!monthly) return null
          return (
            <Card key={plan.id} className={cn(plan.tier === 'pro' && 'border-primary ring-primary ring-1')}>
              <CardHeader>
                <CardTitle>{plan.name}</CardTitle>
                <p>
                  <span className="text-3xl font-bold">{trimMoney(monthly.price.formatted)}</span>
                  <span className="text-muted-foreground"> /month</span>
                </p>
              </CardHeader>
              <CardContent>
                <ul className="grid gap-2 text-sm">
                  {plan.features
                    .filter((f) => f.value)
                    .slice(0, 3)
                    .map((f) => (
                      <li key={f.key} className="flex gap-2">
                        <Check className="text-accent mt-0.5 size-4 shrink-0" aria-hidden />
                        {typeof f.value === 'string' ? `${f.label}: ${f.value}` : f.label}
                      </li>
                    ))}
                </ul>
              </CardContent>
            </Card>
          )
        })}
      </div>
      <div className="mt-8 text-center">
        <Button asChild variant="outline">
          <Link to="/pricing">
            Compare all plans <ArrowRight aria-hidden />
          </Link>
        </Button>
      </div>
    </>
  )
}
