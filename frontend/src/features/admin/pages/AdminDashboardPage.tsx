import { ArrowRight, CalendarCheck, IndianRupee, TrendingDown, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { TrendChart } from '@/components/charts/TrendChart'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { can } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { useOverview, type Overview } from '../analytics/api'
import { BarList, KpiCard, Panel, RangeBar } from '../analytics/components'
import { hours, money, moneyCompact, pct, periodLabel } from '../analytics/format'
import { useRange } from '../analytics/useRange'

export default function AdminDashboardPage() {
  const user = useAuthStore((s) => s.user)
  if (!can(user, 'analytics.view')) {
    return (
      <>
        <Seo title="Admin" noindex />
        <PageHeader
          title={`Welcome, ${user?.name ?? 'admin'}`}
          description="Use the menu to manage the areas you have access to."
        />
      </>
    )
  }
  return <Dashboard />
}

function Dashboard() {
  const { range, update } = useRange()
  const { data, isLoading, isError, isFetching } = useOverview(range)
  const o = data?.data
  const c = range.currency

  return (
    <>
      <Seo title="Dashboard" noindex />
      <PageHeader
        title="Dashboard"
        description="How the business is doing. Money figures come straight from recorded payments, one currency at a time."
        actions={
          <Button asChild variant="outline" size="sm">
            <Link to={`/admin/analytics?from=${range.from}&to=${range.to}&currency=${c}`}>
              Full analytics <ArrowRight aria-hidden />
            </Link>
          </Button>
        }
      />
      <RangeBar
        range={range}
        currencies={data?.meta.currencies ?? []}
        timezone={data?.meta.timezone}
        onChange={update}
      />

      {isError && (
        <p role="alert" className="text-destructive mb-4 text-sm">
          Couldn't load analytics. Try again in a moment.
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy={isFetching || undefined}>
        <KpiCard
          label="Monthly recurring revenue"
          icon={IndianRupee}
          loading={isLoading}
          value={o ? money(o.summary.mrr, c) : '—'}
          note={o ? `${money(o.summary.arr, c)} a year · net of tax` : undefined}
        />
        <KpiCard
          label="Active members"
          icon={Users}
          loading={isLoading}
          value={o?.summary.active_members.toLocaleString() ?? '—'}
          note={o ? `${o.summary.paying_members.toLocaleString()} paying in ${c}` : undefined}
        />
        <KpiCard
          label="Churn in range"
          icon={TrendingDown}
          loading={isLoading}
          value={o ? pct(o.summary.churn_rate) : '—'}
          note={
            o
              ? `${o.members.churned_members} left of ${o.members.paying_at_start} paying at the start`
              : undefined
          }
        />
        <KpiCard
          label="Revenue today"
          icon={CalendarCheck}
          loading={isLoading}
          value={o ? money(o.summary.revenue_today, c) : '—'}
          note={o ? `${money(o.summary.revenue_range, c)} net in range` : undefined}
        />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          {o ? (
            <TrendChart
              kind="area"
              title="Net revenue"
              description={`Payments minus refunds per ${o.revenue.interval} · ${money(o.revenue.net, c)} total`}
              data={o.revenue.series.map((p) => ({ label: periodLabel(p.period), value: p.net }))}
              format={(v) => money(v, c)}
              axisFormat={(v) => moneyCompact(v, c)}
              height={260}
            />
          ) : (
            <Skeleton className="h-80 rounded-xl" />
          )}
        </div>
        <Panel title="Members by tier" description="Highest active membership per person">
          {o ? <TierBars o={o} /> : <Skeleton className="h-40" />}
        </Panel>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Panel title="Signup funnel" description="People who joined in this range">
          {o ? <Funnel o={o} /> : <Skeleton className="h-40" />}
        </Panel>
        <Panel title="Top courses" description="By watch time in this range" className="lg:col-span-2">
          {o ? <TopCourses o={o} currency={c} /> : <Skeleton className="h-40" />}
        </Panel>
      </div>
    </>
  )
}

function TierBars({ o }: { o: Overview }) {
  return (
    <BarList
      label="Members by tier"
      rows={o.members.active_by_tier.map((t) => ({
        key: String(t.tier),
        label: t.name,
        value: t.members,
        note: t.complimentary ? `(${t.complimentary} comp)` : undefined,
      }))}
      empty="No active members yet."
    />
  )
}

function Funnel({ o }: { o: Overview }) {
  const first = o.funnel[0]?.count ?? 0
  return (
    <BarList
      label="Signup funnel"
      rows={o.funnel.map((s) => ({
        key: s.step,
        label: s.label,
        value: s.count,
        note: first && s.step !== 'signed_up' ? `(${pct(s.count / first, 0)})` : undefined,
      }))}
      empty="No signups in this range."
    />
  )
}

function TopCourses({ o, currency }: { o: Overview; currency: string }) {
  if (o.top_courses.length === 0)
    return <p className="text-muted-foreground py-6 text-center text-sm">No watch time in this range.</p>
  return (
    <div className="-mx-1 overflow-x-auto">
      <table className="w-full min-w-[560px] text-sm">
        <thead className="text-muted-foreground text-left">
          <tr>
            <th className="p-2 font-medium">Course</th>
            <th className="p-2 text-right font-medium">Watched</th>
            <th className="p-2 text-right font-medium">Learners</th>
            <th className="p-2 text-right font-medium">Completion</th>
            <th className="p-2 text-right font-medium">Sales</th>
          </tr>
        </thead>
        <tbody className="divide-y tabular-nums">
          {o.top_courses.map((t) => (
            <tr key={t.id ?? t.title}>
              <td className="p-2">
                {t.slug ? (
                  <Link to={`/courses/${t.slug}`} className="font-medium hover:underline">
                    {t.title}
                  </Link>
                ) : (
                  t.title
                )}
                {t.instructor && <span className="text-muted-foreground block text-xs">{t.instructor}</span>}
              </td>
              <td className="p-2 text-right">{hours(t.watch_seconds)}</td>
              <td className="p-2 text-right">{t.learners.toLocaleString()}</td>
              <td className="p-2 text-right">{pct(t.completion_rate, 0)}</td>
              <td className="p-2 text-right">{t.sales ? money(t.sales, currency) : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
