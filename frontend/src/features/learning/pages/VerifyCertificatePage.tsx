import { useQuery } from '@tanstack/react-query'
import { BadgeCheck, ShieldAlert } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { learningApi } from '../api'

/** Public: anyone (employers, LinkedIn viewers) can confirm a certificate is genuine. */
export default function VerifyCertificatePage() {
  const { code = '' } = useParams()
  const { data, isLoading, isError } = useQuery({
    queryKey: ['verify', code],
    queryFn: () => learningApi.verify(code),
    retry: false,
  })

  return (
    <div className="container mx-auto grid max-w-2xl gap-6 px-4 py-16">
      <Seo title="Verify certificate" noindex />
      <h1 className="text-center text-3xl font-bold">Certificate verification</h1>
      {isLoading && <Skeleton className="h-56" />}
      {(isError || (data && !data.valid)) && (
        <Card className="border-destructive/40">
          <CardContent className="grid justify-items-center gap-3 p-8 text-center" role="status">
            <ShieldAlert className="text-destructive size-12" aria-hidden />
            <p className="text-lg font-semibold">
              {data && !data.valid && data.course_title
                ? 'This certificate has been revoked'
                : 'No certificate found'}
            </p>
            <p className="text-muted-foreground text-sm">
              Check the credential ID <span className="font-mono">{code.toUpperCase()}</span> and try again.
            </p>
          </CardContent>
        </Card>
      )}
      {data?.valid && (
        <Card className="border-accent/50">
          <CardContent className="grid justify-items-center gap-4 p-8 text-center" role="status">
            <BadgeCheck className="text-accent size-14" aria-hidden />
            <p className="text-accent text-sm font-semibold tracking-wide uppercase">Verified certificate</p>
            <p className="text-muted-foreground">This certifies that</p>
            <p className="text-2xl font-bold">{data.holder_name}</p>
            <p className="text-muted-foreground">successfully completed</p>
            <p className="text-xl font-semibold">
              {data.course_slug ? (
                <Link to={`/courses/${data.course_slug}`} className="hover:underline">
                  {data.course_title}
                </Link>
              ) : (
                data.course_title
              )}
            </p>
            <dl className="text-muted-foreground mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-left text-sm">
              <dt>Issued</dt>
              <dd className="text-foreground">{formatDate(data.issued_at)}</dd>
              <dt>Issuer</dt>
              <dd className="text-foreground">{data.issuer}</dd>
              {data.instructor_name && (
                <>
                  <dt>Instructor</dt>
                  <dd className="text-foreground">{data.instructor_name}</dd>
                </>
              )}
              <dt>Credential ID</dt>
              <dd className="text-foreground font-mono">{data.verification_code}</dd>
            </dl>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
