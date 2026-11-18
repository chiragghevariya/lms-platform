import { useQuery } from '@tanstack/react-query'
import { Award, Star } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import NotFound from '@/components/NotFound'
import { Seo } from '@/components/Seo'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { learningApi } from '../api'
import { BadgeIcon } from '../components/BadgeIcon'

export default function PublicProfilePage() {
  const { handle = '' } = useParams()
  const { data, isLoading, isError } = useQuery({
    queryKey: ['learner', handle],
    queryFn: () => learningApi.publicProfile(handle),
    retry: false,
  })
  if (isError) return <NotFound />

  return (
    <div className="container mx-auto grid max-w-4xl gap-6 px-4 py-12">
      <Seo title={data?.name ?? 'Learner'} description={data?.headline ?? undefined} path={`/u/${handle}`} />
      {isLoading || !data ? (
        <Skeleton className="h-48" />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-5">
            <Avatar className="size-20">
              {data.avatar_url && <AvatarImage src={data.avatar_url} alt="" />}
              <AvatarFallback className="text-2xl">{data.name.charAt(0)}</AvatarFallback>
            </Avatar>
            <div>
              <h1 className="text-3xl font-bold">{data.name}</h1>
              {data.headline && <p className="text-muted-foreground">{data.headline}</p>}
              <p className="mt-1 inline-flex items-center gap-1 text-sm">
                <Star className="size-4" aria-hidden /> {data.points} points
              </p>
            </div>
          </div>
          {data.skills.length > 0 && (
            <ul className="flex flex-wrap gap-2" aria-label="Skills">
              {data.skills.map((s) => (
                <li key={s}>
                  <Badge variant="secondary">{s}</Badge>
                </li>
              ))}
            </ul>
          )}
          {data.learning_goals && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Learning goals</CardTitle>
              </CardHeader>
              <CardContent className="whitespace-pre-wrap">{data.learning_goals}</CardContent>
            </Card>
          )}
          <section aria-labelledby="certs-h" className="grid gap-3">
            <h2 id="certs-h" className="text-lg font-semibold">
              Certificates
            </h2>
            {data.certificates.length === 0 && <p className="text-muted-foreground text-sm">None yet.</p>}
            <ul className="grid gap-3 sm:grid-cols-2">
              {data.certificates.map((c) => (
                <li key={c.verification_code}>
                  <Card>
                    <CardContent className="flex items-start gap-3 p-4">
                      <Award className="text-accent mt-0.5 size-5" aria-hidden />
                      <div>
                        <p className="font-medium">{c.course_title}</p>
                        <p className="text-muted-foreground text-xs">
                          {formatDate(c.issued_at)} ·{' '}
                          <Link to={`/verify/${c.verification_code}`} className="hover:underline">
                            Verify
                          </Link>
                        </p>
                      </div>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          </section>
          {data.badges.length > 0 && (
            <section aria-labelledby="badges-h" className="grid gap-3">
              <h2 id="badges-h" className="text-lg font-semibold">
                Badges
              </h2>
              <ul className="flex flex-wrap gap-3">
                {data.badges.map((b) => (
                  <li
                    key={b.key}
                    className="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm"
                    title={b.description}
                  >
                    <BadgeIcon badgeKey={b.key} className="text-accent size-4" /> {b.name}
                  </li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </div>
  )
}
