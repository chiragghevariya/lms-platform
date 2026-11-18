import { useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { notificationsAdminApi } from '@/features/notifications/api'
import { ApiError } from '@/lib/api-client'
import { cn } from '@/lib/utils'
import { CHANNEL_LABEL, type ChannelKey, type TemplateRow } from '@/types/notifications'

export default function AdminTemplatesPage() {
  const { data } = useQuery({ queryKey: ['admin', 'templates'], queryFn: notificationsAdminApi.templates })
  const [event, setEvent] = useState<string | null>(null)
  const current = data?.find((t) => t.event === (event ?? data[0]?.event))

  return (
    <>
      <Seo title="Notification templates" noindex />
      <PageHeader
        title="Notification templates"
        description="Edit the copy for each event and channel. WhatsApp messages use templates pre-approved by Meta."
      />
      {!data ? (
        <Skeleton className="h-96" />
      ) : (
        <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
          <nav aria-label="Events" className="max-h-[75dvh] overflow-y-auto rounded-xl border">
            {Object.entries(groupByCategory(data)).map(([cat, rows]) => (
              <div key={cat}>
                <p className="bg-muted/50 px-3 py-1.5 text-xs font-semibold tracking-wide uppercase">{cat}</p>
                <ul>
                  {rows.map((t) => (
                    <li key={t.event}>
                      <button
                        type="button"
                        onClick={() => setEvent(t.event)}
                        aria-current={current?.event === t.event}
                        className={cn(
                          'hover:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm',
                          current?.event === t.event && 'bg-secondary font-medium',
                        )}
                      >
                        {t.label}
                        {t.channels.some((c) => c.customised) && (
                          <span className="bg-primary size-1.5 rounded-full" title="Customised" />
                        )}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
          {current && <TemplateEditor key={current.event} t={current} />}
        </div>
      )}
    </>
  )
}

function groupByCategory(rows: TemplateRow[]): Record<string, TemplateRow[]> {
  return rows.reduce<Record<string, TemplateRow[]>>((acc, t) => {
    ;(acc[t.category] ??= []).push(t)
    return acc
  }, {})
}

function TemplateEditor({ t }: { t: TemplateRow }) {
  const [channel, setChannel] = useState<ChannelKey>(t.channels[0]!.channel)
  const c = t.channels.find((x) => x.channel === channel)!
  return (
    <div className="grid content-start gap-4">
      <div>
        <h2 className="text-lg font-semibold">{t.label}</h2>
        <p className="text-muted-foreground font-mono text-xs">{t.event}</p>
      </div>
      <div role="tablist" aria-label="Channel" className="flex flex-wrap gap-1 border-b">
        {t.channels.map((x) => (
          <button
            key={x.channel}
            role="tab"
            aria-selected={channel === x.channel}
            onClick={() => setChannel(x.channel)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
              channel === x.channel ? 'border-primary' : 'text-muted-foreground border-transparent',
            )}
          >
            {CHANNEL_LABEL[x.channel]}{' '}
            {x.customised && (
              <Badge variant="secondary" className="ml-1 h-4 px-1 text-[10px]">
                edited
              </Badge>
            )}
          </button>
        ))}
      </div>
      <ChannelForm key={channel} event={t.event} vars={t.vars} c={c} />
    </div>
  )
}

function ChannelForm({
  event,
  vars,
  c,
}: {
  event: string
  vars: string[]
  c: TemplateRow['channels'][number]
}) {
  const qc = useQueryClient()
  const [v, setV] = useState({
    subject: c.subject ?? '',
    body: c.body ?? '',
    action_text: c.action_text ?? '',
    whatsapp_template: c.whatsapp_template ?? '',
    whatsapp_params: c.whatsapp_params.join(', '),
  })
  const [preview, setPreview] = useState<{
    subject: string
    body: string
    html: boolean
    unknown_vars: string[]
  } | null>(null)
  const [saving, setSaving] = useState(false)
  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const isMail = c.channel === 'mail'
  const isWa = c.channel === 'whatsapp'
  const hasSubject = ['mail', 'database', 'push'].includes(c.channel)
  const varChips = useMemo(() => [...new Set([...vars, 'name', 'url'])], [vars])

  useEffect(() => {
    if (isWa) return
    const id = window.setTimeout(() => {
      notificationsAdminApi
        .preview(event, { channel: c.channel, subject: v.subject, body: v.body || v.subject })
        .then(setPreview, () => {})
    }, 400)
    return () => window.clearTimeout(id)
  }, [event, c.channel, v.subject, v.body, isWa])

  const insert = (name: string) => {
    const el = bodyRef.current
    const token = `{{${name}}}`
    if (!el) return
    const [a, b] = [el.selectionStart, el.selectionEnd]
    setV((s) => ({ ...s, body: s.body.slice(0, a) + token + s.body.slice(b) }))
    requestAnimationFrame(() => {
      el.focus()
      el.setSelectionRange(a + token.length, a + token.length)
    })
  }

  const save = async () => {
    setSaving(true)
    try {
      await notificationsAdminApi.saveTemplate(
        event,
        c.channel,
        isWa
          ? {
              whatsapp_template: v.whatsapp_template || null,
              whatsapp_params: v.whatsapp_params
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
            }
          : {
              subject: hasSubject ? v.subject || null : null,
              body: v.body || null,
              action_text: isMail ? v.action_text || null : null,
            },
      )
      toast.success('Template saved')
      void qc.invalidateQueries({ queryKey: ['admin', 'templates'] })
    } catch (e) {
      toast.error(
        e instanceof ApiError && e.isValidation
          ? (Object.values(e.errors).flat()[0] ?? e.message)
          : e instanceof Error
            ? e.message
            : 'Could not save',
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      <Card>
        <CardContent className="grid gap-3 p-4">
          {isWa ? (
            <>
              <p className="text-muted-foreground text-sm">
                WhatsApp business-initiated messages must use a template approved in Meta's WhatsApp Manager.
                Enter its name and the variables for {'{{1}}, {{2}}…'} in order.
              </p>
              <label htmlFor="tp-wa" className="text-sm font-medium">
                Template name
              </label>
              <Input
                id="tp-wa"
                placeholder="certificate_issued"
                value={v.whatsapp_template}
                onChange={(e) => setV({ ...v, whatsapp_template: e.target.value })}
              />
              <label htmlFor="tp-wap" className="text-sm font-medium">
                Body variables (comma-separated)
              </label>
              <Input
                id="tp-wap"
                placeholder="name, course, url"
                value={v.whatsapp_params}
                onChange={(e) => setV({ ...v, whatsapp_params: e.target.value })}
              />
            </>
          ) : (
            <>
              {hasSubject && (
                <>
                  <label htmlFor="tp-subj" className="text-sm font-medium">
                    {isMail ? 'Subject' : 'Title'}
                  </label>
                  <Input
                    id="tp-subj"
                    maxLength={200}
                    value={v.subject}
                    onChange={(e) => setV({ ...v, subject: e.target.value })}
                  />
                </>
              )}
              <label htmlFor="tp-body" className="text-sm font-medium">
                {isMail ? 'Body (Markdown)' : 'Text'}
              </label>
              {isMail && !c.customised && !c.body && (
                <p className="text-muted-foreground text-xs">
                  Leave empty to keep the built-in email (it may include extras like attachments).
                </p>
              )}
              <textarea
                id="tp-body"
                ref={bodyRef}
                rows={isMail ? 10 : 4}
                maxLength={20000}
                value={v.body}
                onChange={(e) => setV({ ...v, body: e.target.value })}
                className="border-input bg-background rounded-md border px-3 py-2 font-mono text-sm"
              />
              <div className="flex flex-wrap gap-1" aria-label="Insert variable">
                {varChips.map((name) => (
                  <button
                    key={name}
                    type="button"
                    onClick={() => insert(name)}
                    className="bg-secondary text-secondary-foreground rounded px-1.5 py-0.5 font-mono text-xs hover:opacity-80"
                  >{`{{${name}}}`}</button>
                ))}
              </div>
              {isMail && (
                <>
                  <label htmlFor="tp-act" className="text-sm font-medium">
                    Button text
                  </label>
                  <Input
                    id="tp-act"
                    maxLength={60}
                    value={v.action_text}
                    onChange={(e) => setV({ ...v, action_text: e.target.value })}
                  />
                </>
              )}
            </>
          )}
          <div className="flex gap-2">
            <Button loading={saving} onClick={() => void save()} disabled={!!preview?.unknown_vars.length}>
              Save
            </Button>
            {c.customised && (
              <Button
                variant="ghost"
                onClick={() =>
                  notificationsAdminApi.resetTemplate(event, c.channel).then(() => {
                    toast.success('Back to default')
                    void qc.invalidateQueries({ queryKey: ['admin', 'templates'] })
                  })
                }
              >
                <RotateCcw aria-hidden /> Reset to default
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
      {!isWa && (
        <Card>
          <CardContent className="grid gap-2 p-4">
            <p className="text-muted-foreground text-xs font-medium uppercase">Preview (sample data)</p>
            {preview?.unknown_vars.length ? (
              <p role="alert" className="text-destructive text-sm">
                Unknown variables: {preview.unknown_vars.join(', ')}
              </p>
            ) : null}
            {hasSubject && <p className="font-semibold">{preview?.subject}</p>}
            {/* Server-rendered Markdown with HTML input escaped. */}
            {preview?.html ? (
              <div
                className="prose prose-sm dark:prose-invert max-w-none"
                dangerouslySetInnerHTML={{ __html: preview.body }}
              />
            ) : (
              <p className="text-sm whitespace-pre-wrap">{preview?.body}</p>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
