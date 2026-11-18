import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  CheckoutResult,
  CouponQuote,
  Paginated,
  Payment,
  Plan,
  ProrationPreview,
  Subscription,
} from '@/types/api'

export type Currency = 'INR' | 'USD'

export const billingApi = {
  plans: async (currency?: Currency) =>
    (await http.get<{ data: Plan[]; meta: { currency: Currency } }>('/plans', { params: { currency } })).data,
  subscription: () => getData<Subscription | null>('/billing/subscription'),
  validateCoupon: async (plan_price_id: string, coupon_code: string) =>
    (await http.post<{ data: CouponQuote }>('/billing/coupons/validate', { plan_price_id, coupon_code })).data
      .data,
  checkout: async (plan_price_id: string, coupon_code?: string) =>
    (
      await http.post<{ data: CheckoutResult }>('/billing/checkout', {
        plan_price_id,
        coupon_code: coupon_code || undefined,
      })
    ).data.data,
  syncStripe: async (sessionId: string) =>
    (
      await http.get<{ data: { checkout_status: string; subscription: Subscription | null } }>(
        `/billing/checkout/stripe/${sessionId}`,
      )
    ).data.data,
  verifyRazorpay: async (body: Record<string, string>) =>
    (
      await http.post<{ data: { subscription: Subscription | null } }>(
        '/billing/checkout/razorpay/verify',
        body,
      )
    ).data.data,
  previewChange: async (plan_price_id: string) =>
    (await http.post<{ data: ProrationPreview }>('/billing/subscription/preview-change', { plan_price_id }))
      .data.data,
  changePlan: async (plan_price_id: string) =>
    (await http.post<{ data: Subscription }>('/billing/subscription/change', { plan_price_id })).data.data,
  cancel: async () => (await http.post<{ data: Subscription }>('/billing/subscription/cancel')).data.data,
  resume: async () => (await http.post<{ data: Subscription }>('/billing/subscription/resume')).data.data,
  portal: async () => (await http.get<{ data: { url: string } }>('/billing/portal')).data.data.url,
  invoices: async (page = 1) =>
    (await http.get<Paginated<Payment>>('/billing/invoices', { params: { page } })).data,
  downloadInvoice: async (payment: Payment) => {
    const res = await http.get<Blob>(`/billing/invoices/${payment.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `${payment.invoice_number ?? 'invoice'}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  },
  updateDetails: (body: {
    billing_name?: string | null
    tax_id?: string | null
    billing_address?: Record<string, string | null>
  }) => http.put('/billing/details', body),
}

/** Guests: guess currency from the browser timezone (India → INR / Razorpay). */
export function guessCurrency(): Currency {
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
  return tz === 'Asia/Kolkata' || tz === 'Asia/Calcutta' ? 'INR' : 'USD'
}

export const usePlans = (currency?: Currency) =>
  useQuery({
    queryKey: ['plans', currency ?? 'auto'],
    queryFn: () => billingApi.plans(currency),
    staleTime: 10 * 60_000,
  })

export const useSubscription = (enabled = true) =>
  useQuery({ queryKey: ['billing', 'subscription'], queryFn: billingApi.subscription, enabled })

export const useInvoices = (page: number) =>
  useQuery({ queryKey: ['billing', 'invoices', page], queryFn: () => billingApi.invoices(page) })

/** Mutations that change membership refresh billing data and the session user (tier). */
export function useBillingMutation<TVars, TData>(fn: (v: TVars) => Promise<TData>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['billing'] })
      void qc.invalidateQueries({ queryKey: ['me'] })
    },
  })
}
