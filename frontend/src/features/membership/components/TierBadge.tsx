import { Crown } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { tierLabel } from '@/lib/membership'
import type { MembershipTier } from '@/types/api'

/** `className` lets inverted/hero surfaces use a solid, high-contrast pill. */
export function TierBadge({ tier, className }: { tier: MembershipTier | null; className?: string }) {
  if (!tier)
    return (
      <Badge variant="outline" className={className}>
        Free
      </Badge>
    )
  return (
    <Badge variant={tier} className={className}>
      {tier === 'elite' && <Crown className="mr-1 size-3" aria-hidden />}
      {tierLabel(tier)}
    </Badge>
  )
}
