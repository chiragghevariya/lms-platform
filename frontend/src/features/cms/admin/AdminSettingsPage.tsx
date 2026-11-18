import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, Lock, Plus, Trash2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { cmsApi } from '../api'
import type { SecretStatus, SettingsGroup, SettingsResponse, TaxRate } from '../types'
import { Field, Toggle } from './shared'
import { aria, errorsOf, fieldClass, settingsPayload, type FieldErrors } from './utils'

const KEY = ['admin', 'settings']

type Tab = 'branding' | 'seo' | 'tax' | 'payments' | 'tracking'
const TABS: { id: Tab; label: string }[] = [
  { id: 'branding', label: 'Branding' },
  { id: 'seo', label: 'SEO' },
  { id: 'tax', label: 'Tax & invoices' },
  { id: 'payments', label: 'Payment gateways' },
  { id: 'tracking', label: 'Tracking' },
]

export default function AdminSettingsPage() {
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: cmsApi.settings })
  const [tab, setTab] = useState<Tab>('branding')
  const tabs = TABS.filter((t) => t.id !== 'payments' || data?.can_manage_secrets)

  return (
    <>
      <Seo title="Settings" noindex />
      <PageHeader
        title="Settings"
        description="Site-wide settings. Anything left empty falls back to the server's .env configuration."
      />
      {isLoading || !data ? (
        <Skeleton className="h-96" />
      ) : (
        <>
          <div role="tablist" aria-label="Settings sections" className="mb-6 flex flex-wrap gap-1 border-b">
            {tabs.map((t) => (
              <button
                key={t.id}
                role="tab"
                id={`tab-${t.id}`}
                aria-selected={tab === t.id}
                aria-controls={`panel-${t.id}`}
                onClick={() => setTab(t.id)}
                className={cn(
                  'focus-visible:ring-ring -mb-px border-b-2 px-3 py-2 text-sm font-medium focus-visible:ring-2 focus-visible:outline-none',
                  tab === t.id
                    ? 'border-primary text-foreground'
                    : 'text-muted-foreground hover:text-foreground border-transparent',
                )}
              >
                {t.label}
              </button>
            ))}
          </div>
          <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
            {tab === 'branding' && <BrandingForm data={data} />}
            {tab === 'seo' && <SeoForm data={data} />}
            {tab === 'tax' && <TaxForm data={data} />}
            {tab === 'payments' && data.can_manage_secrets && <PaymentsForm data={data} />}
            {tab === 'tracking' && <TrackingForm data={data} />}
          </div>
        </>
      )}
    </>
  )
}

/** Shared save flow: PUT the group, refresh settings + public site data, map 422s to fields. */
function useSaveGroup(group: SettingsGroup) {
  const qc = useQueryClient()
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function save(body: Record<string, unknown>, onDone?: () => void) {
    setSaving(true)
    setErrors({})
    try {
      const res = await cmsApi.saveSettings(group, body)
      toast.success(res.meta.changed.length ? 'Settings saved' : 'No changes to save')
      await qc.invalidateQueries({ queryKey: KEY })
      qc.invalidateQueries({ queryKey: ['site'] })
      onDone?.()
    } catch (e) {
      setErrors(errorsOf(e))
    } finally {
      setSaving(false)
    }
  }
  return { save, errors, saving }
}

function FormCard({
  title,
  description,
  children,
  saving,
  error,
  onSave,
}: {
  title: string
  description?: string
  children: ReactNode
  saving: boolean
  error?: string
  onSave: () => void
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-4"
          noValidate
          onSubmit={(e) => {
            e.preventDefault()
            onSave()
          }}
        >
          {children}
          {error && (
            <p role="alert" className="text-destructive text-sm">
              {error}
            </p>
          )}
          <div className="flex justify-end">
            <Button type="submit" loading={saving}>
              Save
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

function TextField({
  id,
  label,
  value,
  onChange,
  error,
  hint,
  type = 'text',
  multiline,
  className,
}: {
  id: string
  label: string
  value: string
  onChange: (v: string) => void
  error?: string
  hint?: string
  type?: string
  multiline?: boolean
  className?: string
}) {
  return (
    <Field id={id} label={label} error={error} hint={hint} className={className}>
      {multiline ? (
        <textarea
          {...aria(id, error)}
          rows={3}
          className={fieldClass}
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <Input {...aria(id, error)} type={type} value={value} onChange={(e) => onChange(e.target.value)} />
      )}
    </Field>
  )
}

const s = (v: string | null | undefined) => v ?? ''

function BrandingForm({ data }: { data: SettingsResponse }) {
  const v = data.values.branding
  const [f, setF] = useState({
    name: s(v.name),
    tagline: s(v.tagline),
    logo_url: s(v.logo_url),
    primary_color: s(v.primary_color),
    support_email: s(v.support_email),
  })
  const { save, errors, saving } = useSaveGroup('branding')
  const nul = (x: string) => (x.trim() === '' ? null : x.trim())

  return (
    <FormCard
      title="Branding"
      description="Your name and look across the site, emails and invoices."
      saving={saving}
      error={errors._}
      onSave={() =>
        save({
          name: f.name,
          tagline: nul(f.tagline),
          logo_url: nul(f.logo_url),
          primary_color: nul(f.primary_color),
          support_email: nul(f.support_email),
        })
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="br-name"
          label="Site name"
          value={f.name}
          error={errors.name}
          onChange={(name) => setF({ ...f, name })}
        />
        <TextField
          id="br-tag"
          label="Tagline"
          value={f.tagline}
          error={errors.tagline}
          onChange={(tagline) => setF({ ...f, tagline })}
        />
        <TextField
          id="br-logo"
          label="Logo URL"
          type="url"
          hint="https:// image (SVG or PNG)"
          value={f.logo_url}
          error={errors.logo_url}
          onChange={(logo_url) => setF({ ...f, logo_url })}
        />
        <Field id="br-color" label="Primary colour" hint="Hex, e.g. #4F46E5" error={errors.primary_color}>
          <div className="flex gap-2">
            <Input
              {...aria('br-color', errors.primary_color)}
              value={f.primary_color}
              placeholder="#4F46E5"
              onChange={(e) => setF({ ...f, primary_color: e.target.value })}
            />
            <input
              type="color"
              aria-label="Pick primary colour"
              className="h-10 w-12 cursor-pointer rounded-md border"
              value={/^#[0-9a-fA-F]{6}$/.test(f.primary_color) ? f.primary_color : '#4f46e5'}
              onChange={(e) => setF({ ...f, primary_color: e.target.value.toUpperCase() })}
            />
          </div>
        </Field>
        <TextField
          id="br-email"
          label="Support email"
          type="email"
          hint="Shown on the contact page and in emails"
          value={f.support_email}
          error={errors.support_email}
          onChange={(support_email) => setF({ ...f, support_email })}
        />
      </div>
    </FormCard>
  )
}

function SeoForm({ data }: { data: SettingsResponse }) {
  const v = data.values.seo
  const [f, setF] = useState({
    default_title: s(v.default_title),
    default_description: s(v.default_description),
    og_image_url: s(v.og_image_url),
    twitter_handle: s(v.twitter_handle),
    allow_indexing: v.allow_indexing ?? true,
  })
  const { save, errors, saving } = useSaveGroup('seo')
  const nul = (x: string) => (x.trim() === '' ? null : x.trim())

  return (
    <FormCard
      title="Search & social"
      description="Defaults for pages that don't set their own title, description or share image."
      saving={saving}
      error={errors._}
      onSave={() =>
        save({
          default_title: nul(f.default_title),
          default_description: nul(f.default_description),
          og_image_url: nul(f.og_image_url),
          twitter_handle: nul(f.twitter_handle),
          allow_indexing: f.allow_indexing,
        })
      }
    >
      <TextField
        id="seo-title"
        label="Homepage title"
        hint={`${f.default_title.length}/70 characters`}
        value={f.default_title}
        error={errors.default_title}
        onChange={(default_title) => setF({ ...f, default_title })}
      />
      <TextField
        id="seo-desc"
        label="Default description"
        multiline
        hint={`${f.default_description.length}/170 characters`}
        value={f.default_description}
        error={errors.default_description}
        onChange={(default_description) => setF({ ...f, default_description })}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="seo-og"
          label="Share image URL"
          type="url"
          hint="1200×630 works best"
          value={f.og_image_url}
          error={errors.og_image_url}
          onChange={(og_image_url) => setF({ ...f, og_image_url })}
        />
        <TextField
          id="seo-tw"
          label="X / Twitter handle"
          hint="e.g. @yourbrand"
          value={f.twitter_handle}
          error={errors.twitter_handle}
          onChange={(twitter_handle) => setF({ ...f, twitter_handle })}
        />
      </div>
      <div className="flex items-start gap-3 text-sm">
        <Toggle
          checked={f.allow_indexing}
          label="Allow search engines to index the site"
          onChange={(allow_indexing) => setF({ ...f, allow_indexing })}
        />
        <span>
          Allow search engines to index the site
          <span className="text-muted-foreground block text-xs">
            Turn off for staging sites — every page gets “noindex”.
          </span>
        </span>
      </div>
    </FormCard>
  )
}

function TaxForm({ data }: { data: SettingsResponse }) {
  const v = data.values.tax
  const [rates, setRates] = useState<(Omit<TaxRate, 'rate'> & { rate: string })[]>(
    v.rates.map((r) => ({ ...r, rate: String(r.rate) })),
  )
  const [seller, setSeller] = useState({
    seller_name: s(v.seller_name),
    seller_address: s(v.seller_address),
    seller_tax_id: s(v.seller_tax_id),
  })
  const { save, errors, saving } = useSaveGroup('tax')
  const setRate = (i: number, patch: Partial<(typeof rates)[number]>) =>
    setRates(rates.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <FormCard
      title="Tax & invoices"
      description="Prices include tax. New payments from a listed country show this tax line on the invoice; other countries get none."
      saving={saving}
      error={errors._ ?? errors.rates}
      onSave={() =>
        save({
          rates: rates.map((r) => ({
            country: r.country.trim().toUpperCase(),
            name: r.name.trim(),
            rate: Number(r.rate),
          })),
          seller_name: seller.seller_name || null,
          seller_address: seller.seller_address || null,
          seller_tax_id: seller.seller_tax_id || null,
        })
      }
    >
      <fieldset className="grid gap-2">
        <legend className="mb-2 text-sm font-medium">Tax rates by country</legend>
        {rates.length === 0 && <p className="text-muted-foreground text-sm">No tax is added anywhere.</p>}
        {rates.map((r, i) => (
          <div key={i} className="grid grid-cols-[5rem_1fr_6rem_auto] items-start gap-2">
            <Field id={`tx-c-${i}`} label="Country" error={errors[`rates.${i}.country`]}>
              <Input
                {...aria(`tx-c-${i}`, errors[`rates.${i}.country`])}
                maxLength={2}
                placeholder="IN"
                value={r.country}
                onChange={(e) => setRate(i, { country: e.target.value.toUpperCase() })}
              />
            </Field>
            <Field id={`tx-n-${i}`} label="Tax name" error={errors[`rates.${i}.name`]}>
              <Input
                {...aria(`tx-n-${i}`, errors[`rates.${i}.name`])}
                maxLength={16}
                placeholder="GST"
                value={r.name}
                onChange={(e) => setRate(i, { name: e.target.value })}
              />
            </Field>
            <Field id={`tx-r-${i}`} label="Rate %" error={errors[`rates.${i}.rate`]}>
              <Input
                {...aria(`tx-r-${i}`, errors[`rates.${i}.rate`])}
                type="number"
                min={0}
                max={50}
                step="0.01"
                value={r.rate}
                onChange={(e) => setRate(i, { rate: e.target.value })}
              />
            </Field>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="mt-6"
              aria-label={`Remove ${r.country || 'rate'} ${i + 1}`}
              onClick={() => setRates(rates.filter((_, j) => j !== i))}
            >
              <Trash2 aria-hidden />
            </Button>
          </div>
        ))}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="justify-self-start"
          onClick={() => setRates([...rates, { country: '', name: '', rate: '' }])}
        >
          <Plus aria-hidden /> Add country
        </Button>
      </fieldset>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="tx-sn"
          label="Seller name (on invoices)"
          value={seller.seller_name}
          error={errors.seller_name}
          onChange={(seller_name) => setSeller({ ...seller, seller_name })}
        />
        <TextField
          id="tx-tid"
          label="Tax ID (GSTIN / VAT)"
          value={seller.seller_tax_id}
          error={errors.seller_tax_id}
          onChange={(seller_tax_id) => setSeller({ ...seller, seller_tax_id })}
        />
        <TextField
          id="tx-sa"
          label="Seller address"
          multiline
          className="sm:col-span-2"
          value={seller.seller_address}
          error={errors.seller_address}
          onChange={(seller_address) => setSeller({ ...seller, seller_address })}
        />
      </div>
    </FormCard>
  )
}

/**
 * Write-only secret: shows whether it's set (and where from), never the value.
 * "Replace" reveals an input; "Clear" (admin-set values only) falls back to .env.
 */
function SecretField({
  id,
  label,
  status,
  value,
  onChange,
  cleared,
  onClear,
  editable,
  error,
}: {
  id: string
  label: string
  status: SecretStatus
  value: string
  onChange: (v: string) => void
  cleared: boolean
  onClear: (v: boolean) => void
  editable: boolean
  error?: string
}) {
  const [replacing, setReplacing] = useState(false)
  const summary = cleared
    ? 'Will be cleared on save (falls back to .env)'
    : status.set
      ? `Set · ••••${status.last4 ?? ''} (from ${status.source === 'admin' ? 'admin' : '.env'})`
      : 'Not set'

  return (
    <Field id={id} label={label} error={error}>
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant={status.set && !cleared ? 'secondary' : 'outline'} className="gap-1">
          <Lock className="size-3" aria-hidden /> <span id={`${id}-status`}>{summary}</span>
        </Badge>
        {editable && !replacing && (
          <Button type="button" size="sm" variant="outline" onClick={() => setReplacing(true)}>
            {status.set ? 'Replace' : 'Set'}
          </Button>
        )}
        {editable && status.source === 'admin' && (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            aria-pressed={cleared}
            onClick={() => onClear(!cleared)}
          >
            {cleared ? 'Undo clear' : 'Clear'}
          </Button>
        )}
        {!editable && (
          <span className="text-muted-foreground text-xs">Only a super admin can change this.</span>
        )}
      </div>
      {editable && replacing && (
        <div className="flex gap-2">
          <Input
            {...aria(id, error)}
            type="password"
            autoComplete="off"
            placeholder="Paste the new value · leave blank to keep"
            value={value}
            onChange={(e) => onChange(e.target.value)}
          />
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              onChange('')
              setReplacing(false)
            }}
          >
            Cancel
          </Button>
        </div>
      )}
    </Field>
  )
}

/** Secret inputs + clear flags for one form. */
function useSecrets<K extends string>(keys: readonly K[]) {
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(keys.map((k) => [k, ''])),
  )
  const [clear, setClear] = useState<string[]>([])
  return {
    values,
    clear,
    set: (k: K, v: string) => setValues((s) => ({ ...s, [k]: v })),
    toggleClear: (k: K, on: boolean) => setClear((c) => (on ? [...c, k] : c.filter((x) => x !== k))),
    reset: () => {
      setValues(Object.fromEntries(keys.map((k) => [k, ''])))
      setClear([])
    },
  }
}

const PAYMENT_SECRETS = [
  'stripe_secret',
  'stripe_webhook_secret',
  'razorpay_key_secret',
  'razorpay_webhook_secret',
] as const

function PaymentsForm({ data }: { data: SettingsResponse }) {
  const v = data.values.payments!
  const [plain, setPlain] = useState({ stripe_key: s(v.stripe_key), razorpay_key_id: s(v.razorpay_key_id) })
  const secrets = useSecrets(PAYMENT_SECRETS)
  const { save, errors, saving } = useSaveGroup('payments')
  const secret = (k: (typeof PAYMENT_SECRETS)[number], label: string) => (
    <SecretField
      id={`pay-${k}`}
      label={label}
      status={v[k]}
      value={secrets.values[k] ?? ''}
      onChange={(x) => secrets.set(k, x)}
      cleared={secrets.clear.includes(k)}
      onClear={(on) => secrets.toggleClear(k, on)}
      editable
      error={errors[k]}
    />
  )

  return (
    <div className="grid gap-6">
      <FormCard
        title="Payment gateways"
        description="Keys are encrypted at rest and never shown again. Leave a secret blank to keep it."
        saving={saving}
        error={errors._ ?? errors.clear}
        onSave={() =>
          save(
            settingsPayload(
              { stripe_key: plain.stripe_key || null, razorpay_key_id: plain.razorpay_key_id || null },
              secrets.values,
              secrets.clear,
            ),
            secrets.reset,
          )
        }
      >
        <h3 className="text-sm font-semibold">Stripe</h3>
        <TextField
          id="pay-sk"
          label="Publishable key"
          hint="pk_live_… or pk_test_…"
          value={plain.stripe_key}
          error={errors.stripe_key}
          onChange={(stripe_key) => setPlain({ ...plain, stripe_key })}
        />
        {secret('stripe_secret', 'Secret key')}
        {secret('stripe_webhook_secret', 'Webhook signing secret')}
        <h3 className="mt-2 text-sm font-semibold">Razorpay</h3>
        <TextField
          id="pay-rk"
          label="Key ID"
          hint="rzp_live_… or rzp_test_…"
          value={plain.razorpay_key_id}
          error={errors.razorpay_key_id}
          onChange={(razorpay_key_id) => setPlain({ ...plain, razorpay_key_id })}
        />
        {secret('razorpay_key_secret', 'Key secret')}
        {secret('razorpay_webhook_secret', 'Webhook secret')}
      </FormCard>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Webhook URLs</CardTitle>
          <CardDescription>Add these in the Stripe and Razorpay dashboards.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          <CopyRow label="Stripe" value={data.webhooks.stripe} />
          <CopyRow label="Razorpay" value={data.webhooks.razorpay} />
        </CardContent>
      </Card>
    </div>
  )
}

function CopyRow({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="w-20 text-sm font-medium">{label}</span>
      <code className="bg-muted min-w-0 flex-1 truncate rounded px-2 py-1 text-xs">{value}</code>
      <Button
        type="button"
        size="sm"
        variant="outline"
        aria-label={`Copy ${label} webhook URL`}
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(value)
            setCopied(true)
            setTimeout(() => setCopied(false), 1500)
          } catch {
            toast.error('Copy failed — select the URL and copy it manually.')
          }
        }}
      >
        {copied ? <Check aria-hidden /> : <Copy aria-hidden />} {copied ? 'Copied' : 'Copy'}
      </Button>
    </div>
  )
}

const TRACKING_SECRETS = ['ga4_api_secret', 'meta_capi_token'] as const

function TrackingForm({ data }: { data: SettingsResponse }) {
  const v = data.values.tracking
  const canSecrets = data.can_manage_secrets
  const [plain, setPlain] = useState({
    ga4_measurement_id: s(v.ga4_measurement_id),
    meta_pixel_id: s(v.meta_pixel_id),
    meta_test_event_code: s(v.meta_test_event_code),
  })
  const secrets = useSecrets(TRACKING_SECRETS)
  const { save, errors, saving } = useSaveGroup('tracking')
  const nul = (x: string) => (x.trim() === '' ? null : x.trim())

  return (
    <FormCard
      title="Analytics & ads tracking"
      description="Optional and free. The site loads Google Analytics 4 and the Meta Pixel only when their IDs are set. Purchases are sent from the server (needs the API secret / access token)."
      saving={saving}
      error={errors._ ?? errors.clear}
      onSave={() =>
        save(
          settingsPayload(
            {
              ga4_measurement_id: nul(plain.ga4_measurement_id),
              meta_pixel_id: nul(plain.meta_pixel_id),
              meta_test_event_code: nul(plain.meta_test_event_code),
            },
            canSecrets ? secrets.values : {},
            canSecrets ? secrets.clear : [],
          ),
          secrets.reset,
        )
      }
    >
      <h3 className="text-sm font-semibold">Google Analytics 4</h3>
      <TextField
        id="tr-ga"
        label="Measurement ID"
        hint="G-XXXXXXX"
        value={plain.ga4_measurement_id}
        error={errors.ga4_measurement_id}
        onChange={(ga4_measurement_id) => setPlain({ ...plain, ga4_measurement_id })}
      />
      <SecretField
        id="tr-gas"
        label="Measurement Protocol API secret"
        status={v.ga4_api_secret}
        value={secrets.values.ga4_api_secret ?? ''}
        onChange={(x) => secrets.set('ga4_api_secret', x)}
        cleared={secrets.clear.includes('ga4_api_secret')}
        onClear={(on) => secrets.toggleClear('ga4_api_secret', on)}
        editable={canSecrets}
        error={errors.ga4_api_secret}
      />
      <h3 className="mt-2 text-sm font-semibold">Meta (Facebook) Pixel</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="tr-px"
          label="Pixel ID"
          hint="Digits only"
          value={plain.meta_pixel_id}
          error={errors.meta_pixel_id}
          onChange={(meta_pixel_id) => setPlain({ ...plain, meta_pixel_id })}
        />
        <TextField
          id="tr-tec"
          label="Test event code"
          hint="Only while testing in Events Manager"
          value={plain.meta_test_event_code}
          error={errors.meta_test_event_code}
          onChange={(meta_test_event_code) => setPlain({ ...plain, meta_test_event_code })}
        />
      </div>
      <SecretField
        id="tr-capi"
        label="Conversions API access token"
        status={v.meta_capi_token}
        value={secrets.values.meta_capi_token ?? ''}
        onChange={(x) => secrets.set('meta_capi_token', x)}
        cleared={secrets.clear.includes('meta_capi_token')}
        onClear={(on) => secrets.toggleClear('meta_capi_token', on)}
        editable={canSecrets}
        error={errors.meta_capi_token}
      />
    </FormCard>
  )
}
