import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function Section({
  id,
  eyebrow,
  title,
  intro,
  children,
  className,
}: {
  id?: string
  eyebrow?: string
  title: string
  intro?: string
  children: ReactNode
  className?: string
}) {
  const headingId = id ? `${id}-heading` : undefined
  return (
    <section
      id={id}
      aria-labelledby={headingId}
      className={cn('mx-auto max-w-6xl px-4 py-16 sm:py-20', className)}
    >
      <div className="mx-auto mb-10 max-w-2xl text-center">
        {eyebrow && (
          <p className="text-primary mb-2 text-sm font-semibold tracking-wider uppercase">{eyebrow}</p>
        )}
        <h2 id={headingId} className="text-3xl font-bold tracking-tight text-balance sm:text-4xl">
          {title}
        </h2>
        {intro && <p className="text-muted-foreground mt-3 text-lg">{intro}</p>}
      </div>
      {children}
    </section>
  )
}
