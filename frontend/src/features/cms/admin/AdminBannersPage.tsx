import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cmsApi } from '../api'
import type { Banner, BannerAudience, BannerVariant } from '../types'
import { ConfirmDelete, Field, Toggle } from './shared'
import {
  aria,
  bannerState,
  errorsOf,
  fromLocalInput,
  selectClass,
  toLocalInput,
  type FieldErrors,
} from './utils'

const KEY = ['admin', 'cms', 'banners']

const AUDIENCE: Record<BannerAudience, string> = {
  all: 'Everyone',
  guests: 'Visitors (signed out)',
  free: 'Signed in, no membership',
  members: 'Members',
}
const VARIANT: Record<BannerVariant, string> = { info: 'Info', promo: 'Promotion', warning: 'Warning' }

const STATE_LABEL = { live: 'Live', scheduled: 'Scheduled', expired: 'Expired', off: 'Off' } as const

const when = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : null

export default function AdminBannersPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: () => cmsApi.items('banners') })
  const [editing, setEditing] = useState<Banner | 'new' | null>(null)

  const refresh = () => {
    qc.invalidateQueries({ queryKey: KEY })
    qc.invalidateQueries({ queryKey: ['site'] })
  }

  return (
    <>
      <Seo title="Banners" noindex />
      <PageHeader
        title="Banners"
        description="A strip under the site header. Visitors see the newest live banner for their audience and can dismiss it."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden /> New banner
          </Button>
        }
      />
      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : data.length === 0 ? (
        <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center">
          No banners yet.
        </p>
      ) : (
        <ul className="grid gap-2" aria-label="Banners">
          {data.map((b) => {
            const state = bannerState(b)
            return (
              <li key={b.id} className="bg-card flex flex-wrap items-start gap-3 rounded-lg border p-4">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{b.message}</p>
                  <p className="text-muted-foreground mt-1 text-xs">
                    {VARIANT[b.variant]} · {AUDIENCE[b.audience ?? 'all']}
                    {b.link_url && <> · links to {b.link_url}</>}
                    {(b.starts_at || b.ends_at) && (
                      <>
                        {' '}
                        · {when(b.starts_at) ?? 'now'} → {when(b.ends_at) ?? 'no end'}
                      </>
                    )}
                  </p>
                </div>
                <Badge
                  variant={state === 'live' ? 'default' : state === 'scheduled' ? 'secondary' : 'outline'}
                >
                  {STATE_LABEL[state]}
                </Badge>
                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={`Edit banner “${b.message}”`}
                  onClick={() => setEditing(b)}
                >
                  <Pencil aria-hidden />
                </Button>
                <ConfirmDelete
                  title="Delete this banner?"
                  description={b.message}
                  onConfirm={async () => {
                    await cmsApi.deleteItem('banners', b.id)
                    refresh()
                  }}
                >
                  {(open) => (
                    <Button
                      size="icon"
                      variant="ghost"
                      aria-label={`Delete banner “${b.message}”`}
                      onClick={open}
                    >
                      <Trash2 aria-hidden />
                    </Button>
                  )}
                </ConfirmDelete>
              </li>
            )
          })}
        </ul>
      )}
      {editing && (
        <BannerDialog
          banner={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function BannerDialog({
  banner,
  onClose,
  onSaved,
}: {
  banner: Banner | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState({
    message: banner?.message ?? '',
    link_text: banner?.link_text ?? '',
    link_url: banner?.link_url ?? '',
    variant: banner?.variant ?? ('info' as BannerVariant),
    audience: banner?.audience ?? ('all' as BannerAudience),
    starts_at: toLocalInput(banner?.starts_at),
    ends_at: toLocalInput(banner?.ends_at),
    is_active: banner?.is_active ?? true,
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }))

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      ...form,
      link_text: form.link_text || null,
      link_url: form.link_url || null,
      starts_at: fromLocalInput(form.starts_at),
      ends_at: fromLocalInput(form.ends_at),
    }
    try {
      if (banner) await cmsApi.updateItem('banners', banner.id, body)
      else await cmsApi.createItem('banners', body)
      toast.success('Banner saved')
      onSaved()
    } catch (err) {
      setErrors(errorsOf(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{banner ? 'Edit banner' : 'New banner'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <Field id="b-msg" label="Message" error={errors.message}>
            <Input
              {...aria('b-msg', errors.message)}
              value={form.message}
              maxLength={300}
              required
              onChange={(e) => set({ message: e.target.value })}
            />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="b-lt" label="Link text" error={errors.link_text}>
              <Input
                {...aria('b-lt', errors.link_text)}
                value={form.link_text}
                maxLength={60}
                onChange={(e) => set({ link_text: e.target.value })}
              />
            </Field>
            <Field id="b-lu" label="Link" hint="/pricing or https://…" error={errors.link_url}>
              <Input
                {...aria('b-lu', errors.link_url)}
                value={form.link_url}
                onChange={(e) => set({ link_url: e.target.value })}
              />
            </Field>
            <Field id="b-var" label="Style" error={errors.variant}>
              <select
                {...aria('b-var', errors.variant)}
                className={selectClass}
                value={form.variant}
                onChange={(e) => set({ variant: e.target.value as BannerVariant })}
              >
                {Object.entries(VARIANT).map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </Field>
            <Field id="b-aud" label="Show to" error={errors.audience}>
              <select
                {...aria('b-aud', errors.audience)}
                className={selectClass}
                value={form.audience}
                onChange={(e) => set({ audience: e.target.value as BannerAudience })}
              >
                {Object.entries(AUDIENCE).map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </Field>
            <Field id="b-start" label="Starts" hint="Empty = immediately" error={errors.starts_at}>
              <Input
                {...aria('b-start', errors.starts_at)}
                type="datetime-local"
                value={form.starts_at}
                onChange={(e) => set({ starts_at: e.target.value })}
              />
            </Field>
            <Field id="b-end" label="Ends" hint="Empty = until turned off" error={errors.ends_at}>
              <Input
                {...aria('b-end', errors.ends_at)}
                type="datetime-local"
                value={form.ends_at}
                onChange={(e) => set({ ends_at: e.target.value })}
              />
            </Field>
          </div>
          <div className="flex items-center gap-3 text-sm">
            <Toggle checked={form.is_active} label="Active" onChange={(v) => set({ is_active: v })} /> Active
          </div>
          {errors._ && (
            <p role="alert" className="text-destructive text-sm">
              {errors._}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
