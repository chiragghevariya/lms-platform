import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Megaphone, Send } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { notificationsAdminApi } from '@/features/notifications/api'
import { ApiError } from '@/lib/api-client'
import { CHANNEL_LABEL, type CampaignRow, type ChannelKey, type Segment } from '@/types/notifications'

const TIERS = ['free', 'starter', 'pro', 'elite']
const STATUSES = ['active', 'past_due', 'cancelled', 'none']

export default function AdminCampaignsPage() {
  const { data } = useQuery({
    queryKey: ['admin', 'campaigns'],
    queryFn: notificationsAdminApi.campaigns,
    refetchInterval: 15_000,
  })
  const [editing, setEditing] = useState<CampaignRow | 'new' | null>(null)
  return (
    <>
      <Seo title="Campaigns" noindex />
      <PageHeader
        title="Campaigns"
        description="Broadcast to a segment by email, WhatsApp and in-app. Sending is queued and rate-limited; everyone's preferences are respected."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Megaphone aria-hidden /> New campaign
          </Button>
        }
      />
      {!data ? (
        <Skeleton className="h-40" />
      ) : data.length === 0 ? (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">No campaigns yet.</CardContent>
        </Card>
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th className="p-3 font-medium">Campaign</th>
                <th className="p-3 font-medium">Status</th>
                <th className="p-3 font-medium">Audience</th>
                <th className="p-3 font-medium">Delivery</th>
                <th className="p-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.map((c) => (
                <tr key={c.id}>
                  <td className="p-3">
                    <p className="font-medium">{c.name}</p>
                    <p className="text-muted-foreground text-xs">
                      {c.channels.map((ch) => CHANNEL_LABEL[ch]).join(' · ')} · by {c.created_by}
                    </p>
                  </td>
                  <td className="p-3">
                    <Badge variant={c.status === 'sent' ? 'default' : 'secondary'} className="capitalize">
                      {c.status}
                    </Badge>
                    {c.status === 'scheduled' && c.scheduled_at && (
                      <span className="text-muted-foreground block text-xs">
                        {new Date(c.scheduled_at).toLocaleString()}
                      </span>
                    )}
                  </td>
                  <td className="p-3 tabular-nums">{c.audience_count || '—'}</td>
                  <td className="p-3 text-xs">
                    {Object.entries(c.stats).map(([ch, s]) => (
                      <div key={ch}>
                        <span className="font-medium">{CHANNEL_LABEL[ch as ChannelKey]}</span>:{' '}
                        {Object.entries(s ?? {})
                          .map(([k, n]) => `${n} ${k}`)
                          .join(', ')}
                      </div>
                    ))}
                  </td>
                  <td className="p-3 text-right">
                    {c.status === 'draft' && (
                      <Button size="sm" variant="outline" onClick={() => setEditing(c)}>
                        Edit
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && (
        <CampaignDialog initial={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
      )}
    </>
  )
}

function CampaignDialog({ initial, onClose }: { initial: CampaignRow | null; onClose: () => void }) {
  const qc = useQueryClient()
  const [id, setId] = useState(initial?.id ?? null)
  const [v, setV] = useState({
    name: initial?.name ?? '',
    channels: initial?.channels ?? (['mail', 'database'] as ChannelKey[]),
    subject: initial?.subject ?? '',
    body: initial?.body ?? '',
    action_text: initial?.action_text ?? '',
    action_url: initial?.action_url ?? '',
    whatsapp_template: initial?.whatsapp_template ?? '',
    whatsapp_params: (initial?.whatsapp_params ?? []).join(', '),
  })
  const [seg, setSeg] = useState<Segment>(initial?.segment ?? { tiers: [], status: [], countries: [] })
  const [countries, setCountries] = useState((initial?.segment.countries ?? []).join(', '))
  const [when, setWhen] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const segment: Segment = {
    ...seg,
    countries: countries
      .split(',')
      .map((s) => s.trim().toUpperCase())
      .filter((s) => s.length === 2),
  }
  const audience = useQuery({
    queryKey: ['admin', 'audience', JSON.stringify(segment)],
    queryFn: () => notificationsAdminApi.audience(segment),
  })

  const toggleIn = (k: 'tiers' | 'status', val: string) =>
    setSeg((s) => {
      const cur: string[] = s[k] ?? []
      return { ...s, [k]: cur.includes(val) ? cur.filter((x) => x !== val) : [...cur, val] }
    })

  const persist = async () => {
    const saved = await notificationsAdminApi.saveCampaign(id, {
      ...v,
      action_url: v.action_url || null,
      action_text: v.action_text || null,
      whatsapp_template: v.whatsapp_template || null,
      whatsapp_params: v.whatsapp_params
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean),
      segment,
    })
    setId(saved.id)
    void qc.invalidateQueries({ queryKey: ['admin', 'campaigns'] })
    return saved.id
  }
  const run = async (kind: 'save' | 'test' | 'send' | 'schedule') => {
    setBusy(kind)
    setErrors({})
    try {
      const cid = await persist()
      if (kind === 'test') toast.success(`Test sent to ${(await notificationsAdminApi.test(cid)).sent_to}`)
      if (kind === 'send') {
        await notificationsAdminApi.schedule(cid)
        toast.success('Sending…')
        onClose()
      }
      if (kind === 'schedule') {
        await notificationsAdminApi.schedule(cid, new Date(when).toISOString())
        toast.success('Scheduled')
        onClose()
      }
      if (kind === 'save') toast.success('Draft saved')
      void qc.invalidateQueries({ queryKey: ['admin', 'campaigns'] }) // show sending/scheduled straight away
    } catch (e) {
      if (e instanceof ApiError && e.isValidation)
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else toast.error(e instanceof Error ? e.message : 'Failed')
    } finally {
      setBusy(null)
    }
  }
  const err = (k: string) =>
    errors[k] && (
      <p role="alert" className="text-destructive text-xs">
        {errors[k]}
      </p>
    )
  const field = 'border-input bg-background rounded-md border px-3 py-2 text-sm'

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{initial ? 'Edit campaign' : 'New campaign'}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-5">
          <div className="grid gap-1">
            <label htmlFor="cp-name" className="text-sm font-medium">
              Internal name
            </label>
            <Input id="cp-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />
            {err('name')}
          </div>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm">Audience</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <fieldset className="flex flex-wrap gap-3">
                <legend className="mb-1 font-medium">Tier</legend>
                {TIERS.map((t) => (
                  <label key={t} className="flex items-center gap-1 capitalize">
                    <input
                      type="checkbox"
                      checked={seg.tiers?.includes(t) ?? false}
                      onChange={() => toggleIn('tiers', t)}
                    />
                    {t}
                  </label>
                ))}
              </fieldset>
              <fieldset className="flex flex-wrap gap-3">
                <legend className="mb-1 font-medium">Paid subscription status</legend>
                {STATUSES.map((t) => (
                  <label key={t} className="flex items-center gap-1">
                    {
                      <input
                        type="checkbox"
                        checked={seg.status?.includes(t) ?? false}
                        onChange={() => toggleIn('status', t)}
                      />
                    }
                    {t.replace('_', ' ')}
                  </label>
                ))}
              </fieldset>
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1">
                  <label htmlFor="cp-inactive" className="font-medium">
                    Inactive for at least (days)
                  </label>
                  <Input
                    id="cp-inactive"
                    type="number"
                    min={1}
                    value={seg.inactive_days ?? ''}
                    onChange={(e) =>
                      setSeg({ ...seg, inactive_days: e.target.value ? Number(e.target.value) : null })
                    }
                  />
                </div>
                <div className="grid gap-1">
                  <label htmlFor="cp-countries" className="font-medium">
                    Countries (ISO codes)
                  </label>
                  <Input
                    id="cp-countries"
                    placeholder="IN, US"
                    value={countries}
                    onChange={(e) => setCountries(e.target.value)}
                  />
                </div>
              </div>
              <p className="font-medium" role="status">
                {audience.data
                  ? `${audience.data.count.toLocaleString()} people match · ${audience.data.whatsapp_reachable.toLocaleString()} reachable on WhatsApp`
                  : 'Counting…'}
              </p>
            </CardContent>
          </Card>
          <fieldset className="flex flex-wrap gap-4 text-sm">
            <legend className="mb-1 text-sm font-medium">Channels</legend>
            {(['mail', 'database', 'whatsapp', 'push'] as ChannelKey[]).map((ch) => (
              <label key={ch} className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={v.channels.includes(ch)}
                  onChange={() =>
                    setV({
                      ...v,
                      channels: v.channels.includes(ch)
                        ? v.channels.filter((x) => x !== ch)
                        : [...v.channels, ch],
                    })
                  }
                />
                {CHANNEL_LABEL[ch]}
              </label>
            ))}
          </fieldset>
          {err('channels')}
          <div className="grid gap-1">
            <label htmlFor="cp-subject" className="text-sm font-medium">
              Subject / title
            </label>
            <Input
              id="cp-subject"
              maxLength={200}
              value={v.subject}
              onChange={(e) => setV({ ...v, subject: e.target.value })}
            />
            {err('subject')}
            <p className="text-muted-foreground text-xs">Use {'{{name}}'} for the recipient's first name.</p>
          </div>
          {(v.channels.includes('mail') || v.channels.includes('database')) && (
            <div className="grid gap-1">
              <label htmlFor="cp-body" className="text-sm font-medium">
                Message (Markdown)
              </label>
              <textarea
                id="cp-body"
                rows={7}
                className={field}
                value={v.body}
                onChange={(e) => setV({ ...v, body: e.target.value })}
              />
              {err('body')}
              <div className="grid gap-2 sm:grid-cols-2">
                <Input
                  aria-label="Button text"
                  placeholder="Button text"
                  value={v.action_text}
                  onChange={(e) => setV({ ...v, action_text: e.target.value })}
                />
                <Input
                  aria-label="Button link"
                  type="url"
                  placeholder="https://…"
                  value={v.action_url}
                  onChange={(e) => setV({ ...v, action_url: e.target.value })}
                />
              </div>
              {err('action_url')}
            </div>
          )}
          {v.channels.includes('whatsapp') && (
            <div className="grid gap-2 sm:grid-cols-2">
              <div className="grid gap-1">
                <label htmlFor="cp-wa" className="text-sm font-medium">
                  Approved WhatsApp template
                </label>
                <Input
                  id="cp-wa"
                  placeholder="diwali_offer"
                  value={v.whatsapp_template}
                  onChange={(e) => setV({ ...v, whatsapp_template: e.target.value })}
                />
                {err('whatsapp_template')}
              </div>
              <div className="grid gap-1">
                <label htmlFor="cp-wap" className="text-sm font-medium">
                  Template variables
                </label>
                <Input
                  id="cp-wap"
                  placeholder="{{name}}, 30%"
                  value={v.whatsapp_params}
                  onChange={(e) => setV({ ...v, whatsapp_params: e.target.value })}
                />
              </div>
            </div>
          )}
          <div className="grid gap-1 sm:max-w-xs">
            <label htmlFor="cp-when" className="text-sm font-medium">
              Schedule (optional)
            </label>
            <Input
              id="cp-when"
              type="datetime-local"
              value={when}
              onChange={(e) => setWhen(e.target.value)}
            />
            {err('scheduled_at')}
          </div>
        </div>
        <DialogFooter className="flex-wrap gap-2">
          <Button variant="ghost" loading={busy === 'save'} onClick={() => void run('save')}>
            Save draft
          </Button>
          <Button variant="outline" loading={busy === 'test'} onClick={() => void run('test')}>
            Send test to me
          </Button>
          {when ? (
            <Button loading={busy === 'schedule'} onClick={() => void run('schedule')}>
              Schedule
            </Button>
          ) : (
            <Button
              loading={busy === 'send'}
              disabled={!audience.data?.count}
              onClick={() => void run('send')}
            >
              <Send aria-hidden /> Send to {audience.data?.count ?? 0}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
