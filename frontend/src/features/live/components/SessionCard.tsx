import { Clock, Repeat, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { cn } from '@/lib/utils'
import type { LiveSession } from '@/types/live'
import { formatSessionTime } from '../api'
import { SessionActions } from './SessionActions'

export function SessionCard({ session: s }: { session: LiveSession }) {
  const start = new Date(s.starts_at)
  return (
    <Card className={cn(s.status === 'cancelled' && 'opacity-70')}>
      <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
        <div
          className="bg-secondary text-secondary-foreground grid w-16 shrink-0 place-items-center rounded-lg py-2 text-center"
          aria-hidden
        >
          <span className="text-xs uppercase">{start.toLocaleDateString(undefined, { month: 'short' })}</span>
          <span className="text-2xl leading-none font-bold">{start.getDate()}</span>
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Link to={`/live/${s.id}`} className="font-semibold hover:underline">
              {s.title}
            </Link>
            {s.status === 'live' && (
              <Badge className="bg-destructive text-white">
                <span
                  className="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-white"
                  aria-hidden
                />{' '}
                Live
              </Badge>
            )}
            <TierBadge tier={s.required_tier} />
          </div>
          <p className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span className="inline-flex items-center gap-1">
              <Clock className="size-3.5" aria-hidden />
              {formatSessionTime(s.starts_at)} · {s.duration} min
            </span>
            <span>{s.type_label}</span>
            <span>with {s.instructor.name}</span>
            {s.is_series && (
              <span className="inline-flex items-center gap-1">
                <Repeat className="size-3.5" aria-hidden /> Weekly
              </span>
            )}
            {s.seats_left !== null && (
              <span className="inline-flex items-center gap-1">
                <Users className="size-3.5" aria-hidden /> {s.seats_left} seat{s.seats_left === 1 ? '' : 's'}{' '}
                left
              </span>
            )}
          </p>
          {s.course && <p className="text-muted-foreground text-xs">Part of {s.course.title}</p>}
        </div>
        <SessionActions session={s} compact />
      </CardContent>
    </Card>
  )
}
