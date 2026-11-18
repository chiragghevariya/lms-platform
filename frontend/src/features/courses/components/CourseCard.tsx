import { Clock, PlayCircle } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { prefetchCourse } from '../api'
import { Link } from 'react-router-dom'
import { Stars } from '@/components/StarRating'
import { Card, CardContent } from '@/components/ui/card'
import { LockBadge, UpgradeCta } from '@/features/membership/components/LockBadge'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatMinutes } from '@/lib/format'
import { canAccessTier } from '@/lib/membership'
import { useAuthStore } from '@/stores/auth-store'
import type { CourseCard as Course } from '@/types/courses'

export function CourseCard({
  course: c,
  headingLevel = 'h3',
}: {
  course: Course
  headingLevel?: 'h2' | 'h3'
}) {
  const user = useAuthStore((s) => s.user)
  const unlocked = c.has_access ?? canAccessTier(user, c.tier)
  const Heading = headingLevel
  const qc = useQueryClient()

  return (
    <Card className="group relative flex flex-col overflow-hidden transition-shadow hover:shadow-md">
      <div className="bg-muted relative aspect-video">
        {c.thumbnail_url ? (
          <img
            src={c.thumbnail_url}
            srcSet={c.thumbnail_srcset ?? undefined}
            sizes="(min-width: 1024px) 280px, (min-width: 640px) 50vw, 100vw"
            width={480}
            height={270}
            alt=""
            loading="lazy"
            decoding="async"
            className="size-full object-cover"
          />
        ) : (
          <div aria-hidden className="from-primary/70 to-accent/60 size-full bg-gradient-to-br" />
        )}
        {c.tier && !unlocked && <LockBadge tier={c.tier} className="absolute top-3 right-3" />}
      </div>
      <CardContent className="flex flex-1 flex-col gap-2 p-4">
        <div className="flex items-center justify-between gap-2">
          <span className="text-muted-foreground truncate text-xs font-medium">{c.category?.name}</span>
          {c.tier ? (
            <TierBadge tier={c.tier} />
          ) : (
            <span className="text-accent text-xs font-semibold">Free</span>
          )}
        </div>
        <Heading className="leading-snug font-semibold">
          <Link
            to={`/courses/${c.slug}`}
            onMouseEnter={() => prefetchCourse(qc, c.slug)}
            onFocus={() => prefetchCourse(qc, c.slug)}
            className="after:absolute after:inset-0 focus-visible:outline-none"
          >
            {c.title}
          </Link>
        </Heading>
        <p className="text-muted-foreground text-sm">{c.instructor.name}</p>
        <div className="mt-auto flex items-center gap-3 pt-2 text-xs">
          {c.rating_count > 0 && (
            <span className="flex items-center gap-1">
              <strong>{c.rating_avg.toFixed(1)}</strong>{' '}
              <Stars value={c.rating_avg} className="[&_svg]:size-3" />
            </span>
          )}
          <span className="text-muted-foreground flex items-center gap-1">
            <Clock className="size-3" aria-hidden /> {formatMinutes(c.duration_minutes)}
          </span>
          <span className="text-muted-foreground flex items-center gap-1">
            <PlayCircle className="size-3" aria-hidden /> {c.lessons_count}
          </span>
        </div>
        {user && c.tier && !unlocked && (
          // Sits above the card-wide link overlay so it stays clickable.
          <UpgradeCta tier={c.tier} className="relative z-10 mt-2 justify-self-start" />
        )}
      </CardContent>
    </Card>
  )
}
