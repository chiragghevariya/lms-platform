import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, MessageCircle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { CHANNEL_LABEL, type ChannelKey, type PreferenceMatrix } from '@/types/notifications'
import { notificationsApi } from '../api'

const COLUMNS: ChannelKey[] = ['mail', 'database', 'whatsapp', 'push', 'sms']

export default function NotificationSettingsPage() {
  const qc = useQueryClient()
  const { data } = useQuery({
    queryKey: ['notifications', 'preferences'],
    queryFn: notificationsApi.preferences,
  })

  const toggle = async (event: string, channel: ChannelKey, enabled: boolean) => {
    // Optimistic: flip locally, roll back on error.
    const prev = qc.getQueryData<PreferenceMatrix>(['notifications', 'preferences'])
    qc.setQueryData<PreferenceMatrix>(
      ['notifications', 'preferences'],
      (m) =>
        m && {
          ...m,
          categories: m.categories.map((c) => ({
            ...c,
            events: c.events.map((e) =>
              e.event === event
                ? { ...e, channels: { ...e.channels, [channel]: { ...e.channels[channel]!, enabled } } }
                : e,
            ),
          })),
        },
    )
    try {
      qc.setQueryData(
        ['notifications', 'preferences'],
        await notificationsApi.updatePreferences([{ event, channel, enabled }]),
      )
    } catch {
      qc.setQueryData(['notifications', 'preferences'], prev)
      toast.error('Could not save')
    }
  }

  return (
    <>
      <Seo title="Notification settings" noindex />
      <PageHeader
        title="Notification settings"
        description="Choose how we reach you. Security and billing receipts always go by email."
      />
      {!data ? (
        <Skeleton className="h-96" />
      ) : (
        <div className="grid gap-6">
          <WhatsAppCard m={data} />
          {data.categories.map((c) => {
            const cols = COLUMNS.filter((ch) => c.events.some((e) => e.channels[ch]))
            return (
              <Card key={c.key}>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">{c.label}</CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                  <table className="w-full min-w-[480px] text-sm">
                    <thead className="text-muted-foreground">
                      <tr>
                        <th className="py-2 text-left font-medium">Notification</th>
                        {cols.map((ch) => (
                          <th key={ch} className="w-24 py-2 text-center font-medium">
                            {CHANNEL_LABEL[ch]}
                            {!data.channels[ch] && (
                              <span className="block text-[10px] font-normal">(not available)</span>
                            )}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {c.events.map((e) => (
                        <tr key={e.event}>
                          <th scope="row" className="py-2 text-left font-normal">
                            {e.label}
                          </th>
                          {cols.map((ch) => {
                            const s = e.channels[ch]
                            return (
                              <td key={ch} className="py-2 text-center">
                                {s ? (
                                  s.locked ? (
                                    <span
                                      className="text-muted-foreground inline-flex items-center gap-1 text-xs"
                                      title="Always sent"
                                    >
                                      <Lock className="size-3" aria-hidden />
                                      <span className="sr-only">
                                        {CHANNEL_LABEL[ch]} for {e.label}:{' '}
                                      </span>
                                      Always
                                    </span>
                                  ) : (
                                    <input
                                      type="checkbox"
                                      className="size-4 accent-[var(--primary)]"
                                      checked={s.enabled}
                                      disabled={!data.channels[ch]}
                                      title={
                                        data.channels[ch]
                                          ? undefined
                                          : `${CHANNEL_LABEL[ch]} isn't available yet`
                                      }
                                      aria-label={`${CHANNEL_LABEL[ch]} for ${e.label}`}
                                      onChange={(ev) => void toggle(e.event, ch, ev.target.checked)}
                                    />
                                  )
                                ) : (
                                  <span className="text-muted-foreground" aria-hidden>
                                    —
                                  </span>
                                )}
                              </td>
                            )
                          })}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </CardContent>
              </Card>
            )
          })}
          <p className="text-muted-foreground text-sm">
            Push notifications reach the mobile app on {data.push_devices} device
            {data.push_devices === 1 ? '' : 's'}. WhatsApp and SMS need a verified phone number.
          </p>
        </div>
      )}
    </>
  )
}

function WhatsAppCard({ m }: { m: PreferenceMatrix }) {
  const qc = useQueryClient()
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  if (!m.channels.whatsapp) return null
  const save = async (optIn: boolean) => {
    setBusy(true)
    try {
      await notificationsApi.whatsapp(optIn)
      toast.success(optIn ? 'WhatsApp messages turned on' : 'WhatsApp messages turned off')
      void qc.invalidateQueries({ queryKey: ['notifications', 'preferences'] })
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not save')
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <MessageCircle className="size-4" aria-hidden /> WhatsApp
        </CardTitle>
        <CardDescription>
          {m.whatsapp.opted_in
            ? `On for ${m.whatsapp.phone} since ${new Date(m.whatsapp.opted_in_at!).toLocaleDateString()}. Reply STOP on WhatsApp any time to turn it off.`
            : 'Get class reminders, receipts and certificates on WhatsApp. Off until you opt in.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {m.whatsapp.opted_in ? (
          <Button
            variant="outline"
            className="justify-self-start"
            loading={busy}
            onClick={() => void save(false)}
          >
            Turn off WhatsApp
          </Button>
        ) : !m.whatsapp.phone_verified ? (
          <p className="text-sm">
            <Link to="/verify-phone" className="text-primary underline">
              Verify your phone number
            </Link>{' '}
            first.
          </p>
        ) : (
          <>
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1"
                checked={consent}
                onChange={(e) => setConsent(e.target.checked)}
              />
              I agree to receive messages on WhatsApp at {m.whatsapp.phone}. I can stop them any time.
            </label>
            <Button
              className="justify-self-start"
              disabled={!consent}
              loading={busy}
              onClick={() => void save(true)}
            >
              Turn on WhatsApp
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}
