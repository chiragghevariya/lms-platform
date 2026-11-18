import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { TrendChart } from '@/components/charts/TrendChart'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useAffiliateReport,
  useCohorts,
  useLearningReport,
  useMembersReport,
  useRevenueReport,
  type Cohort,
} from '../analytics/api'
import { BarList, KpiCard, Panel, RangeBar } from '../analytics/components'
import { hours, money, moneyCompact, pct, periodLabel } from '../analytics/format'
import { useRange } from '../analytics/useRange'

const GATEWAY: Record<string, string> = { stripe: 'Stripe', razorpay: 'Razorpay', manual: 'Manual' }

export default function AdminAnalyticsPage() {
  const { range, update } = useRange()
  const c = range.currency
  const members = useMembersReport(range)
  const revenue = useRevenueReport(range)
  const learning = useLearningReport(range)
  const affiliates = useAffiliateReport(range)
  const m = members.data?.data
  const r = revenue.data?.data
  const l = learning.data?.data
  const a = affiliates.data?.data
  const meta = members.data?.meta ?? revenue.data?.meta

  return (
    <>
      <Seo title="Analytics" noindex />
      <PageHeader
        title="Analytics"
        description="Membership, revenue, learning and affiliate metrics. Definitions are under each number."
      />
      <RangeBar
        range={range}
        currencies={meta?.currencies ?? []}
        timezone={meta?.timezone}
        onChange={update}
      />

      <h2 className="mb-3 text-lg font-semibold">Membership</h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label="MRR"
          loading={!m}
          value={m ? money(m.mrr, c) : '—'}
          note="Renewing paid plans, net of tax, per month"
        />
        <KpiCard label="ARR" loading={!m} value={m ? money(m.arr, c) : '—'} note="MRR × 12" />
        <KpiCard
          label="ARPU"
          loading={!m}
          value={m ? money(m.arpu, c) : '—'}
          note={m ? `MRR ÷ ${m.paying_members} paying members` : undefined}
        />
        <KpiCard
          label="Lifetime value"
          loading={!m}
          value={m ? money(m.ltv, c) : '—'}
          note={m?.ltv === null ? 'Needs churn in the range to estimate' : 'ARPU ÷ monthly churn'}
        />
        <KpiCard
          label="New paying members"
          loading={!m}
          value={m?.new_members.toLocaleString() ?? '—'}
          note="First membership payment in range"
        />
        <KpiCard
          label="Churned"
          loading={!m}
          value={m?.churned_members.toLocaleString() ?? '—'}
          note={
            m
              ? `${pct(m.churn_rate)} of ${m.paying_at_start} at start · ${pct(m.monthly_churn_rate)} / month`
              : undefined
          }
        />
        <KpiCard
          label="Trial conversion"
          loading={!m}
          value={m ? pct(m.trial_conversion) : '—'}
          note={m ? `${m.trials_converted} of ${m.trials_started} trials started in range paid` : undefined}
        />
        <KpiCard
          label="Active members"
          loading={!m}
          value={m ? m.active_by_tier.reduce((s, t) => s + t.members, 0).toLocaleString() : '—'}
          note={m ? `${m.active_by_tier.reduce((s, t) => s + t.complimentary, 0)} complimentary` : undefined}
        />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          {members.data ? (
            <TrendChart
              kind="area"
              title="MRR over time"
              description="Nightly snapshots (from the day the job first ran) plus today"
              data={members.data.data.mrr_trend.map((p) => ({ label: periodLabel(p.day), value: p.mrr }))}
              format={(v) => money(v, c)}
              axisFormat={(v) => moneyCompact(v, c)}
            />
          ) : (
            <Skeleton className="h-72 rounded-xl" />
          )}
        </div>
        <Panel title="Signup funnel" description="People who joined in this range">
          {m ? (
            <BarList
              label="Signup funnel"
              rows={members.data!.data.funnel.map((s, _i, all) => ({
                key: s.step,
                label: s.label,
                value: s.count,
                note:
                  all[0]?.count && s.step !== 'signed_up'
                    ? `(${pct(s.count / all[0]?.count, 0)})`
                    : undefined,
              }))}
            />
          ) : (
            <Skeleton className="h-40" />
          )}
        </Panel>
      </div>

      <h2 className="mt-10 mb-3 text-lg font-semibold">Revenue</h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label="Gross"
          loading={!r}
          value={r ? money(r.gross, c) : '—'}
          note={r ? `${r.payments} payments, tax included` : undefined}
        />
        <KpiCard
          label="Refunds"
          loading={!r}
          value={r ? money(r.refunds, c) : '—'}
          note="Refunded in range"
        />
        <KpiCard label="Net" loading={!r} value={r ? money(r.net, c) : '—'} note="Gross − refunds" />
        <KpiCard
          label="Tax collected"
          loading={!r}
          value={r ? money(r.tax, c) : '—'}
          note="GST / VAT on paid invoices"
        />
      </div>
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          {r ? (
            <TrendChart
              title="Net revenue"
              description={`Per ${r.interval}`}
              data={r.series.map((p) => ({ label: periodLabel(p.period), value: p.net }))}
              format={(v) => money(v, c)}
              axisFormat={(v) => moneyCompact(v, c)}
            />
          ) : (
            <Skeleton className="h-72 rounded-xl" />
          )}
        </div>
        <Panel title="Where it came from">
          {r ? (
            <div className="grid gap-6">
              <BarList
                label="Revenue by product"
                format={(v) => money(v, c)}
                rows={[
                  { key: 'membership', label: 'Memberships', value: r.membership },
                  { key: 'courses', label: 'Course sales', value: r.courses },
                ]}
              />
              <BarList
                label="Revenue by gateway"
                format={(v) => money(v, c)}
                rows={r.by_gateway.map((g) => ({
                  key: g.gateway,
                  label: GATEWAY[g.gateway] ?? g.gateway,
                  value: g.total,
                  note: `(${g.payments})`,
                }))}
              />
            </div>
          ) : (
            <Skeleton className="h-40" />
          )}
        </Panel>
      </div>
      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <Panel title="Revenue by country" description="Billing country on the invoice">
          {r ? (
            <BarList
              label="Revenue by country"
              format={(v) => money(v, c)}
              rows={r.by_country.map((g) => ({
                key: g.country ?? 'unknown',
                label: g.country ? countryName(g.country) : 'Not recorded',
                value: g.total,
              }))}
            />
          ) : (
            <Skeleton className="h-40" />
          )}
        </Panel>
        <Panel
          title="Affiliates"
          description="Commissions earned on referred revenue"
          className="lg:col-span-2"
        >
          {a ? (
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Stat
                label="Referred revenue"
                value={money(a.referred_revenue, c)}
                note={`${pct(a.referred_share)} of net`}
              />
              <Stat
                label="Commissions"
                value={money(a.commissions, c)}
                note={`${pct(a.share_of_revenue)} of net revenue`}
              />
              <Stat label="Paid out" value={money(a.paid_out, c)} note="Marked paid in range" />
              <Stat
                label="Payouts"
                value={
                  <Link className="text-primary text-base hover:underline" to="/admin/affiliates">
                    Manage →
                  </Link>
                }
              />
            </dl>
          ) : (
            <Skeleton className="h-24" />
          )}
        </Panel>
      </div>

      <h2 className="mt-10 mb-3 text-lg font-semibold">Retention</h2>
      <CohortTable />

      <h2 className="mt-10 mb-3 text-lg font-semibold">Learning & live</h2>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label="Watch time"
          loading={!l}
          value={l ? hours(l.watch_seconds) : '—'}
          note={l ? `${l.active_learners} active learners` : undefined}
        />
        <KpiCard
          label="Enrollments"
          loading={!l}
          value={l?.enrollments.toLocaleString() ?? '—'}
          note={l ? `${l.completions} courses completed in range` : undefined}
        />
        <KpiCard
          label="Completion rate"
          loading={!l}
          value={l ? pct(l.completion_rate) : '—'}
          note="All-time: completed ÷ enrolled"
        />
        <KpiCard
          label="Live attendance"
          loading={!l}
          value={l ? pct(l.live.attendance_rate) : '—'}
          note={
            l
              ? `${l.live.attended} of ${l.live.registrations} registered · ${l.live.sessions} sessions`
              : undefined
          }
        />
      </div>
      <Panel title="Top courses" description="By watch time in this range" className="mt-6">
        {l ? (
          l.top_courses.length === 0 ? (
            <p className="text-muted-foreground py-6 text-center text-sm">No watch time in this range.</p>
          ) : (
            <div className="-mx-1 overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <thead className="text-muted-foreground text-left">
                  <tr>
                    <th className="p-2 font-medium">Course</th>
                    <th className="p-2 text-right font-medium">Watched</th>
                    <th className="p-2 text-right font-medium">Learners</th>
                    <th className="p-2 text-right font-medium">Enrolled</th>
                    <th className="p-2 text-right font-medium">Completion</th>
                    <th className="p-2 text-right font-medium">Rating</th>
                    <th className="p-2 text-right font-medium">Sales</th>
                  </tr>
                </thead>
                <tbody className="divide-y tabular-nums">
                  {l.top_courses.map((t) => (
                    <tr key={t.id ?? t.title}>
                      <td className="p-2">
                        <span className="font-medium">{t.title}</span>
                        {t.instructor && (
                          <span className="text-muted-foreground block text-xs">{t.instructor}</span>
                        )}
                      </td>
                      <td className="p-2 text-right">{hours(t.watch_seconds)}</td>
                      <td className="p-2 text-right">{t.learners}</td>
                      <td className="p-2 text-right">{t.enrollments}</td>
                      <td className="p-2 text-right">{pct(t.completion_rate, 0)}</td>
                      <td className="p-2 text-right">{t.rating ? t.rating.toFixed(1) : '—'}</td>
                      <td className="p-2 text-right">{t.sales ? money(t.sales, c) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        ) : (
          <Skeleton className="h-40" />
        )}
      </Panel>
    </>
  )
}

function Stat({ label, value, note }: { label: string; value: ReactNode; note?: string }) {
  return (
    <div>
      <dt className="text-muted-foreground text-sm">{label}</dt>
      <dd className="text-xl font-bold tabular-nums">{value}</dd>
      {note && <dd className="text-muted-foreground text-xs">{note}</dd>}
    </div>
  )
}

const regionNames = (() => {
  try {
    return new Intl.DisplayNames(undefined, { type: 'region' })
  } catch {
    return null
  }
})()
const countryName = (code: string) => regionNames?.of(code) ?? code

/**
 * Cohort retention as a table with a single-hue sequential fill (light → dark = low → high).
 * Every cell prints its percentage, so the colour is only a reading aid.
 */
function CohortTable() {
  const [months, setMonths] = useState(12)
  const { data } = useCohorts(months)
  const rows = data?.data
  const width = Math.max(0, ...(rows ?? []).map((r) => r.retention.length))

  return (
    <Panel
      title="Cohort retention"
      description="Members grouped by the month of their first paid membership; each cell is the share still holding a paid period that month."
      action={
        <label className="text-muted-foreground flex items-center gap-2 text-xs">
          Show
          <select
            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
            value={months}
            onChange={(e) => setMonths(Number(e.target.value))}
          >
            <option value={6}>6 months</option>
            <option value={12}>12 months</option>
            <option value={24}>24 months</option>
          </select>
        </label>
      }
    >
      {!rows ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="-mx-1 overflow-x-auto">
          <table className="w-full min-w-[560px] border-separate border-spacing-0.5 text-xs tabular-nums">
            <caption className="sr-only">Retention by monthly cohort</caption>
            <thead className="text-muted-foreground">
              <tr>
                <th scope="col" className="p-1.5 text-left font-medium">
                  Cohort
                </th>
                <th scope="col" className="p-1.5 text-right font-medium">
                  People
                </th>
                {Array.from({ length: width }).map((_, k) => (
                  <th key={k} scope="col" className="p-1.5 text-center font-medium">
                    M{k}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <CohortRow key={r.cohort} row={r} width={width} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  )
}

function CohortRow({ row, width }: { row: Cohort; width: number }) {
  return (
    <tr>
      <th scope="row" className="p-1.5 text-left font-medium whitespace-nowrap">
        {periodLabel(row.cohort)}
      </th>
      <td className="text-muted-foreground p-1.5 text-right">{row.size}</td>
      {Array.from({ length: width }).map((_, k) => {
        const v = row.retention[k]
        if (v === undefined) return <td key={k} />
        if (v === null || row.size === 0)
          return (
            <td key={k} className="text-muted-foreground p-1.5 text-center">
              —
            </td>
          )
        const strong = v >= 0.55
        return (
          <td
            key={k}
            className="rounded p-1.5 text-center"
            style={{
              background: `color-mix(in oklch, var(--chart-1) ${Math.round(8 + v * 82)}%, var(--card))`,
              color: strong ? 'var(--primary-foreground)' : 'var(--foreground)',
            }}
          >
            {Math.round(v * 100)}%
          </td>
        )
      })}
    </tr>
  )
}
