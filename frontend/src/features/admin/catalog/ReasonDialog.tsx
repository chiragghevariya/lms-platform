import { useId, useState, type ReactNode } from 'react'
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

const textarea =
  'border-input bg-background focus-visible:ring-ring min-h-24 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none'

/**
 * Confirmation dialog with an optional/required free-text field (reason, notes, reference).
 * `onConfirm` may throw; the dialog stays open and shows the message.
 */
export function ReasonDialog({
  open,
  onOpenChange,
  title,
  description,
  label = 'Reason',
  required = true,
  multiline = true,
  confirmLabel = 'Confirm',
  destructive,
  onConfirm,
  extra,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: ReactNode
  label?: string
  required?: boolean
  multiline?: boolean
  confirmLabel?: string
  destructive?: boolean
  onConfirm: (value: string) => Promise<unknown>
  extra?: ReactNode
}) {
  const id = useId()
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function change(next: boolean) {
    if (!next) {
      setValue('')
      setError(null)
    }
    onOpenChange(next)
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (required && value.trim().length < 3) {
      setError(`${label} is required (at least 3 characters).`)
      return
    }
    setBusy(true)
    setError(null)
    try {
      await onConfirm(value.trim())
      change(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={change}>
      <DialogContent>
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            {description && <DialogDescription>{description}</DialogDescription>}
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor={id}>
              {label}
              {!required && <span className="text-muted-foreground font-normal"> (optional)</span>}
            </Label>
            {multiline ? (
              <textarea
                id={id}
                className={textarea}
                value={value}
                maxLength={2000}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-err` : undefined}
                onChange={(e) => setValue(e.target.value)}
              />
            ) : (
              <input
                id={id}
                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                value={value}
                maxLength={120}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-err` : undefined}
                onChange={(e) => setValue(e.target.value)}
              />
            )}
            {error && (
              <p id={`${id}-err`} role="alert" className="text-destructive text-sm">
                {error}
              </p>
            )}
          </div>
          {extra}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => change(false)}>
              Cancel
            </Button>
            <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={busy}>
              {busy ? 'Working…' : confirmLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
