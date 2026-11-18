import type { ReactNode } from 'react'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import { useCmsPage } from '@/features/cms/api'
import { CmsHtml } from '@/features/cms/components/CmsHtml'
import { formatDate } from '@/lib/format'

/**
 * Legal page: shows the admin's published CMS version (Admin → Website → Legal pages) when
 * there is one, otherwise the built-in template text passed as children.
 */
export function LegalPage({
  title,
  path,
  updated,
  slug,
  children,
}: {
  title: string
  path: string
  updated: string
  slug?: string
  children: ReactNode
}) {
  const cms = useCmsPage(slug ?? '')
  const live = slug ? cms.data : undefined
  const loading = !!slug && cms.isLoading

  return (
    <article className="mx-auto max-w-3xl px-4 py-16">
      <Seo title={live?.title ?? title} path={path} />
      <h1 className="text-4xl font-extrabold tracking-tight">{live?.title ?? title}</h1>
      {loading ? (
        <div className="mt-8 grid gap-3" aria-busy="true">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-24" />
          <Skeleton className="h-24" />
        </div>
      ) : live ? (
        <>
          <p className="text-muted-foreground mt-2 text-sm">Last updated {formatDate(live.updated_at)}</p>
          <CmsHtml html={live.body} className="text-muted-foreground [&_h2]:text-foreground mt-8" />
        </>
      ) : (
        <>
          <p className="text-muted-foreground mt-2 text-sm">Last updated {updated}</p>
          <Alert variant="info" className="mt-6">
            Template text — have this reviewed by your legal counsel before launch.
          </Alert>
          <div className="[&_p]:text-muted-foreground [&_ul]:text-muted-foreground mt-8 grid gap-6 [&_h2]:text-xl [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-6">
            {children}
          </div>
        </>
      )}
    </article>
  )
}
