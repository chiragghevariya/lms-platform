import { useRef } from 'react'
import { cn } from '@/lib/utils'

/** Minimal accessible tabs (roving focus with arrow keys). Panels are rendered by the caller. */
export function Tabs<T extends string>({
  label,
  tabs,
  value,
  onChange,
  idPrefix,
}: {
  label: string
  tabs: { value: T; label: string; count?: number }[]
  value: T
  onChange: (v: T) => void
  idPrefix: string
}) {
  const refs = useRef<(HTMLButtonElement | null)[]>([])
  return (
    <div role="tablist" aria-label={label} className="mb-4 flex gap-1 overflow-x-auto border-b">
      {tabs.map((t, i) => (
        <button
          key={t.value}
          ref={(el) => {
            refs.current[i] = el
          }}
          role="tab"
          type="button"
          id={`${idPrefix}-tab-${t.value}`}
          aria-selected={value === t.value}
          aria-controls={`${idPrefix}-panel`}
          tabIndex={value === t.value ? 0 : -1}
          onClick={() => onChange(t.value)}
          onKeyDown={(e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
            const next = (i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length
            refs.current[next]?.focus()
            onChange(tabs[next]!.value)
          }}
          className={cn(
            '-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
            value === t.value
              ? 'border-primary text-foreground'
              : 'text-muted-foreground hover:text-foreground border-transparent',
          )}
        >
          {t.label}
          {t.count !== undefined && (
            <span className="text-muted-foreground ml-1.5 tabular-nums">{t.count}</span>
          )}
        </button>
      ))}
    </div>
  )
}
