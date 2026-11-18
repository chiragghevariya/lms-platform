import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cmsApi } from '../api'
import type { CmsSection, Cta, SectionContent, SectionKey } from '../types'
import { Field, Toggle } from './shared'
import { aria, errorsOf, fieldClass, type FieldErrors } from './utils'

type TextField = 'badge' | 'eyebrow' | 'title' | 'highlight' | 'subtitle' | 'intro'

/** Which fields each fixed section actually renders on the homepage. */
const FIELDS: Record<
  SectionKey,
  { text: TextField[]; ctas: ('primary_cta' | 'secondary_cta')[]; items: boolean }
> = {
  hero: {
    text: ['badge', 'title', 'highlight', 'subtitle'],
    ctas: ['primary_cta', 'secondary_cta'],
    items: true,
  },
  why: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: true },
  featured: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: false },
  pricing: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: false },
  instructors: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: false },
  testimonials: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: false },
  faq: { text: ['eyebrow', 'title', 'intro'], ctas: [], items: false },
  cta: { text: ['title', 'subtitle'], ctas: ['primary_cta'], items: false },
}

const SECTION_LABEL: Record<SectionKey, string> = {
  hero: 'Hero',
  why: 'Why us',
  featured: 'Featured courses',
  pricing: 'Pricing teaser',
  instructors: 'Mentors',
  testimonials: 'Testimonials',
  faq: 'FAQ',
  cta: 'Closing call to action',
}

const TEXT_LABEL: Record<TextField, string> = {
  badge: 'Badge',
  eyebrow: 'Eyebrow (small label)',
  title: 'Heading',
  highlight: 'Highlighted words',
  subtitle: 'Subtitle',
  intro: 'Intro',
}

const NOTE: Partial<Record<SectionKey, string>> = {
  featured: 'Shows courses marked “featured” in Courses.',
  pricing: 'Shows the active plans from Plans & prices.',
  instructors: 'Shows featured instructors.',
  testimonials: 'Content comes from Website → Testimonials; hidden when none are published.',
  faq: 'Questions come from Website → FAQs.',
}

export default function AdminHomepagePage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'cms', 'sections'], queryFn: cmsApi.sections })
  const [busy, setBusy] = useState(false)

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['admin', 'cms', 'sections'] })
    qc.invalidateQueries({ queryKey: ['cms', 'home'] })
  }

  async function move(index: number, dir: -1 | 1) {
    if (!data) return
    const a = data[index]
    const b = data[index + dir]
    if (!a || !b) return
    setBusy(true)
    try {
      // Give every section its list position, swapping the two being moved.
      const order = data.map((s) => s.key)
      ;[order[index], order[index + dir]] = [order[index + dir]!, order[index]!]
      await Promise.all(
        data
          .filter((s) => order.indexOf(s.key) !== s.sort)
          .map((s) => cmsApi.updateSection(s.key, { sort: order.indexOf(s.key) })),
      )
      refresh()
    } catch {
      toast.error('Could not reorder sections.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Seo title="Homepage" noindex />
      <PageHeader
        title="Homepage"
        description="Edit the copy, order and visibility of each homepage section. Untouched fields keep the built-in text."
        actions={
          <Button variant="outline" asChild>
            <a href="/" target="_blank" rel="noopener">
              <ExternalLink aria-hidden /> Preview homepage
            </a>
          </Button>
        }
      />
      {isLoading || !data ? (
        <Skeleton className="h-96" />
      ) : (
        <ol className="grid gap-4">
          {data.map((s, i) => (
            <li key={s.key}>
              <SectionEditor
                section={s}
                first={i === 0}
                last={i === data.length - 1}
                busy={busy}
                onMove={(dir) => move(i, dir)}
                onSaved={refresh}
              />
            </li>
          ))}
        </ol>
      )}
    </>
  )
}

function SectionEditor({
  section,
  first,
  last,
  busy,
  onMove,
  onSaved,
}: {
  section: CmsSection
  first: boolean
  last: boolean
  busy: boolean
  onMove: (dir: -1 | 1) => void
  onSaved: () => void
}) {
  const spec = FIELDS[section.key]
  const [open, setOpen] = useState(false)
  const [c, setC] = useState<SectionContent>(section.content)
  const [visible, setVisible] = useState(section.is_visible)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const id = (f: string) => `sec-${section.key}-${f}`

  const setCta = (k: 'primary_cta' | 'secondary_cta', patch: Partial<Cta>) =>
    setC((s) => ({ ...s, [k]: { label: '', url: '', ...(s[k] ?? {}), ...patch } }))
  const items = c.items ?? []
  const setItems = (next: typeof items) => setC((s) => ({ ...s, items: next }))

  /** Visibility only — unsaved copy edits stay in the form. */
  async function toggleVisible(next: boolean) {
    setVisible(next)
    try {
      await cmsApi.updateSection(section.key, { is_visible: next })
      toast.success(`${SECTION_LABEL[section.key]} ${next ? 'shown' : 'hidden'}`)
      onSaved()
    } catch {
      setVisible(!next)
      toast.error('Could not change visibility.')
    }
  }

  async function save(nextVisible = visible) {
    setSaving(true)
    setErrors({})
    try {
      const content: SectionContent = {}
      for (const f of spec.text) content[f] = c[f] ?? null
      for (const k of spec.ctas) {
        const v = c[k]
        content[k] = v && (v.label || v.url) ? v : null
      }
      if (spec.items) content.items = items.filter((it) => it.title.trim())
      await cmsApi.updateSection(section.key, { content, is_visible: nextVisible })
      toast.success(`${SECTION_LABEL[section.key]} saved`)
      onSaved()
    } catch (e) {
      setErrors(errorsOf(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center gap-3 space-y-0">
        <CardTitle className="text-base">{SECTION_LABEL[section.key]}</CardTitle>
        <Badge variant={section.customised ? 'default' : 'outline'}>
          {section.customised ? 'Customised' : 'Default'}
        </Badge>
        {!section.is_visible && <Badge variant="secondary">Hidden</Badge>}
        <div className="ml-auto flex items-center gap-2">
          <div className="text-muted-foreground flex items-center gap-2 text-sm">
            Visible
            <Toggle
              checked={visible}
              label={`Show ${SECTION_LABEL[section.key]} section`}
              disabled={saving}
              onChange={(v) => void toggleVisible(v)}
            />
          </div>
          <Button
            size="icon"
            variant="ghost"
            disabled={first || busy}
            onClick={() => onMove(-1)}
            aria-label="Move up"
          >
            <ArrowUp aria-hidden />
          </Button>
          <Button
            size="icon"
            variant="ghost"
            disabled={last || busy}
            onClick={() => onMove(1)}
            aria-label="Move down"
          >
            <ArrowDown aria-hidden />
          </Button>
          <Button size="sm" variant="outline" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
            {open ? 'Close' : 'Edit'}
          </Button>
        </div>
      </CardHeader>
      {open && (
        <CardContent className="grid gap-4">
          {NOTE[section.key] && <p className="text-muted-foreground text-sm">{NOTE[section.key]}</p>}
          <div className="grid gap-4 sm:grid-cols-2">
            {spec.text.map((f) => {
              const err = errors[`content.${f}`]
              const long = f === 'subtitle' || f === 'intro'
              return (
                <Field
                  key={f}
                  id={id(f)}
                  label={TEXT_LABEL[f]}
                  error={err}
                  className={long ? 'sm:col-span-2' : undefined}
                >
                  {long ? (
                    <textarea
                      {...aria(id(f), err)}
                      rows={3}
                      className={fieldClass}
                      value={c[f] ?? ''}
                      onChange={(e) => setC((s) => ({ ...s, [f]: e.target.value }))}
                    />
                  ) : (
                    <Input
                      {...aria(id(f), err)}
                      value={c[f] ?? ''}
                      onChange={(e) => setC((s) => ({ ...s, [f]: e.target.value }))}
                    />
                  )}
                </Field>
              )
            })}
          </div>

          {spec.ctas.map((k) => (
            <fieldset key={k} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
              <legend className="px-1 text-sm font-medium">
                {k === 'primary_cta' ? 'Main button' : 'Second button'}
              </legend>
              <Field id={id(`${k}-label`)} label="Button text" error={errors[`content.${k}.label`]}>
                <Input
                  {...aria(id(`${k}-label`), errors[`content.${k}.label`])}
                  value={c[k]?.label ?? ''}
                  onChange={(e) => setCta(k, { label: e.target.value })}
                />
              </Field>
              <Field
                id={id(`${k}-url`)}
                label="Link"
                hint="A path like /register or a full https:// URL"
                error={errors[`content.${k}.url`]}
              >
                <Input
                  {...aria(id(`${k}-url`), errors[`content.${k}.url`])}
                  value={c[k]?.url ?? ''}
                  onChange={(e) => setCta(k, { url: e.target.value })}
                />
              </Field>
            </fieldset>
          ))}

          {spec.items && (
            <fieldset className="grid gap-3">
              <legend className="mb-2 text-sm font-medium">
                {section.key === 'hero' ? 'Highlight cards' : 'Points'} ({items.length}/8)
              </legend>
              {items.map((it, i) => (
                <div
                  key={i}
                  className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end"
                >
                  <Field id={id(`item-${i}-title`)} label="Title" error={errors[`content.items.${i}.title`]}>
                    <Input
                      {...aria(id(`item-${i}-title`), errors[`content.items.${i}.title`])}
                      value={it.title}
                      onChange={(e) =>
                        setItems(items.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))
                      }
                    />
                  </Field>
                  <Field id={id(`item-${i}-body`)} label="Text" error={errors[`content.items.${i}.body`]}>
                    <Input
                      {...aria(id(`item-${i}-body`), errors[`content.items.${i}.body`])}
                      value={it.body ?? ''}
                      onChange={(e) =>
                        setItems(items.map((x, j) => (j === i ? { ...x, body: e.target.value } : x)))
                      }
                    />
                  </Field>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove item ${i + 1}`}
                    onClick={() => setItems(items.filter((_, j) => j !== i))}
                  >
                    <Trash2 aria-hidden />
                  </Button>
                </div>
              ))}
              <Button
                variant="outline"
                size="sm"
                className="justify-self-start"
                disabled={items.length >= 8}
                onClick={() => setItems([...items, { title: '', body: '' }])}
              >
                <Plus aria-hidden /> Add item
              </Button>
            </fieldset>
          )}

          {errors._ && (
            <p role="alert" className="text-destructive text-sm">
              {errors._}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Button
              variant="ghost"
              onClick={() => {
                setC(section.content)
                setErrors({})
              }}
            >
              Discard changes
            </Button>
            <Button loading={saving} onClick={() => void save()}>
              Save section
            </Button>
          </div>
        </CardContent>
      )}
    </Card>
  )
}
