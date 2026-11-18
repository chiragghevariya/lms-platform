import { ArrowUpRight, Award, CalendarClock, ClipboardList, Flame, PlayCircle, Star } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { ProgressRing } from '@/components/ProgressRing'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { VerifyEmailBanner } from '@/features/auth/components/VerifyEmailBanner'
import { CourseCard } from '@/features/courses/components/CourseCard'
import { useDashboard } from '@/features/learning/api'
import { BadgeIcon } from '@/features/learning/components/BadgeIcon'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth-store'

export default function StudentDashboardPage() {
  const user = useAuthStore((s) => s.user)!
  const firstName = user.name.split(' ')[0]
  const { data, isLoading } = useDashboard()
  const streak = data?.stats.streak

  return (
    <>
      <Seo title="Dashboard" noindex />
      <VerifyEmailBanner />
      <PageHeader title={`Hi ${firstName} 👋`} description="Here's where your learning stands today." />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat
          icon={<Flame className="size-5" aria-hidden />}
          value={isLoading ? null : `${streak?.current ?? 0} day${streak?.current === 1 ? '' : 's'}`}
          label={streak?.active_today ? 'Streak · done today ✓' : 'Streak · learn today to keep it'}
        />
        <Stat
          icon={<Star className="size-5" aria-hidden />}
          value={isLoading ? null : String(data?.stats.points ?? 0)}
          label="Points"
        />
        <Stat
          icon={<PlayCircle className="size-5" aria-hidden />}
          value={isLoading ? null : String(data?.continue_learning.length ?? 0)}
          label="Courses in progress"
        />
        <Stat
          icon={<Award className="size-5" aria-hidden />}
          value={isLoading ? null : String(data?.stats.certificates ?? 0)}
          label="Certificates"
          to="/app/certificates"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <section aria-labelledby="continue-h" className="grid gap-3 self-start lg:col-span-2">
          <h2 id="continue-h" className="text-lg font-semibold">
            Continue learning
          </h2>
          {isLoading && <Skeleton className="h-24" />}
          {data?.continue_learning.length === 0 && (
            <Card className="border-dashed">
              <CardContent className="text-muted-foreground p-8 text-center text-sm">
                Nothing in progress.{' '}
                <Link to="/courses" className="text-primary underline">
                  Pick a course
                </Link>{' '}
                to get started.
              </CardContent>
            </Card>
          )}
          {data?.continue_learning.map((c) => (
            <Card key={c.course.id}>
              <CardContent className="flex items-center gap-4 p-4">
                <ProgressRing value={c.percent} label={`${c.course.title} progress`} />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold">{c.course.title}</p>
                  <p className="text-muted-foreground text-sm">
                    {c.completed_lessons} of {c.total_lessons} lessons
                    {c.last_activity_at && ` · last active ${formatDate(c.last_activity_at)}`}
                  </p>
                </div>
                <Button asChild size="sm">
                  <Link
                    to={
                      c.next_lesson_id
                        ? `/learn/${c.course.slug}/${c.next_lesson_id}`
                        : `/courses/${c.course.slug}`
                    }
                    aria-label={`Resume ${c.course.title}`}
                  >
                    Resume
                  </Link>
                </Button>
              </CardContent>
            </Card>
          ))}
        </section>

        <div className="grid gap-6 self-start">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2 text-base">
                <ClipboardList className="size-4" aria-hidden /> Pending assignments
              </CardTitle>
            </CardHeader>
            <CardContent>
              {data?.pending_assignments.length ? (
                <ul className="grid gap-3 text-sm">
                  {data.pending_assignments.map((p) => (
                    <li key={p.lesson_id}>
                      <Link
                        to={`/learn/${p.course.slug}/${p.lesson_id}`}
                        className="font-medium hover:underline"
                      >
                        {p.title}
                      </Link>
                      <p className="text-muted-foreground text-xs">
                        {p.course.title}
                        {p.status === 'returned' && ' · changes requested'}
                        {p.due_at && ` · due ${formatDate(p.due_at)}`}
                      </p>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-muted-foreground text-sm">You're all caught up.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2 text-base">
                <CalendarClock className="size-4" aria-hidden /> Upcoming live classes
              </CardTitle>
            </CardHeader>
            <CardContent>
              {data?.upcoming_live_classes.length ? (
                <ul className="grid gap-3 text-sm">
                  {data.upcoming_live_classes.map((l) => (
                    <li key={l.id} className="flex items-center justify-between gap-2">
                      <span className="min-w-0">
                        <Link to={`/live/${l.id}`} className="block truncate font-medium hover:underline">
                          {l.title}
                        </Link>
                        <span className="text-muted-foreground text-xs">
                          {new Date(l.starts_at).toLocaleString(undefined, {
                            weekday: 'short',
                            day: 'numeric',
                            month: 'short',
                            hour: 'numeric',
                            minute: '2-digit',
                          })}
                        </span>
                      </span>
                      {l.joinable && (
                        <Button size="sm" variant="accent" asChild>
                          <Link to={`/live/${l.id}`}>Join</Link>
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-muted-foreground text-sm">
                  You haven't registered for a live class.{' '}
                  <Link to="/app/live" className="text-primary underline">
                    Browse the schedule
                  </Link>
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-base">Recent badges</CardTitle>
              <CardDescription>
                <Link to="/app/learning-profile" className="hover:underline">
                  See all achievements
                </Link>
              </CardDescription>
            </CardHeader>
            <CardContent>
              {data?.recent_badges.length ? (
                <ul className="grid gap-2">
                  {data.recent_badges.map((b) => (
                    <li key={b.key} className="flex items-center gap-3 text-sm">
                      <span className="bg-accent/15 text-accent grid size-8 place-items-center rounded-full">
                        <BadgeIcon badgeKey={b.key} className="size-4" />
                      </span>
                      <span>
                        <span className="font-medium">{b.name}</span>
                        <span className="text-muted-foreground block text-xs">{b.description}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-muted-foreground text-sm">Complete your first lesson to earn a badge.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-3 pb-3">
              <div>
                <CardDescription>Your membership</CardDescription>
                <CardTitle className="mt-1">
                  <TierBadge tier={user.membership.tier} />
                </CardTitle>
              </div>
              {user.membership.tier !== 'elite' && (
                <Button asChild variant="accent" size="sm">
                  <Link to="/pricing">
                    Upgrade <ArrowUpRight aria-hidden />
                  </Link>
                </Button>
              )}
            </CardHeader>
          </Card>
        </div>
      </div>

      {!!data?.recommended.length && (
        <section aria-labelledby="rec-h" className="mt-8 grid gap-3">
          <h2 id="rec-h" className="text-lg font-semibold">
            Recommended for you
          </h2>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {data.recommended.map((c) => (
              <CourseCard key={c.id} course={c} />
            ))}
          </div>
        </section>
      )}
    </>
  )
}

function Stat({
  icon,
  value,
  label,
  to,
}: {
  icon: React.ReactNode
  value: string | null
  label: string
  to?: string
}) {
  const body = (
    <CardContent className="flex items-center gap-4 p-5">
      <span className="bg-secondary text-secondary-foreground grid size-10 place-items-center rounded-lg">
        {icon}
      </span>
      <div>
        {value === null ? <Skeleton className="h-7 w-12" /> : <p className="text-2xl font-bold">{value}</p>}
        <p className="text-muted-foreground text-sm">{label}</p>
      </div>
    </CardContent>
  )
  return (
    <Card>
      {to ? (
        <Link to={to} className="hover:bg-muted/40 block rounded-xl">
          {body}
        </Link>
      ) : (
        body
      )}
    </Card>
  )
}
