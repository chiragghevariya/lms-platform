import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useId, useState } from 'react'
import { toast } from 'sonner'
import { toolbarSelect } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError } from '@/lib/api-client'
import { formatDate, formatMinor } from '@/lib/format'
import type { Coupon, CouponInput } from '@/types/admin'
import { adminApi, useAdminPlans } from '../api'
import { toMinor } from '../components/helpers'

const describe = (c: Coupon) =>
  c.type === 'percent' ? `${c.value}% off` : `${formatMinor(c.value, c.currency ?? 'INR')} off`

const expired = (c: Coupon) => !!c.expires_at && new Date(c.expires_at) < new Date()

export default function AdminCouponsPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'coupons'], queryFn: adminApi.coupons })
  const plans = useAdminPlans()
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Coupon | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'coupons'] })

  const toggle = useMutation({
    mutationFn: (c: Coupon) => adminApi.updateCoupon(c.id, { is_active: !c.is_active }),
    onSuccess: (c) => {
      toast.success(c.is_active ? `${c.code} activated` : `${c.code} paused`)
      void refresh()
    },
  })
  const remove = useMutation({
    mutationFn: (c: Coupon) => adminApi.deleteCoupon(c.id),
    onSuccess: () => {
      toast.success('Coupon deleted')
      setDeleting(null)
      void refresh()
    },
  })

  const planName = (id: string) => plans.data?.find((p) => p.id === id)?.name ?? 'plan'

  return (
    <>
      <Seo title="Coupons" noindex />
      <PageHeader
        title="Coupons"
        description="Discount codes for memberships. One use per member; Stripe mirrors them automatically."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden /> New coupon
          </Button>
        }
      />
      {isLoading ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[720px] text-sm" aria-label="Coupons">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th className="p-3 font-medium">Code</th>
                <th className="p-3 font-medium">Discount</th>
                <th className="p-3 font-medium">Plans</th>
                <th className="p-3 font-medium">Used</th>
                <th className="p-3 font-medium">Expires</th>
                <th className="p-3 font-medium">State</th>
                <th className="p-3">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data?.data.map((c) => (
                <tr key={c.id}>
                  <td className="p-3 font-mono font-medium">{c.code}</td>
                  <td className="p-3">{describe(c)}</td>
                  <td className="p-3">
                    {c.plan_ids?.length ? c.plan_ids.map(planName).join(', ') : 'All plans'}
                  </td>
                  <td className="p-3 tabular-nums">
                    {c.times_used}
                    {c.max_uses ? ` / ${c.max_uses}` : ''}
                  </td>
                  <td className="p-3">{c.expires_at ? formatDate(c.expires_at) : 'Never'}</td>
                  <td className="p-3">
                    <Badge variant={c.is_active && !expired(c) ? 'default' : 'outline'}>
                      {expired(c) ? 'Expired' : c.is_active ? 'Active' : 'Paused'}
                    </Badge>
                  </td>
                  <td className="p-3">
                    <div className="flex justify-end gap-1">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(c)}>
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={toggle.isPending}
                        onClick={() => toggle.mutate(c)}
                      >
                        {c.is_active ? 'Pause' : 'Activate'}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-destructive"
                        onClick={() => setDeleting(c)}
                      >
                        Delete
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
              {data?.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="text-muted-foreground p-8 text-center">
                    No coupons yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <CouponDialog
          coupon={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            void refresh()
          }}
        />
      )}

      <Dialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete {deleting?.code}?</DialogTitle>
            <DialogDescription>
              The code stops working immediately. Past redemptions and invoices are kept.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting)}
            >
              Delete coupon
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function CouponDialog({
  coupon,
  onClose,
  onSaved,
}: {
  coupon: Coupon | null
  onClose: () => void
  onSaved: () => void
}) {
  const id = useId()
  const plans = useAdminPlans()
  const creating = !coupon
  const [f, setF] = useState({
    code: coupon?.code ?? '',
    type: coupon?.type ?? ('percent' as 'percent' | 'fixed'),
    value: coupon ? (coupon.type === 'fixed' ? String(coupon.value / 100) : String(coupon.value)) : '',
    currency: coupon?.currency ?? 'INR',
    max_uses: coupon?.max_uses ? String(coupon.max_uses) : '',
    expires_at: coupon?.expires_at ? coupon.expires_at.slice(0, 10) : '',
    plan_ids: coupon?.plan_ids ?? ([] as string[]),
    is_active: coupon?.is_active ?? true,
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => {
      const common: CouponInput = {
        max_uses: f.max_uses ? Number(f.max_uses) : null,
        expires_at: f.expires_at ? `${f.expires_at}T23:59:59` : null,
        plan_ids: f.plan_ids,
        is_active: f.is_active,
      }
      if (!creating) {
        // Re-sending an unchanged (possibly past) expiry would fail the "after now" rule.
        if (f.expires_at === (coupon.expires_at?.slice(0, 10) ?? '')) delete common.expires_at
        return adminApi.updateCoupon(coupon.id, common)
      }
      const value = f.type === 'fixed' ? toMinor(f.value) : Number(f.value)
      return adminApi.createCoupon({
        ...common,
        code: f.code.trim().toUpperCase(),
        type: f.type,
        value: value ?? 0,
        currency: f.type === 'fixed' ? f.currency : null,
      })
    },
    onSuccess: (c) => {
      toast.success(`${c.code} saved`)
      onSaved()
    },
    onError: (e) => {
      if (e instanceof ApiError && e.isValidation) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.split('.')[0], v[0] ?? ''])))
      } else toast.error(e instanceof Error ? e.message : 'Could not save the coupon.')
    },
  })

  const field = (name: string) => ({
    id: `${id}-${name}`,
    'aria-invalid': !!errors[name],
    'aria-describedby': errors[name] ? `${id}-${name}-err` : undefined,
  })
  const err = (name: string) =>
    errors[name] && (
      <p id={`${id}-${name}-err`} role="alert" className="text-destructive text-sm">
        {errors[name]}
      </p>
    )

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{creating ? 'New coupon' : `Edit ${coupon.code}`}</DialogTitle>
          {!creating && (
            <DialogDescription>
              Code and discount can't change once created; make a new coupon instead.
            </DialogDescription>
          )}
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            setErrors({})
            save.mutate()
          }}
        >
          {creating && (
            <>
              <div className="grid gap-1">
                <Label htmlFor={`${id}-code`}>Code</Label>
                <Input
                  {...field('code')}
                  required
                  maxLength={40}
                  className="font-mono uppercase"
                  value={f.code}
                  onChange={(e) => setF((s) => ({ ...s, code: e.target.value }))}
                />
                {err('code')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="grid gap-1">
                  <Label htmlFor={`${id}-type`}>Type</Label>
                  <select
                    {...field('type')}
                    className={toolbarSelect}
                    value={f.type}
                    onChange={(e) => setF((s) => ({ ...s, type: e.target.value as 'percent' | 'fixed' }))}
                  >
                    <option value="percent">Percent off</option>
                    <option value="fixed">Fixed amount off</option>
                  </select>
                </div>
                <div className="grid gap-1">
                  <Label htmlFor={`${id}-value`}>
                    {f.type === 'percent' ? 'Percent (1–100)' : `Amount (${f.currency})`}
                  </Label>
                  <Input
                    {...field('value')}
                    required
                    inputMode="decimal"
                    value={f.value}
                    onChange={(e) => setF((s) => ({ ...s, value: e.target.value }))}
                  />
                  {err('value')}
                </div>
              </div>
              {f.type === 'fixed' && (
                <div className="grid gap-1">
                  <Label htmlFor={`${id}-currency`}>Currency</Label>
                  <select
                    {...field('currency')}
                    className={toolbarSelect}
                    value={f.currency}
                    onChange={(e) => setF((s) => ({ ...s, currency: e.target.value }))}
                  >
                    <option value="INR">INR</option>
                    <option value="USD">USD</option>
                  </select>
                  {err('currency')}
                </div>
              )}
            </>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-1">
              <Label htmlFor={`${id}-max_uses`}>Max uses (empty = unlimited)</Label>
              <Input
                {...field('max_uses')}
                type="number"
                min={1}
                value={f.max_uses}
                onChange={(e) => setF((s) => ({ ...s, max_uses: e.target.value }))}
              />
              {err('max_uses')}
            </div>
            <div className="grid gap-1">
              <Label htmlFor={`${id}-expires_at`}>Expires on</Label>
              <Input
                {...field('expires_at')}
                type="date"
                value={f.expires_at}
                onChange={(e) => setF((s) => ({ ...s, expires_at: e.target.value }))}
              />
              {err('expires_at')}
            </div>
          </div>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Plans (none selected = all plans)</legend>
            <div className="flex flex-wrap gap-3">
              {plans.data?.map((p) => (
                <label key={p.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={f.plan_ids.includes(p.id)}
                    onChange={(e) =>
                      setF((s) => ({
                        ...s,
                        plan_ids: e.target.checked
                          ? [...s.plan_ids, p.id]
                          : s.plan_ids.filter((x) => x !== p.id),
                      }))
                    }
                  />
                  {p.name}
                </label>
              ))}
            </div>
            {err('plan_ids')}
          </fieldset>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={f.is_active}
              onChange={(e) => setF((s) => ({ ...s, is_active: e.target.checked }))}
            />
            Active
          </label>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save coupon'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
