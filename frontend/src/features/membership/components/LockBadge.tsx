import { Lock } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { tierLabel } from '@/lib/membership'
import { cn } from '@/lib/utils'
import type { MembershipTier } from '@/types/api'

/** Small "locked" pill for gated items (course cards, lesson rows, live classes). */
export function LockBadge({ tier, className }: { tier: MembershipTier; className?: string }) {
  return (
    <span
      className={cn(
        'bg-foreground/85 text-background inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
        className,
      )}
    >
      <Lock className="size-3" aria-hidden />
      <span>
        <span className="sr-only">Locked — requires </span>
        {tierLabel(tier)}
      </span>
    </span>
  )
}

/** "Upgrade to Pro" call to action that leads to pricing. */
export function UpgradeCta({
  tier,
  size = 'sm',
  className,
}: {
  tier: MembershipTier
  size?: 'sm' | 'default'
  className?: string
}) {
  return (
    <Button asChild size={size} variant="accent" className={className}>
      <Link to={`/pricing?highlight=${tier}`}>Upgrade to {tierLabel(tier)}</Link>
    </Button>
  )
}
