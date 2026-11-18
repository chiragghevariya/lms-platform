import { useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { notificationsAdminApi } from '@/features/notifications/api'
import { cn } from '@/lib/utils'
import { CHANNEL_LABEL, type ChannelKey } from '@/types/notifications'

const STATUSES = ['sent', 'delivered', 'read', 'failed', 'skipped', 'sending'] as const
const sel = 'border-input bg-background h-9 rounded-md border px-2 text-sm'

export default function AdminDeliveryPage() {
  const qc = useQueryClient()
  const [f, setF] = useState({ channel: '', status: '', event: '', q: '', days: 7, page: 1 })
  const params = {
    channel: f.channel || undefined,
    status: f.status || undefined,
    event: f.event || undefined,
    q: f.q || undefined,
    days: f.days,
    page: f.page,
  }
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'logs', params],
    queryFn: () => notificationsAdminApi.logs(params),
    refetchInterval: 20_000,
  })
  const set = (patch: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...patch }))

  return (
    <>
      <Seo title="Delivery reports" noindex />
      <PageHeader
        title="Delivery reports"
        description="Every notification per channel, updated by provider webhooks (sent → delivered → read)."
      />
      {data && (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {(Object.keys(CHANNEL_LABEL) as ChannelKey[]).map((ch) => {
            const s = data.meta.summary[ch] ?? {}
            const total = Object.values(s).reduce((a, b) => a + b, 0)
            return (
              <Card key={ch}>
                <CardContent className="p-4">
                  <p className="text-muted-foreground text-sm">{CHANNEL_LABEL[ch]}</p>
                  <p className="text-2xl font-bold tabular-nums">{total.toLocaleString()}</p>
                  <p className="text-muted-foreground text-xs">
                    {STATUSES.filter((st) => s[st])
                      .map((st) => `${s[st]} ${st}`)
                      .join(' · ') || 'none'}
                  </p>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <select
          aria-label="Channel"
          className={sel}
          value={f.channel}
          onChange={(e) => set({ channel: e.target.value })}
        >
          <option value="">All channels</option>
          {(Object.keys(CHANNEL_LABEL) as ChannelKey[]).map((c) => (
            <option key={c} value={c}>
              {CHANNEL_LABEL[c]}
            </option>
          ))}
        </select>
        <select
          aria-label="Status"
          className={sel}
          value={f.status}
          onChange={(e) => set({ status: e.target.value })}
        >
          <option value="">All statuses</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
        <select
          aria-label="Period"
          className={sel}
          value={f.days}
          onChange={(e) => set({ days: Number(e.target.value) })}
        >
          <option value={1}>Last 24 hours</option>
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
        <Input
          aria-label="Event"
          placeholder="event (e.g. payment_failed)"
          className="w-56"
          value={f.event}
          onChange={(e) => set({ event: e.target.value })}
        />
        <Input
          aria-label="Search user"
          placeholder="name or email"
          className="w-48"
          value={f.q}
          onChange={(e) => set({ q: e.target.value })}
        />
      </div>
      {isLoading ? (
        <Skeleton className="h-60" />
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th className="p-3 font-medium">When</th>
                <th className="p-3 font-medium">Event</th>
                <th className="p-3 font-medium">Channel</th>
                <th className="p-3 font-medium">Recipient</th>
                <th className="p-3 font-medium">Status</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {data?.data.map((l) => (
                <tr key={l.id}>
                  <td className="p-3 text-xs whitespace-nowrap">{new Date(l.created_at).toLocaleString()}</td>
                  <td className="p-3 font-mono text-xs">{l.event}</td>
                  <td className="p-3">
                    {CHANNEL_LABEL[l.channel]}
                    <span className="text-muted-foreground block text-xs">{l.provider}</span>
                  </td>
                  <td className="p-3 text-xs">
                    {l.user?.name}
                    <span className="text-muted-foreground block">{l.recipient ?? l.user?.email}</span>
                  </td>
                  <td className="p-3">
                    <Badge
                      variant="secondary"
                      className={cn(
                        'capitalize',
                        l.status === 'failed' && 'bg-destructive/10 text-destructive',
                        ['delivered', 'read'].includes(l.status) && 'bg-accent/15',
                      )}
                    >
                      {l.status}
                    </Badge>
                    {l.error && (
                      <span className="text-muted-foreground block max-w-64 truncate text-xs" title={l.error}>
                        {l.error.replace(/_/g, ' ')}
                      </span>
                    )}
                    {l.attempts > 1 && (
                      <span className="text-muted-foreground block text-xs">{l.attempts} attempts</span>
                    )}
                  </td>
                  <td className="p-3 text-right">
                    {l.retryable && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          notificationsAdminApi.retry(l.id).then(
                            () => {
                              toast.success('Retry queued')
                              void qc.invalidateQueries({ queryKey: ['admin', 'logs'] })
                            },
                            () => toast.error('Retry failed'),
                          )
                        }
                      >
                        <RefreshCw aria-hidden /> Retry
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {data?.data.length === 0 && (
            <p className="text-muted-foreground p-6 text-center text-sm">No notifications match.</p>
          )}
        </div>
      )}
      {data && data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center gap-2">
          <Button
            size="sm"
            variant="outline"
            disabled={f.page <= 1}
            onClick={() => setF({ ...f, page: f.page - 1 })}
          >
            Previous
          </Button>
          <span className="text-sm">
            Page {f.page} of {data.meta.last_page}
          </span>
          <Button
            size="sm"
            variant="outline"
            disabled={f.page >= data.meta.last_page}
            onClick={() => setF({ ...f, page: f.page + 1 })}
          >
            Next
          </Button>
        </div>
      )}
    </>
  )
}
