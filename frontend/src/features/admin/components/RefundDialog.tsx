import { useMutation } from '@tanstack/react-query'
import { useId, useState } from 'react'
import { toast } from 'sonner'
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
import { ApiError } from '@/lib/api-client'
import { formatMinor } from '@/lib/format'
import type { AdminPayment } from '@/types/admin'
import { adminApi, useInvalidatePeople } from '../api'
import { toMinor } from './helpers'
import { textareaClass } from './ReasonDialog'

/** Full or partial refund through the payment's gateway. Amount typed in major units (₹ / $). */
export function RefundDialog({
  payment,
  open,
  onOpenChange,
}: {
  payment: AdminPayment
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const id = useId()
  const invalidate = useInvalidatePeople()
  const [mode, setMode] = useState<'full' | 'partial'>('full')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const refundable = payment.refundable_minor

  const close = (o: boolean) => {
    if (!o) {
      setMode('full')
      setAmount('')
      setReason('')
      setError(null)
    }
    onOpenChange(o)
  }

  const minor = mode === 'full' ? refundable : toMinor(amount)
  const amountError =
    mode === 'partial' && amount !== ''
      ? minor === null
        ? 'Enter an amount like 499 or 499.50.'
        : minor > refundable
          ? `You can refund at most ${formatMinor(refundable, payment.currency)}.`
          : null
      : null
  const canSubmit = !!minor && minor <= refundable && reason.trim().length >= 3 && !amountError

  const refund = useMutation({
    mutationFn: () =>
      adminApi.refund(payment.id, { amount: mode === 'full' ? undefined : minor!, reason: reason.trim() }),
    onSuccess: (p) => {
      toast.success(`Refunded ${formatMinor(p.refunded_minor - payment.refunded_minor, payment.currency)}`)
      void invalidate()
      close(false)
    },
    onError: (e) => {
      if (e instanceof ApiError) setError(e.errors.amount?.[0] ?? e.errors.reason?.[0] ?? e.message)
      else setError('The refund failed. Try again.')
    },
  })

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Refund payment</DialogTitle>
          <DialogDescription>
            {payment.invoice_number ?? payment.id} · paid {payment.amount.formatted} via {payment.gateway}.
            Refundable balance: <strong>{formatMinor(refundable, payment.currency)}</strong>. The money is
            returned through{' '}
            {payment.gateway === 'manual' ? 'your own records (manual payment)' : payment.gateway};
            commissions and earnings adjust automatically.
          </DialogDescription>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            setError(null)
            if (canSubmit) refund.mutate()
          }}
        >
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Amount</legend>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name={`${id}-mode`}
                checked={mode === 'full'}
                onChange={() => setMode('full')}
              />
              Full remaining balance ({formatMinor(refundable, payment.currency)})
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name={`${id}-mode`}
                checked={mode === 'partial'}
                onChange={() => setMode('partial')}
              />
              Partial amount
            </label>
            {mode === 'partial' && (
              <div className="grid gap-1 pl-6">
                <Label htmlFor={`${id}-amount`}>Amount in {payment.currency}</Label>
                <Input
                  id={`${id}-amount`}
                  inputMode="decimal"
                  autoComplete="off"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  aria-invalid={!!amountError}
                  aria-describedby={amountError ? `${id}-amount-err` : undefined}
                />
                {amountError && (
                  <p id={`${id}-amount-err`} role="alert" className="text-destructive text-sm">
                    {amountError}
                  </p>
                )}
              </div>
            )}
          </fieldset>
          <div className="grid gap-2">
            <Label htmlFor={`${id}-reason`}>Reason (kept in the audit log)</Label>
            <textarea
              id={`${id}-reason`}
              className={textareaClass}
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </div>
          {error && (
            <p role="alert" className="text-destructive text-sm">
              {error}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => close(false)}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={!canSubmit || refund.isPending}>
              {refund.isPending
                ? 'Refunding…'
                : `Refund ${minor && minor <= refundable ? formatMinor(minor, payment.currency) : ''}`.trim()}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
