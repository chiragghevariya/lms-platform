import { useId, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'

export const textareaClass =
  'border-input bg-background focus-visible:ring-ring min-h-24 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none'

/** Confirmation that requires (or optionally takes) a written reason — logged in the audit trail. */
export function ReasonDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  destructive,
  required = true,
  pending,
  error,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: string
  confirmLabel: string
  destructive?: boolean
  required?: boolean
  pending?: boolean
  error?: string | null
  onConfirm: (reason: string) => void
}) {
  const id = useId()
  const [reason, setReason] = useState('')
  const invalid = required && reason.trim().length < 3

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        if (!o) setReason('')
        onOpenChange(o)
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            if (!invalid) onConfirm(reason.trim())
          }}
        >
          <div className="grid gap-2">
            <Label htmlFor={id}>Reason{required ? '' : ' (optional)'}</Label>
            <textarea
              id={id}
              className={textareaClass}
              value={reason}
              maxLength={500}
              required={required}
              onChange={(e) => setReason(e.target.value)}
              aria-describedby={error ? `${id}-err` : undefined}
            />
            {error && (
              <p id={`${id}-err`} role="alert" className="text-destructive text-sm">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant={destructive ? 'destructive' : 'default'}
              disabled={invalid || pending}
            >
              {pending ? 'Working…' : confirmLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
