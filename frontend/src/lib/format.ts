import type { PriceInterval } from '@/types/api'

export const formatDate = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'

/** "₹999.00" → "₹999" */
export const trimMoney = (formatted: string) => formatted.replace(/\.00$/, '')

export const intervalSuffix: Record<PriceInterval, string> = {
  month: '/month',
  year: '/year',
  lifetime: ' once',
}

export function formatMinor(amount: number, currency: string) {
  return trimMoney(
    new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', { style: 'currency', currency }).format(
      amount / 100,
    ),
  )
}

/** 754 → "12:34", 3725 → "1:02:05" */
export function formatClock(totalSeconds: number) {
  const s = Math.max(0, Math.floor(totalSeconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
}

/** 95 → "1h 35m", 40 → "40m" */
export function formatMinutes(minutes: number) {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h ? `${h}h${m ? ` ${m}m` : ''}` : `${m}m`
}

export function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

/** "2026-10" → "October 2026" (UTC, so the month never shifts with the viewer's timezone). */
export const monthName = (period: string) =>
  new Date(`${period}-01T00:00:00Z`).toLocaleDateString(undefined, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  })
