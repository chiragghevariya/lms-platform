import { useParams } from 'react-router-dom'
import NotFound from '@/components/NotFound'
import { Seo } from '@/components/Seo'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { useCmsPage } from '../api'
import { CmsHtml } from '../components/CmsHtml'

/** /p/:slug — admin-managed static pages (refund policy, cookies, …). */
export default function CmsPage() {
  const { slug = '' } = useParams()
  const { data, isLoading, isError } = useCmsPage(slug)

  if (isError) return <NotFound />

  return (
    <article className="mx-auto max-w-3xl px-4 py-16">
      {isLoading || !data ? (
        <div className="grid gap-4" aria-busy="true">
          <Skeleton className="h-10 w-2/3" />
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-40" />
        </div>
      ) : (
        <>
          <Seo title={data.title} path={`/p/${data.slug}`} />
          <h1 className="text-4xl font-extrabold tracking-tight">{data.title}</h1>
          <p className="text-muted-foreground mt-2 text-sm">Last updated {formatDate(data.updated_at)}</p>
          <CmsHtml html={data.body} className="text-muted-foreground [&_h2]:text-foreground mt-8" />
        </>
      )}
    </article>
  )
}
