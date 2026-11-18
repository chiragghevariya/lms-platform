import { ApiError } from '@/lib/api-client'
import type { Banner, Post } from '../types'

export const fieldClass =
  'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none'
export const selectClass = 'border-input bg-background h-10 rounded-md border px-2 text-sm'

export type FieldErrors = Record<string, string>

/** Flattens Laravel 422 errors to `{ "content.title": "…" }`; other errors under `_`. */
export function errorsOf(e: unknown): FieldErrors {
  if (e instanceof ApiError && e.isValidation) {
    const out: FieldErrors = {}
    for (const [k, v] of Object.entries(e.errors)) out[k] = v[0] ?? e.message
    return Object.keys(out).length ? out : { _: e.message }
  }
  if (e instanceof ApiError && e.status === 403)
    return { _: e.message || 'You are not allowed to change this.' }
  return { _: e instanceof Error ? e.message : 'Something went wrong.' }
}

/** Props wiring a control to its <Field> label/error. */
export const aria = (id: string, error?: string) => ({
  id,
  'aria-invalid': error ? true : undefined,
  'aria-describedby': error ? `${id}-error` : undefined,
})

/** ISO ↔ <input type="datetime-local"> value in the browser's time zone. */
export function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
export const fromLocalInput = (v: string): string | null => (v ? new Date(v).toISOString() : null)

/** live | scheduled | expired | off — from the server's is_live plus the dates. */
export function bannerState(b: Banner, now = Date.now()): 'live' | 'scheduled' | 'expired' | 'off' {
  if (b.is_live) return 'live'
  if (!b.is_active) return 'off'
  if (b.ends_at && new Date(b.ends_at).getTime() <= now) return 'expired'
  if (b.starts_at && new Date(b.starts_at).getTime() > now) return 'scheduled'
  return 'off'
}

export function postState(p: Pick<Post, 'status' | 'published_at'>): 'draft' | 'scheduled' | 'published' {
  if (p.status === 'draft') return 'draft'
  return p.published_at && new Date(p.published_at).getTime() > Date.now() ? 'scheduled' : 'published'
}

/** Terms/Privacy have built-in routes; any other CMS page lives at /p/{slug}. */
export const publicPath = (slug: string) =>
  slug === 'terms' || slug === 'privacy' ? `/${slug}` : `/p/${slug}`

/**
 * Body for PUT /admin/settings/{group}: plain fields as-is; secrets only when a new value was
 * typed (blank = keep the stored one); `clear` lists secrets to remove (fall back to .env).
 */
export function settingsPayload(
  plain: Record<string, unknown>,
  secrets: Record<string, string>,
  clear: string[],
): Record<string, unknown> {
  const body: Record<string, unknown> = { ...plain }
  for (const [k, v] of Object.entries(secrets)) if (v.trim() !== '') body[k] = v.trim()
  const toClear = clear.filter((k) => !(k in body))
  if (toClear.length) body.clear = toClear
  return body
}
