/** "12.5" → 1250 (minor units); null when not a valid positive amount with ≤ 2 decimals. */
export function toMinor(major: string): number | null {
  const v = major.trim().replace(/,/g, '')
  if (!/^\d+(\.\d{1,2})?$/.test(v)) return null
  const minor = Math.round(Number(v) * 100)
  return minor > 0 ? minor : null
}

export const TIER_NAMES: Record<number, string> = { 1: 'Starter', 2: 'Pro', 3: 'Elite' }
