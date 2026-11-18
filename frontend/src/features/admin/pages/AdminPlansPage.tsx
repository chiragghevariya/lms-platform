import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { intervalSuffix, trimMoney } from '@/lib/format'
import { useAdminPlans, useTogglePrice } from '../api'

export default function AdminPlansPage() {
  const { data, isLoading } = useAdminPlans()
  const toggle = useTogglePrice()

  return (
    <>
      <PageHeader
        title="Membership plans"
        description="INR prices bill via Razorpay, others via Stripe. A price needs its gateway id before it can be sold."
      />
      {isLoading && <Skeleton className="h-64" />}
      <div className="grid gap-6">
        {data?.map((plan) => (
          <Card key={plan.id}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                {plan.name} <TierBadge tier={plan.tier} />
                {!plan.is_active && <Badge variant="outline">Inactive</Badge>}
              </CardTitle>
              <CardDescription>{plan.description}</CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-muted-foreground">
                  <tr>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Price
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Gateway id
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Trial
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Status
                    </th>
                    <th scope="col" className="py-2">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {plan.prices?.map((price) => {
                    const gatewayId =
                      price.gateway === 'stripe' ? price.stripe_price_id : price.razorpay_plan_id
                    const needsId =
                      !gatewayId && !(price.gateway === 'razorpay' && price.interval === 'lifetime')
                    return (
                      <tr key={price.id}>
                        <td className="py-2 pr-4">
                          {trimMoney(price.price.formatted)}
                          {intervalSuffix[price.interval]}
                        </td>
                        <td className="py-2 pr-4 font-mono text-xs">
                          {gatewayId ??
                            (needsId ? (
                              <span className="text-destructive">missing ({price.gateway})</span>
                            ) : (
                              'Orders API'
                            ))}
                        </td>
                        <td className="py-2 pr-4">{price.trial_days ? `${price.trial_days} days` : '—'}</td>
                        <td className="py-2 pr-4">
                          <Badge variant={price.is_active ? 'default' : 'outline'}>
                            {price.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </td>
                        <td className="py-2 text-right">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={toggle.isPending}
                            onClick={() =>
                              toggle.mutate(
                                { plan, price },
                                {
                                  onError: (e) => toast.error(e.message),
                                  onSuccess: () => toast.success('Price updated'),
                                },
                              )
                            }
                          >
                            {price.is_active ? 'Deactivate' : 'Activate'}
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </CardContent>
          </Card>
        ))}
      </div>
    </>
  )
}
