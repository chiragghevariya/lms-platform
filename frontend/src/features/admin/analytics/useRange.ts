import { useSearchParams } from 'react-router-dom'
import type { RangeParams } from './api'

export const PRESETS = [
  { days: 7, label: '7 days' },
  { days: 30, label: '30 days' },
  { days: 90, label: '90 days' },
  { days: 365, label: '12 months' },
] as const

/** Local calendar date (YYYY-MM-DD) n days before today. */
export function isoDaysAgo(days: number, from = new Date()) {
  const d = new Date(from)
  d.setDate(d.getDate() - days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Range + currency live in the URL so a dashboard view can be shared or bookmarked. */
export function useRange(defaultCurrency = 'INR') {
  const [params, setParams] = useSearchParams()
  const to = params.get('to') ?? isoDaysAgo(0)
  const from = params.get('from') ?? isoDaysAgo(29)
  const currency = params.get('currency') ?? defaultCurrency
  const range: RangeParams = { from, to, currency }

  const update = (patch: Partial<RangeParams>) =>
    setParams(
      (p) => {
        const next = new URLSearchParams(p)
        Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)))
        return next
      },
      { replace: true },
    )

  return { range, update }
}
