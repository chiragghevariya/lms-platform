import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const TONE: Record<string, string> = {
  active: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-700 dark:text-emerald-400',
  succeeded: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-700 dark:text-emerald-400',
  trialing: 'border-sky-600/40 bg-sky-600/10 text-sky-700 dark:text-sky-400',
  pending: 'border-sky-600/40 bg-sky-600/10 text-sky-700 dark:text-sky-400',
  incomplete: 'border-sky-600/40 bg-sky-600/10 text-sky-700 dark:text-sky-400',
  past_due: 'border-amber-600/40 bg-amber-500/10 text-amber-800 dark:text-amber-400',
  refunded: 'border-amber-600/40 bg-amber-500/10 text-amber-800 dark:text-amber-400',
  suspended: 'border-amber-600/40 bg-amber-500/10 text-amber-800 dark:text-amber-400',
  failed: 'border-destructive/40 bg-destructive/10 text-destructive',
  banned: 'border-destructive/40 bg-destructive/10 text-destructive',
  cancelled: '',
  expired: '',
}

/** Status pill: colour plus the status word, never colour alone. */
export function StatusBadge({ status, className }: { status: string; className?: string }) {
  return (
    <Badge variant="outline" className={cn('capitalize', TONE[status], className)}>
      {status.replace('_', ' ')}
    </Badge>
  )
}
