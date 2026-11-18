import { CreditCard, ShieldCheck, Tag } from 'lucide-react'
import { useState } from 'react'
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
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api-client'
import { intervalSuffix, trimMoney } from '@/lib/format'
import type { CouponQuote, Plan, PlanPrice } from '@/types/api'
import { billingApi } from '../api'
import { useCheckout } from '../useCheckout'

interface Props {
  plan: Plan | null
  price: PlanPrice | null
  initialCoupon?: string
  onOpenChange: (open: boolean) => void
}

/** Order summary + coupon field → hands off to Stripe Checkout or the Razorpay modal. */
export function CheckoutDialog({ plan, price, initialCoupon = '', onOpenChange }: Props) {
  const [code, setCode] = useState(initialCoupon)
  const [quote, setQuote] = useState<CouponQuote | null>(null)
  const [couponError, setCouponError] = useState<string | null>(null)
  const [checking, setChecking] = useState(false)
  const checkout = useCheckout()

  if (!plan || !price) return null

  const applyCoupon = async () => {
    setChecking(true)
    setCouponError(null)
    try {
      setQuote(await billingApi.validateCoupon(price.id, code.trim()))
    } catch (e) {
      setQuote(null)
      setCouponError(
        e instanceof ApiError ? (e.errors.coupon_code?.[0] ?? e.message) : 'Could not check that code.',
      )
    } finally {
      setChecking(false)
    }
  }

  const total = quote ? quote.final_price.formatted : price.price.formatted

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {plan.name} {price.interval === 'lifetime' ? 'lifetime' : `· billed ${price.interval}ly`}
          </DialogTitle>
          <DialogDescription>{plan.description}</DialogDescription>
        </DialogHeader>

        <dl className="grid gap-2 rounded-lg border p-4 text-sm">
          <div className="flex justify-between">
            <dt className="text-muted-foreground">Price</dt>
            <dd>
              {trimMoney(price.price.formatted)}
              {intervalSuffix[price.interval]}
            </dd>
          </div>
          {quote && (
            <div className="text-accent flex justify-between">
              <dt>Coupon {quote.code}</dt>
              <dd>− {trimMoney(quote.discount.formatted)}</dd>
            </div>
          )}
          <div className="flex justify-between border-t pt-2 font-semibold">
            <dt>{price.trial_days > 0 ? `Due after ${price.trial_days}-day trial` : 'Due today'}</dt>
            <dd>{trimMoney(total)}</dd>
          </div>
          {quote?.applies_to === 'first_payment' && (
            <p className="text-muted-foreground text-xs">
              Discount applies to your first payment; renewals are at the regular price.
            </p>
          )}
          <p className="text-muted-foreground text-xs">Prices include applicable taxes.</p>
        </dl>

        <form
          className="grid grid-cols-[1fr_auto] items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (code.trim()) void applyCoupon()
          }}
        >
          <FormField id="coupon" label="Coupon code" error={couponError ?? undefined}>
            {(a) => (
              <div className="relative">
                <Tag
                  className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                  aria-hidden
                />
                <Input
                  {...a}
                  className="pl-9 uppercase"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  autoComplete="off"
                />
              </div>
            )}
          </FormField>
          <Button type="submit" variant="outline" loading={checking} disabled={!code.trim()}>
            Apply
          </Button>
        </form>

        {checkout.isError &&
          !(checkout.error instanceof ApiError && checkout.error.code === 'already_subscribed') && (
            <Alert variant="destructive">{checkout.error.message}</Alert>
          )}

        <DialogFooter>
          <p className="text-muted-foreground mr-auto flex items-center gap-1.5 text-xs">
            {price.gateway === 'razorpay' ? (
              <>
                <ShieldCheck className="size-4" aria-hidden /> Razorpay · UPI, cards, netbanking
              </>
            ) : (
              <>
                <CreditCard className="size-4" aria-hidden /> Secure card payment via Stripe
              </>
            )}
          </p>
          <Button
            loading={checkout.isPending}
            onClick={() => checkout.mutate({ priceId: price.id, coupon: quote ? quote.code : undefined })}
          >
            Continue to payment
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
