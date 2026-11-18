import { ChevronDown } from 'lucide-react'
import type { Faq as FaqItem } from '@/features/cms/types'
import { DEFAULT_FAQS } from '../data/defaultHome'

/** Native <details> accordion: keyboard and screen-reader friendly without JS. */
export function Faq({ items = DEFAULT_FAQS }: { items?: FaqItem[] }) {
  return (
    <div className="mx-auto grid max-w-3xl gap-3">
      {items.map(({ id, question, answer }) => (
        <details
          key={id}
          className="group bg-card rounded-lg border p-4 [&_summary::-webkit-details-marker]:hidden"
        >
          <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
            {question}
            <ChevronDown className="size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
          </summary>
          <p className="text-muted-foreground mt-3 whitespace-pre-line">{answer}</p>
        </details>
      ))}
    </div>
  )
}
