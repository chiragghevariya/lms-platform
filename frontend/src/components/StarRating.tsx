import { Star } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Stars({ value, className }: { value: number; className?: string }) {
  return (
    <span
      className={cn('inline-flex items-center gap-0.5', className)}
      aria-label={`${value.toFixed(1)} out of 5 stars`}
      role="img"
    >
      {[1, 2, 3, 4, 5].map((i) => (
        <Star
          key={i}
          className={cn(
            'size-4',
            i <= Math.round(value) ? 'fill-tier-elite text-tier-elite' : 'text-muted-foreground/40',
          )}
          aria-hidden
        />
      ))}
    </span>
  )
}

export function StarInput({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  return (
    <div role="radiogroup" aria-label="Your rating" className="flex gap-1">
      {[1, 2, 3, 4, 5].map((i) => (
        <button
          key={i}
          type="button"
          role="radio"
          aria-checked={value === i}
          aria-label={`${i} star${i > 1 ? 's' : ''}`}
          onClick={() => onChange(i)}
          className="rounded p-0.5"
        >
          <Star
            className={cn(
              'size-6',
              i <= value ? 'fill-tier-elite text-tier-elite' : 'text-muted-foreground/50',
            )}
            aria-hidden
          />
        </button>
      ))}
    </div>
  )
}
