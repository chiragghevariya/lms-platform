import { Lock } from 'lucide-react'
import type { ReactNode } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { canAccessTier, tierLabel } from '@/lib/membership'
import { UpgradeCta } from './LockBadge'
import { useAuthStore } from '@/stores/auth-store'
import type { MembershipTier } from '@/types/api'

/** Renders children only when the user's tier (or staff role) satisfies `tier`; otherwise an upgrade prompt. */
export function TierGate({
  tier,
  children,
  fallback,
}: {
  tier: MembershipTier
  children: ReactNode
  fallback?: ReactNode
}) {
  const user = useAuthStore((s) => s.user)
  if (canAccessTier(user, tier)) return <>{children}</>
  if (fallback !== undefined) return <>{fallback}</>
  return <UpgradePrompt tier={tier} />
}

export function UpgradePrompt({ tier }: { tier: MembershipTier }) {
  return (
    <Card className="border-dashed">
      <CardHeader className="items-center text-center">
        <span className="bg-secondary text-secondary-foreground grid size-12 place-items-center rounded-full">
          <Lock aria-hidden />
        </span>
        <CardTitle>{tierLabel(tier)} membership required</CardTitle>
        <CardDescription>Upgrade to {tierLabel(tier)} or higher to unlock this content.</CardDescription>
      </CardHeader>
      <CardContent className="flex justify-center">
        <UpgradeCta tier={tier} size="default" />
      </CardContent>
    </Card>
  )
}
