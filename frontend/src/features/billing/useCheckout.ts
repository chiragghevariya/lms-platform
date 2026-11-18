import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { track } from '@/features/cms/tracking'
import { ApiError } from '@/lib/api-client'
import { billingApi } from './api'
import { openRazorpay } from './razorpay'

/**
 * Starts checkout for a price: Stripe → full-page redirect to Checkout;
 * Razorpay → modal, then server-side signature verification, then the success page.
 */
export function useCheckout() {
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async ({ priceId, coupon }: { priceId: string; coupon?: string }) => {
      track('begin_checkout', { items: [{ item_id: priceId }], coupon })
      const result = await billingApi.checkout(priceId, coupon)

      if (result.action === 'redirect' && result.redirect_url) {
        window.location.assign(result.redirect_url)
        return
      }

      if (result.razorpay) {
        try {
          const resp = await openRazorpay(result.razorpay)
          await billingApi.verifyRazorpay({ ...resp })
          navigate('/billing/success?gateway=razorpay', { replace: true })
        } catch (e) {
          if (e instanceof DOMException && e.name === 'AbortError') {
            toast.info('Payment cancelled. You have not been charged.')
            return
          }
          navigate(
            `/billing/failed?reason=${encodeURIComponent(e instanceof Error ? e.message : 'Payment failed')}`,
          )
        }
      }
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'already_subscribed') {
        toast.info(e.message, {
          action: { label: 'Manage billing', onClick: () => navigate('/app/billing') },
        })
      }
      // Other errors are shown inline by the checkout dialog.
    },
  })
}
