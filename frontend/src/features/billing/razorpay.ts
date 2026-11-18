import type { RazorpayCheckoutPayload } from '@/types/api'

interface RazorpayResponse {
  razorpay_payment_id: string
  razorpay_signature: string
  razorpay_subscription_id?: string
  razorpay_order_id?: string
}

interface RazorpayInstance {
  open: () => void
  on: (event: 'payment.failed', cb: (resp: { error: { description?: string } }) => void) => void
}

declare global {
  interface Window {
    Razorpay?: new (options: Record<string, unknown>) => RazorpayInstance
  }
}

const SCRIPT_SRC = 'https://checkout.razorpay.com/v1/checkout.js'
let loading: Promise<void> | null = null

export function loadRazorpay(): Promise<void> {
  if (window.Razorpay) return Promise.resolve()
  loading ??= new Promise<void>((resolve, reject) => {
    const s = document.createElement('script')
    s.src = SCRIPT_SRC
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => {
      loading = null
      reject(new Error('Could not load Razorpay. Check your connection and try again.'))
    }
    document.body.appendChild(s)
  })
  return loading
}

/** Opens the Razorpay Checkout modal; resolves with the signed handler response, rejects on failure/dismiss. */
export async function openRazorpay(payload: RazorpayCheckoutPayload): Promise<RazorpayResponse> {
  await loadRazorpay()
  return new Promise((resolve, reject) => {
    const rzp = new window.Razorpay!({
      ...payload,
      theme: { color: '#4f46e5' },
      handler: (resp: RazorpayResponse) => resolve(resp),
      modal: { ondismiss: () => reject(new DOMException('Payment cancelled', 'AbortError')) },
    })
    rzp.on('payment.failed', (resp) => reject(new Error(resp.error.description ?? 'Payment failed')))
    rzp.open()
  })
}
