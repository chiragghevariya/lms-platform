import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Clock, Copy, Download, ShieldAlert } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { TrendChart } from '@/components/charts/TrendChart'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError } from '@/lib/api-client'
import { formatDate, formatMinor } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AffiliateAccount } from '@/types/affiliate'
import { affiliateApi, useAffiliate } from '../api'
import { ShareLink } from '../components/ShareLink'

const TABS = [
  ['overview', 'Overview'],
  ['links', 'Link builder'],
  ['commissions', 'Commissions'],
  ['payouts', 'Payouts'],
  ['assets', 'Marketing assets'],
] as const
type Tab = (typeof TABS)[number][0]

export default function AffiliatePage() {
  const { data, isLoading } = useAffiliate()
  const [tab, setTab] = useState<Tab>('overview')

  return (
    <>
      <Seo title="Affiliate programme" noindex />
      <PageHeader
        title="Affiliate programme"
        description="Earn commission when people you refer become paying members."
      />
      {isLoading ? (
        <Skeleton className="h-64" />
      ) : !data || data.status === 'rejected' ? (
        <ApplyCard rejected={data?.status === 'rejected' ? data.review_notes : null} />
      ) : data.status === 'pending' ? (
        <Card>
          <CardContent className="flex items-center gap-3 p-6">
            <Clock className="size-5" aria-hidden />
            <div>
              <p className="font-semibold">Application under review</p>
              <p className="text-muted-foreground text-sm">
                We'll email you when it's approved. Your code will be {data.code}.
              </p>
            </div>
          </CardContent>
        </Card>
      ) : (
        <>
          {data.status === 'suspended' && (
            <Alert variant="destructive" className="mb-4">
              <ShieldAlert className="mr-1 inline size-4" aria-hidden /> Your affiliate account is suspended —
              your link no longer tracks.
              {data.review_notes ? ` ${data.review_notes}` : ''}
            </Alert>
          )}
          <div role="tablist" aria-label="Affiliate sections" className="mb-5 flex flex-wrap gap-1 border-b">
            {TABS.map(([v, label]) => (
              <button
                key={v}
                role="tab"
                aria-selected={tab === v}
                onClick={() => setTab(v)}
                className={cn(
                  '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                  tab === v ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
                )}
              >
                {label}
              </button>
            ))}
          </div>
          {tab === 'overview' && <Overview a={data} />}
          {tab === 'links' && <LinkBuilder a={data} />}
          {tab === 'commissions' && <Commissions />}
          {tab === 'payouts' && <Payouts a={data} />}
          {tab === 'assets' && <Assets />}
        </>
      )}
    </>
  )
}

function ApplyCard({ rejected }: { rejected: string | null }) {
  const qc = useQueryClient()
  const [plan, setPlan] = useState('')
  const [website, setWebsite] = useState('')
  const [accept, setAccept] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [sending, setSending] = useState(false)
  return (
    <Card>
      <CardHeader>
        <CardTitle>Join the affiliate programme</CardTitle>
        <CardDescription>
          Earn a share of every membership your audience buys — on the first payment and renewals for a year.
          We review applications within a few days.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {rejected !== null && (
          <Alert className="mb-4">
            Your previous application wasn't approved{rejected ? `: ${rejected}` : '.'} You can apply again.
          </Alert>
        )}
        <form
          className="grid max-w-xl gap-3"
          onSubmit={async (e) => {
            e.preventDefault()
            setSending(true)
            setErrors({})
            try {
              await affiliateApi.apply({
                promotion_plan: plan,
                website: website || undefined,
                accept_terms: accept,
              })
              toast.success('Application sent')
              void qc.invalidateQueries({ queryKey: ['affiliate'] })
            } catch (err) {
              if (err instanceof ApiError && err.isValidation)
                setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
              else toast.error(err instanceof Error ? err.message : 'Could not send')
            } finally {
              setSending(false)
            }
          }}
        >
          <label htmlFor="af-plan" className="text-sm font-medium">
            How will you promote us?
          </label>
          <textarea
            id="af-plan"
            rows={4}
            maxLength={3000}
            value={plan}
            onChange={(e) => setPlan(e.target.value)}
            aria-invalid={!!errors.promotion_plan}
            placeholder="e.g. a YouTube channel about freelancing (40k subscribers) and a weekly newsletter"
            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
          />
          {errors.promotion_plan && (
            <p role="alert" className="text-destructive text-xs">
              {errors.promotion_plan}
            </p>
          )}
          <label htmlFor="af-web" className="text-sm font-medium">
            Website or channel <span className="text-muted-foreground font-normal">(optional)</span>
          </label>
          <Input
            id="af-web"
            type="url"
            placeholder="https://"
            value={website}
            onChange={(e) => setWebsite(e.target.value)}
          />
          {errors.website && (
            <p role="alert" className="text-destructive text-xs">
              {errors.website}
            </p>
          )}
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={accept}
              onChange={(e) => setAccept(e.target.checked)}
            />
            I won't use spam, paid ads on our brand name, or refer myself. Commissions on refunded or
            fraudulent purchases are reversed.
          </label>
          {errors.accept_terms && (
            <p role="alert" className="text-destructive text-xs">
              Please accept the programme rules.
            </p>
          )}
          {errors.affiliate && <Alert variant="destructive">{errors.affiliate}</Alert>}
          <Button type="submit" loading={sending} className="justify-self-start">
            Apply
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function Overview({ a }: { a: AffiliateAccount }) {
  const [days, setDays] = useState(30)
  const { data } = useQuery({
    queryKey: ['affiliate', 'stats', days],
    queryFn: () => affiliateApi.stats(days),
  })
  const rate = a.terms.type === 'percent' ? `${a.terms.rate}%` : 'a fixed amount'
  const f = data?.funnel
  const stages = f
    ? ([
        ['Clicks', f.clicks],
        ['Sign-ups', f.signups],
        ['Paying members', f.conversions],
      ] as const)
    : []
  const max = Math.max(1, ...stages.map(([, v]) => v))

  return (
    <div className="grid gap-6">
      <Card>
        <CardContent className="grid gap-4 p-5">
          <ShareLink
            url={a.link}
            text="I learn practical, income-earning skills here — join with my link:"
            label="Your referral link"
          />
          <p className="text-muted-foreground text-sm">
            You earn {rate} of each payment (after tax) for {a.terms.recurring_months} months. Links stay
            attributed for {a.terms.cookie_days} days ({a.terms.attribution.replace('_', ' ')}). Commissions
            become payable {a.terms.hold_days} days after payment, so refunds can be accounted for.
          </p>
        </CardContent>
      </Card>

      {a.balances && Object.keys(a.balances).length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Object.entries(a.balances).flatMap(([cur, b]) =>
            [
              { label: `Pending (${cur})`, v: b.pending, hint: `in the ${a.terms.hold_days}-day hold` },
              {
                label: `Payable (${cur})`,
                v: b.approved,
                hint: `next payout from ${formatMinor(a.payout.minimum[cur] ?? 0, cur)}`,
              },
              { label: `Paid (${cur})`, v: b.paid, hint: 'all time' },
            ].map((t) => (
              <Card key={`${cur}-${t.label}`}>
                <CardContent className="p-5">
                  <p className="text-muted-foreground text-sm">{t.label}</p>
                  <p className="text-2xl font-bold tabular-nums">{formatMinor(t.v, cur)}</p>
                  <p className="text-muted-foreground text-xs">{t.hint}</p>
                </CardContent>
              </Card>
            )),
          )}
        </div>
      )}

      <section aria-labelledby="funnel-h" className="grid gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="funnel-h" className="font-semibold">
            Funnel
          </h2>
          <select
            aria-label="Date range"
            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
          >
            <option value={7}>Last 7 days</option>
            <option value={30}>Last 30 days</option>
            <option value={90}>Last 90 days</option>
            <option value={365}>Last 12 months</option>
          </select>
        </div>
        {f && (
          <Card>
            <CardContent className="grid gap-3 p-5">
              {stages.map(([label, v], i) => (
                <div key={label} className="grid grid-cols-[8rem_1fr_5rem] items-center gap-3 text-sm">
                  <span>{label}</span>
                  <div className="bg-muted h-6 overflow-hidden rounded" aria-hidden>
                    <div
                      className="h-full rounded-r"
                      style={{ width: `${(v / max) * 100}%`, background: 'var(--chart-1)' }}
                    />
                  </div>
                  <span className="text-right font-semibold tabular-nums">
                    {v.toLocaleString()}
                    {i === 1 && f.signup_rate !== null && (
                      <span className="text-muted-foreground block text-xs font-normal">
                        {f.signup_rate}% of clicks
                      </span>
                    )}
                    {i === 2 && f.conversion_rate !== null && (
                      <span className="text-muted-foreground block text-xs font-normal">
                        {f.conversion_rate}% of sign-ups
                      </span>
                    )}
                  </span>
                </div>
              ))}
              <p className="text-muted-foreground text-xs">
                {f.unique_visitors.toLocaleString()} unique visitor{f.unique_visitors === 1 ? '' : 's'}
              </p>
            </CardContent>
          </Card>
        )}
        {data && (
          <div className="grid gap-4 lg:grid-cols-2">
            <TrendChart
              title="Clicks"
              description="Per day"
              data={data.daily.map((d) => ({ label: shortDay(d.date), value: d.clicks }))}
            />
            <TrendChart
              title="Sign-ups"
              description="Per day"
              data={data.daily.map((d) => ({ label: shortDay(d.date), value: d.signups }))}
            />
          </div>
        )}
        {!!data?.campaigns.length && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Top campaigns (utm_campaign)</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="divide-y text-sm">
                {data.campaigns.map((c) => (
                  <li key={c.campaign} className="flex justify-between py-2">
                    <span>{c.campaign}</span>
                    <span className="tabular-nums">
                      {c.clicks} click{c.clicks === 1 ? '' : 's'}
                    </span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </section>
    </div>
  )
}

function LinkBuilder({ a }: { a: AffiliateAccount }) {
  const origin = useMemo(() => new URL(a.link).origin, [a.link])
  const [v, setV] = useState({ path: '/', source: '', medium: '', campaign: '', content: '' })
  const url = useMemo(() => {
    const u = new URL(v.path.startsWith('/') ? v.path : `/${v.path}`, origin)
    u.searchParams.set('ref', a.code)
    ;(['source', 'medium', 'campaign', 'content'] as const).forEach(
      (k) => v[k].trim() && u.searchParams.set(`utm_${k}`, v[k].trim()),
    )
    return u.toString()
  }, [v, origin, a.code])
  const field = (k: keyof typeof v, label: string, placeholder: string) => (
    <div className="grid gap-1">
      <label htmlFor={`utm-${k}`} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={`utm-${k}`}
        placeholder={placeholder}
        value={v[k]}
        onChange={(e) => setV({ ...v, [k]: e.target.value })}
      />
    </div>
  )
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">UTM link builder</CardTitle>
        <CardDescription>Tag links per channel so your funnel shows which ones convert.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1">
            <label htmlFor="utm-path" className="text-sm font-medium">
              Landing page
            </label>
            <select
              id="utm-path"
              className="border-input bg-background h-9 rounded-md border px-2 text-sm"
              value={v.path}
              onChange={(e) => setV({ ...v, path: e.target.value })}
            >
              <option value="/">Home</option>
              <option value="/pricing">Pricing</option>
              <option value="/courses">Course catalogue</option>
            </select>
          </div>
          {field('source', 'Source', 'youtube, instagram, newsletter')}
          {field('medium', 'Medium', 'video, story, email')}
          {field('campaign', 'Campaign', 'diwali-sale')}
          {field('content', 'Content (optional)', 'pinned-comment')}
        </div>
        <ShareLink url={url} text="Join me on this learning platform:" label="Tagged link" />
      </CardContent>
    </Card>
  )
}

const shortDay = (d: string) =>
  new Date(`${d}T00:00:00Z`).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  })

const STATUS_TEXT: Record<string, string> = {
  pending: 'In hold',
  approved: 'Payable',
  paid: 'Paid',
  reversed: 'Reversed',
}

function Commissions() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['affiliate', 'commissions', page],
    queryFn: () => affiliateApi.commissions(page),
  })
  if (isLoading) return <Skeleton className="h-40" />
  if (!data?.data.length)
    return (
      <p className="text-muted-foreground text-sm">
        No commissions yet — they appear when someone you referred pays.
      </p>
    )
  return (
    <div className="grid gap-3">
      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full min-w-[640px] text-sm">
          <thead className="bg-muted/50 text-muted-foreground text-left">
            <tr>
              <th className="p-3 font-medium">Date</th>
              <th className="p-3 font-medium">Customer</th>
              <th className="p-3 font-medium">For</th>
              <th className="p-3 text-right font-medium">Commission</th>
              <th className="p-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y tabular-nums">
            {data.data.map((c) => (
              <tr key={c.id}>
                <td className="p-3">{formatDate(c.created_at)}</td>
                <td className="p-3">
                  {c.customer}
                  {c.level === 2 && (
                    <span className="text-muted-foreground block text-xs">via your recruit (level 2)</span>
                  )}
                </td>
                <td className="p-3">
                  {c.description}
                  <span className="text-muted-foreground block text-xs">
                    {c.rate} of {formatMinor(Math.abs(c.base_amount), c.currency)}
                  </span>
                </td>
                <td className={cn('p-3 text-right font-medium', c.net < 0 && 'text-destructive')}>
                  {formatMinor(c.net, c.currency)}
                  {c.net !== c.amount && c.status !== 'reversed' && (
                    <span className="text-muted-foreground block text-xs line-through">
                      {formatMinor(c.amount, c.currency)}
                    </span>
                  )}
                </td>
                <td className="p-3">
                  <Badge variant={c.status === 'paid' ? 'default' : 'secondary'}>
                    {c.on_hold_for_review ? 'Under review' : STATUS_TEXT[c.status]}
                  </Badge>
                  {c.status === 'pending' && !c.on_hold_for_review && (
                    <span className="text-muted-foreground block text-xs">
                      payable {formatDate(c.available_at)}
                    </span>
                  )}
                  {c.reversal_reason && (
                    <span className="text-muted-foreground block text-xs">
                      {c.reversal_reason.replace(/_/g, ' ')}
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data.meta.last_page > 1 && (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Previous
          </Button>
          <span className="text-sm">
            Page {page} of {data.meta.last_page}
          </span>
          <Button
            size="sm"
            variant="outline"
            disabled={page >= data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  )
}

function Payouts({ a }: { a: AffiliateAccount }) {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['affiliate', 'payouts'], queryFn: affiliateApi.payouts })
  const [method, setMethod] = useState<'bank' | 'upi' | 'paypal'>(a.payout.method ?? 'upi')
  const [d, setD] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const field = (k: string, label: string) => (
    <div className="grid gap-1">
      <label htmlFor={`afp-${k}`} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={`afp-${k}`}
        autoComplete="off"
        value={d[k] ?? ''}
        onChange={(e) => setD({ ...d, [k]: e.target.value })}
        aria-invalid={!!errors[`payout_details.${k}`]}
      />
      {errors[`payout_details.${k}`] && (
        <p role="alert" className="text-destructive text-xs">
          {errors[`payout_details.${k}`]}
        </p>
      )}
    </div>
  )
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Payout details</CardTitle>
          <CardDescription>
            Paid monthly once your payable balance reaches{' '}
            {Object.entries(a.payout.minimum)
              .map(([c, v]) => formatMinor(v, c))
              .join(' / ')}
            . Stored encrypted.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          {a.payout.summary && (
            <p className="bg-muted rounded-md p-3 text-sm">
              On file: <span className="font-medium">{a.payout.summary}</span>
            </p>
          )}
          <form
            className="grid gap-3"
            onSubmit={async (e) => {
              e.preventDefault()
              setSaving(true)
              setErrors({})
              try {
                await affiliateApi.updatePayout({ payout_method: method, payout_details: d })
                toast.success('Payout details saved')
                setD({})
                void qc.invalidateQueries({ queryKey: ['affiliate', 'me'] })
              } catch (err) {
                if (err instanceof ApiError && err.isValidation)
                  setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
                else toast.error(err instanceof Error ? err.message : 'Could not save')
              } finally {
                setSaving(false)
              }
            }}
          >
            <fieldset className="flex flex-wrap gap-4 text-sm">
              <legend className="mb-1 text-sm font-medium">Method</legend>
              {(['upi', 'bank', 'paypal'] as const).map((m) => (
                <label key={m} className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="afp-method"
                    checked={method === m}
                    onChange={() => setMethod(m)}
                  />
                  {m === 'bank' ? 'Bank (India)' : m === 'upi' ? 'UPI' : 'PayPal'}
                </label>
              ))}
            </fieldset>
            {method === 'upi' && field('upi_id', 'UPI ID')}
            {method === 'bank' && (
              <>
                {field('account_name', 'Account holder')}
                {field('account_number', 'Account number')}
                {field('ifsc', 'IFSC')}
              </>
            )}
            {method === 'paypal' && field('paypal_email', 'PayPal email')}
            <Button type="submit" loading={saving} className="justify-self-start">
              Save
            </Button>
          </form>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Payout history</CardTitle>
        </CardHeader>
        <CardContent>
          {data?.length ? (
            <ul className="divide-y text-sm">
              {data.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 py-2">
                  <span>
                    {p.period}
                    <span className="text-muted-foreground block text-xs">
                      {p.commissions_count} commissions · {p.method}
                      {p.reference ? ` · Ref ${p.reference}` : ''}
                    </span>
                  </span>
                  <span className="text-right">
                    <span className="font-semibold tabular-nums">{formatMinor(p.amount, p.currency)}</span>
                    <Badge
                      variant={p.status === 'paid' ? 'default' : 'secondary'}
                      className="ml-2 capitalize"
                    >
                      {p.status}
                    </Badge>
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-muted-foreground text-sm">No payouts yet.</p>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function Assets() {
  const { data } = useQuery({ queryKey: ['affiliate', 'assets'], queryFn: affiliateApi.assets })
  if (!data) return <Skeleton className="h-40" />
  return (
    <div className="grid gap-6">
      <section aria-labelledby="banners-h" className="grid gap-3">
        <h2 id="banners-h" className="font-semibold">
          Banners
        </h2>
        <div className="grid gap-4 md:grid-cols-2">
          {data.banners.map((b) => (
            <Card key={b.size}>
              <CardContent className="grid gap-2 p-4">
                <img
                  src={b.url}
                  alt={`Banner ${b.size}`}
                  className="max-h-48 w-auto self-start rounded border object-contain"
                />
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">{b.size}</span>
                  <Button size="sm" variant="ghost" asChild>
                    <a href={b.url} download={`banner-${b.size}.svg`}>
                      <Download aria-hidden /> SVG
                    </a>
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </section>
      <section aria-labelledby="copy-h" className="grid gap-3">
        <h2 id="copy-h" className="font-semibold">
          Swipe copy
        </h2>
        {data.swipe_copy.map((c) => (
          <Card key={c.title}>
            <CardContent className="grid gap-2 p-4">
              <div className="flex items-center justify-between">
                <p className="font-medium">{c.title}</p>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() =>
                    navigator.clipboard.writeText(c.body).then(
                      () => toast.success('Copied'),
                      () => toast.error('Copy failed'),
                    )
                  }
                >
                  <Copy aria-hidden /> Copy
                </Button>
              </div>
              <p className="bg-muted rounded-md p-3 text-sm whitespace-pre-wrap">{c.body}</p>
            </CardContent>
          </Card>
        ))}
      </section>
    </div>
  )
}
