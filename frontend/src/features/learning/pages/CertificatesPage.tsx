import { Award, Download, ExternalLink, Share2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import type { Certificate } from '@/types/learning'
import { downloadCertificate, useCertificates } from '../api'

export default function CertificatesPage() {
  const { data, isLoading } = useCertificates()

  return (
    <>
      <Seo title="Certificates" noindex />
      <PageHeader title="Certificates" description="Every completed course earns a verifiable certificate." />
      {isLoading && <Skeleton className="h-32" />}
      {data?.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            Finish every lesson in a course to earn your first certificate.{' '}
            <Link to="/app/courses" className="text-primary underline">
              Go to my learning
            </Link>
          </CardContent>
        </Card>
      )}
      <div className="grid gap-4 md:grid-cols-2">
        {data?.map((c) => (
          <CertificateCard key={c.verification_code} cert={c} />
        ))}
      </div>
    </>
  )
}

function CertificateCard({ cert }: { cert: Certificate }) {
  const [busy, setBusy] = useState(false)
  return (
    <Card>
      <CardContent className="grid gap-4 p-5">
        <div className="flex gap-4">
          <span className="bg-accent/15 text-accent grid size-12 shrink-0 place-items-center rounded-full">
            <Award className="size-6" aria-hidden />
          </span>
          <div className="min-w-0">
            <h2 className="font-semibold">{cert.course_title}</h2>
            <p className="text-muted-foreground text-sm">
              Issued {formatDate(cert.issued_at)}
              {cert.instructor_name && ` · ${cert.instructor_name}`}
            </p>
            <p className="text-muted-foreground mt-1 text-xs">
              Credential ID <span className="font-mono">{cert.verification_code}</span>
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            loading={busy}
            onClick={async () => {
              setBusy(true)
              try {
                await downloadCertificate(cert)
              } catch {
                toast.error('Could not download the PDF')
              } finally {
                setBusy(false)
              }
            }}
          >
            <Download aria-hidden /> PDF
          </Button>
          {cert.linkedin_url && (
            <Button size="sm" variant="outline" asChild>
              <a href={cert.linkedin_url} target="_blank" rel="noopener noreferrer">
                <Share2 aria-hidden /> Add to LinkedIn
              </a>
            </Button>
          )}
          <Button size="sm" variant="ghost" asChild>
            <Link to={`/verify/${cert.verification_code}`}>
              <ExternalLink aria-hidden /> Verification page
            </Link>
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() =>
              navigator.clipboard.writeText(cert.verify_url).then(
                () => toast.success('Verification link copied'),
                () => toast.error('Copy failed'),
              )
            }
          >
            Copy link
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
