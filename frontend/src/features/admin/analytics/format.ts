import { formatMinor } from '@/lib/format'

export const pct = (v: number | null | undefined, digits = 1) =>
  v === null || v === undefined
    ? '—'
    : `${(v * 100).toLocaleString(undefined, { maximumFractionDigits: digits })}%`

export const money = (minor: number | null | undefined, currency: string) =>
  minor === null || minor === undefined ? '—' : formatMinor(minor, currency)

/** Compact money for chart axes and dense tables: ₹1.2L / $12K style via Intl compact. */
export const moneyCompact = (minor: number, currency: string) =>
  new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 100)

export const hours = (seconds: number) =>
  `${(seconds / 3600).toLocaleString(undefined, { maximumFractionDigits: seconds < 36000 ? 1 : 0 })} h`

/** "2026-11-05" → "5 Nov", "2026-11" → "Nov 2026" */
export function periodLabel(period: string) {
  const [y, m, d] = period.split('-').map(Number)
  const date = new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1)
  return d
    ? date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
    : date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
}
