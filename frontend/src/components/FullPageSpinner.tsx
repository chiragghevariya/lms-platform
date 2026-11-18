import { Loader2 } from 'lucide-react'

export function FullPageSpinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="grid min-h-dvh place-items-center" role="status" aria-live="polite">
      <Loader2 className="text-primary size-8 animate-spin" aria-hidden />
      <span className="sr-only">{label}…</span>
    </div>
  )
}
