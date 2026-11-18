import { useQuery } from '@tanstack/react-query'
import { ClipboardCheck, MessageCircleQuestion, Star, UserPlus, type LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { TrendChart } from '@/components/charts/TrendChart'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatMinor } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ActivityItem } from '@/types/instructor'
import { teachingApi, useAnalytics } from '../api'

const RANGES = [7, 30, 90] as const
const ICON: Record<ActivityItem['type'], LucideIcon> = {
  enrollment: UserPlus,
  review: Star,
  question: MessageCircleQuestion,
  submission: ClipboardCheck,
}
const STATUS_LABEL: Record<string, string> = { draft: 'Draft', review: 'In review', published: 'Published' }
const pct = (v: number | null) => (v === null ? '—' : `${v}%`)
const monthLabel = (period: string) =>
  new Date(`${period}-01T00:00:00Z`).toLocaleDateString(undefined, {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
  })

export default function InstructorDashboardPage() {
  const [days, setDays] = useState<(typeof RANGES)[number]>(30)
  const { data, isLoading } = useAnalytics(days)
  const activity = useQuery({ queryKey: ['instructor', 'activity'], queryFn: teachingApi.activity })
  const k = data?.kpis

  const tiles = k
    ? [
        {
          label: 'Students',
          value: k.students.toLocaleString(),
          sub: `${k.new_enrollments} new in ${days} days`,
        },
        { label: 'Completion rate', value: pct(k.completion_rate), sub: 'of all enrolments' },
        { label: 'Avg quiz score', value: pct(k.avg_quiz_score), sub: `last ${days} days` },
        {
          label: 'Watch time',
          value: `${Math.round(k.watch_minutes / 60).toLocaleString()} h`,
          sub: `${k.watch_minutes.toLocaleString()} min, last ${days} days`,
        },
        {
          label: 'Rating',
          value: k.rating_avg === null ? '—' : `${k.rating_avg} ★`,
          sub: `${k.rating_count} review${k.rating_count === 1 ? '' : 's'}`,
        },
        { label: 'Live attendance', value: pct(k.live_attendance_rate), sub: 'of registrants attended' },
        {
          label: 'Open questions',
          value: String(k.open_questions),
          sub: 'waiting for you',
          to: '/instructor/questions',
        },
        {
          label: 'To grade',
          value: String(k.pending_submissions),
          sub: 'assignment submissions',
          to: '/instructor/grading',
        },
      ]
    : []

  const currencies = [
    ...new Set(data?.series.earnings.filter((e) => e.amount > 0).map((e) => e.currency) ?? []),
  ]
  // Every currency chart shows the same months (missing = 0), oldest first.
  const periods = [...new Set(data?.series.earnings.map((e) => e.period) ?? [])].sort()

  return (
    <>
      <Seo title="Instructor dashboard" noindex />
      <PageHeader
        title="Instructor dashboard"
        description="How your courses are doing."
        actions={
          <div role="radiogroup" aria-label="Date range" className="bg-muted inline-flex rounded-md p-1">
            {RANGES.map((r) => (
              <button
                key={r}
                role="radio"
                aria-checked={days === r}
                onClick={() => setDays(r)}
                className={cn(
                  'rounded px-3 py-1.5 text-sm font-medium',
                  days === r ? 'bg-background shadow-sm' : 'text-muted-foreground',
                )}
              >
                {r} days
              </button>
            ))}
          </div>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {isLoading && Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-24" />)}
        {tiles.map((t) => {
          const body = (
            <CardContent className="p-5">
              <p className="text-muted-foreground text-sm">{t.label}</p>
              <p className="mt-1 text-3xl font-bold tabular-nums">{t.value}</p>
              <p className="text-muted-foreground mt-1 text-xs">{t.sub}</p>
            </CardContent>
          )
          return (
            <Card key={t.label}>
              {t.to ? (
                <Link to={t.to} className="hover:bg-muted/40 block rounded-xl">
                  {body}
                </Link>
              ) : (
                body
              )}
            </Card>
          )
        })}
      </div>

      {data && (
        <div className="mb-6 grid gap-4 lg:grid-cols-2">
          <TrendChart
            title="New enrolments"
            description={`Per day, last ${days} days`}
            data={data.series.daily.map((d) => ({ label: shortDate(d.date), value: d.enrollments }))}
          />
          <TrendChart
            title="Watch time"
            description={`Minutes per day, last ${days} days`}
            kind="area"
            data={data.series.daily.map((d) => ({ label: shortDate(d.date), value: d.watch_minutes }))}
            format={(v) => `${v.toLocaleString()} min`}
          />
          {currencies.map((cur) => (
            <TrendChart
              key={cur}
              title={`Earnings (${cur})`}
              description="Per month · the lighter bar is this month so far"
              data={periods.map((period) => ({
                label: monthLabel(period),
                value:
                  (data.series.earnings.find((e) => e.period === period && e.currency === cur)?.amount ?? 0) /
                  100,
                estimate: period === new Date().toISOString().slice(0, 7),
              }))}
              format={(v) => formatMinor(Math.round(v * 100), cur)}
            />
          ))}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">Courses</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            {data?.courses.length ? (
              <table className="w-full min-w-[560px] text-sm">
                <thead className="text-muted-foreground text-left">
                  <tr>
                    <th className="py-2 font-medium">Course</th>
                    <th className="py-2 pl-4 text-right font-medium whitespace-nowrap">Students</th>
                    <th className="py-2 pl-4 text-right font-medium whitespace-nowrap">Completion</th>
                    <th className="py-2 pl-4 text-right font-medium whitespace-nowrap">Avg quiz</th>
                    <th className="py-2 pl-4 text-right font-medium whitespace-nowrap">Watch (min)</th>
                    <th className="py-2 pl-4 text-right font-medium whitespace-nowrap">Rating</th>
                  </tr>
                </thead>
                <tbody className="divide-y tabular-nums">
                  {data.courses.map((c) => (
                    <tr key={c.id}>
                      <td className="py-2">
                        <Link to={`/instructor/courses/${c.id}/edit`} className="font-medium hover:underline">
                          {c.title}
                        </Link>
                        <span className="text-muted-foreground ml-2 text-xs">
                          {STATUS_LABEL[c.status] ?? c.status}
                        </span>
                      </td>
                      <td className="py-2 pl-4 text-right">{c.students}</td>
                      <td className="py-2 pl-4 text-right">{pct(c.completion_rate)}</td>
                      <td className="py-2 pl-4 text-right">{pct(c.avg_quiz_score)}</td>
                      <td className="py-2 pl-4 text-right">{c.watch_minutes.toLocaleString()}</td>
                      <td className="py-2 pl-4 text-right">
                        {c.rating_avg === null ? '—' : `${c.rating_avg} (${c.rating_count})`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="text-muted-foreground text-sm">
                No courses yet.{' '}
                <Link to="/instructor/courses" className="text-primary underline">
                  Create your first course
                </Link>
              </p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent activity</CardTitle>
          </CardHeader>
          <CardContent>
            {activity.data?.length ? (
              <ul className="grid gap-3 text-sm">
                {activity.data.map((a, i) => {
                  const Icon = ICON[a.type]
                  const text = <span>{a.text}</span>
                  return (
                    <li key={`${a.type}-${a.at}-${i}`} className="flex gap-3">
                      <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                      <span className="min-w-0">
                        {a.link ? (
                          <Link to={a.link} className="hover:underline">
                            {text}
                          </Link>
                        ) : (
                          text
                        )}
                        <span className="text-muted-foreground block text-xs">
                          {new Date(a.at).toLocaleString()}
                        </span>
                      </span>
                    </li>
                  )
                })}
              </ul>
            ) : (
              <p className="text-muted-foreground text-sm">Nothing yet.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  )
}

const shortDate = (d: string) =>
  new Date(`${d}T00:00:00Z`).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  })
