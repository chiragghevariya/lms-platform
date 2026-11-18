import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import NotFound from '@/components/NotFound'
import { Seo } from '@/components/Seo'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { cmsApi } from '../api'
import { CmsHtml } from '../components/CmsHtml'

export default function BlogPostPage() {
  const { slug = '' } = useParams()
  const {
    data: post,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ['blog', 'post', slug],
    queryFn: () => cmsApi.post(slug),
    retry: false,
  })

  if (isError) return <NotFound />

  return (
    <article className="mx-auto max-w-3xl px-4 py-12">
      <Link
        to="/blog"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="size-4" aria-hidden /> All posts
      </Link>
      {isLoading || !post ? (
        <div className="mt-6 grid gap-4" aria-busy="true">
          <Skeleton className="h-10 w-3/4" />
          <Skeleton className="aspect-[16/9]" />
          <Skeleton className="h-40" />
        </div>
      ) : (
        <>
          <Seo
            title={post.seo_title || post.title}
            description={post.seo_description || post.excerpt || undefined}
            path={`/blog/${post.slug}`}
            image={post.cover_url ?? undefined}
          />
          <h1 className="mt-6 text-3xl font-extrabold tracking-tight text-balance sm:text-5xl">
            {post.title}
          </h1>
          <p className="text-muted-foreground mt-4 text-sm">
            {post.author?.name && <>By {post.author.name} · </>}
            <time dateTime={post.published_at ?? undefined}>{formatDate(post.published_at)}</time> ·{' '}
            {post.reading_minutes} min read
          </p>
          {post.cover_url && (
            <img src={post.cover_url} alt="" className="mt-8 aspect-[16/9] w-full rounded-xl object-cover" />
          )}
          {post.excerpt && <p className="text-muted-foreground mt-8 text-lg">{post.excerpt}</p>}
          <CmsHtml html={post.body ?? ''} className="mt-6 text-base" />
        </>
      )}
    </article>
  )
}
