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
    'img',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
  ],
  ALLOWED_ATTR: ['href', 'title', 'target', 'rel', 'src', 'alt', 'width', 'height'],
}

/** Admin-authored CMS HTML (purified server-side too; DOMPurify is defence in depth). */
export function CmsHtml({ html, className }: { html: string; className?: string }) {
  const clean = useMemo(() => DOMPurify.sanitize(html, ALLOWED), [html])
  return (
    <div
      className={cn(
        '[&_a]:text-primary max-w-none leading-relaxed [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:pl-4 [&_blockquote]:italic',
        '[&_code]:bg-muted [&_code]:rounded [&_code]:px-1 [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold',
        '[&_img]:my-4 [&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-3',
        '[&_pre]:bg-muted [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:p-3 [&_table]:my-4 [&_table]:w-full [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2 [&_th]:text-left [&_ul]:list-disc [&_ul]:pl-6',
        className,
      )}
      dangerouslySetInnerHTML={{ __html: clean }}
    />
  )
}
