import DOMPurify from 'dompurify'
import { useMemo } from 'react'
import { cn } from '@/lib/utils'

const ALLOWED = {
  ALLOWED_TAGS: [
    'h2',
    'h3',
    'h4',
    'p',
    'br',
    'strong',
    'b',
    'em',
    'i',
    'u',
    's',
    'code',
    'pre',
    'blockquote',
    'hr',
    'ul',
    'ol',
    'li',
    'a',
  ],
  ALLOWED_ATTR: ['href', 'title', 'target', 'rel'],
}

/** Renders instructor HTML. Already sanitized server-side; DOMPurify is defence in depth. */
export function RichTextView({ html, className }: { html: string | null | undefined; className?: string }) {
  const clean = useMemo(() => (html ? DOMPurify.sanitize(html, ALLOWED) : ''), [html])
  if (!clean) return null

  return (
    <div
      className={cn(
        '[&_a]:text-primary [&_code]:bg-muted [&_pre]:bg-muted max-w-none text-sm leading-relaxed [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:pl-4 [&_blockquote]:italic [&_code]:rounded [&_code]:px-1 [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:text-lg [&_h3]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:p-3 [&_ul]:list-disc [&_ul]:pl-6',
        className,
      )}
      dangerouslySetInnerHTML={{ __html: clean }}
    />
  )
}
