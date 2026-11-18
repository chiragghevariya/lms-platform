import { ArrowLeft, CalendarPlus, ExternalLink, Video } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import NotFound from '@/components/NotFound'
import { Seo } from '@/components/Seo'
import { ThemeToggle } from '@/components/ThemeToggle'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { TierBadge } from '@/features/membership/components/TierBadge'
import type { JoinPayload } from '@/types/live'
import { formatSessionTime, liveApi, useLiveSession } from '../api'
import { SessionActions } from '../components/SessionActions'
import { formatCountdown, useNow } from '../components/useNow'

const ZoomEmbed = lazy(() => import('../components/ZoomEmbed'))

/** In-app live room: waiting room with countdown → embedded Zoom (or the provider's own app as fallback). */
export default function LiveRoomPage() {
  const { id = '' } = useParams()
  const { data: s, isLoading, error, refetch } = useLiveSession(id)
  const now = useNow()
  const [join, setJoin] = useState<JoinPayload | null>(null)
  const [joining, setJoining] = useState(false)

  if (error) return <NotFound />
  const opensAt = s?.viewer ? new Date(s.viewer.join_opens_at).getTime() : 0
  const canJoin =
    !!s?.viewer?.registered &&
    s.viewer.eligible &&
    s.status !== 'cancelled' &&
    s.status !== 'ended' &&
    now < new Date(s.ends_at).getTime() &&
    (now >= opensAt || s.status === 'live')

  const enter = async () => {
    setJoining(true)
    try {
      const payload = await liveApi.join(id)
      if (payload.mode === 'external') {
        window.open(payload.url, '_blank', 'noopener,noreferrer')
      }
      setJoin(payload)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not join')
      void refetch()
    } finally {
      setJoining(false)
    }
  }

  const fallbackUrl =
    join?.mode === 'zoom_sdk' ? join.fallback_url : join?.mode === 'external' ? join.url : null

  return (
    <div className="flex min-h-dvh flex-col">
      <Seo title={s?.title ?? 'Live class'} noindex />
      <header className="bg-card sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-3">
        <Button variant="ghost" size="sm" asChild>
          <Link to="/app/live">
            <ArrowLeft aria-hidden /> <span className="hidden sm:inline">Live classes</span>
          </Link>
        </Button>
        <p className="min-w-0 flex-1 truncate font-semibold">{s?.title}</p>
        {fallbackUrl && (
          <Button size="sm" variant="outline" asChild>
            <a href={fallbackUrl} target="_blank" rel="noopener noreferrer">
              <ExternalLink aria-hidden /> Open in {s?.provider === 'zoom' ? 'Zoom' : 'a new tab'}
            </a>
          </Button>
        )}
        <ThemeToggle />
      </header>

      <main id="main" className="mx-auto grid w-full max-w-6xl flex-1 content-start gap-6 p-4 lg:p-6">
        {isLoading || !s ? (
          <Skeleton className="h-64" />
        ) : join?.mode === 'zoom_sdk' ? (
          <Suspense fallback={<Skeleton className="h-[60dvh]" />}>
            <ZoomEmbed join={join} onLeave={() => setJoin(null)} />
          </Suspense>
        ) : (
          <>
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p className="text-muted-foreground text-sm">
                  {s.type_label} with {s.instructor.name}
                </p>
                <h1 className="text-2xl font-bold sm:text-3xl">{s.title}</h1>
                <p className="text-muted-foreground mt-1">
                  {formatSessionTime(s.starts_at, { year: 'numeric' })} · {s.duration} min
                </p>
              </div>
              <TierBadge tier={s.required_tier} />
            </div>

            {s.status === 'cancelled' && (
              <Alert variant="destructive">
                This session was cancelled.{s.cancel_reason ? ` ${s.cancel_reason}` : ''}
              </Alert>
            )}

            <Card>
              <CardContent className="grid justify-items-center gap-4 p-8 text-center">
                {join?.mode === 'external' ? (
                  <>
                    <p className="font-medium">The class opened in a new tab.</p>
                    <Button variant="outline" asChild>
                      <a href={join.url} target="_blank" rel="noopener noreferrer">
                        <ExternalLink aria-hidden /> Open it again
                      </a>
                    </Button>
                  </>
                ) : canJoin ? (
                  <>
                    <p className="text-lg font-semibold">
                      {s.status === 'live' ? 'The class is live' : 'The room is open'}
                    </p>
                    <Button size="lg" variant="accent" loading={joining} onClick={() => void enter()}>
                      <Video aria-hidden /> Join now
                    </Button>
                    <p className="text-muted-foreground text-xs">
                      {s.provider === 'zoom'
                        ? 'Joins in your browser — no Zoom app needed.'
                        : 'Opens the meeting link in a new tab.'}
                    </p>
                  </>
                ) : s.viewer?.registered && s.status === 'scheduled' ? (
                  <>
                    <p className="text-muted-foreground">You're registered. The room opens in</p>
                    <p className="text-4xl font-bold tabular-nums" role="timer" aria-live="off">
                      {formatCountdown(opensAt - now)}
                    </p>
                    <Button variant="ghost" size="sm" asChild>
                      <a href={s.calendar.google} target="_blank" rel="noopener noreferrer">
                        <CalendarPlus aria-hidden /> Add to Google Calendar
                      </a>
                    </Button>
                  </>
                ) : (
                  <SessionActions session={s} />
                )}
              </CardContent>
            </Card>

            {s.description && (
              <section aria-labelledby="about-h" className="grid gap-2">
                <h2 id="about-h" className="font-semibold">
                  About this session
                </h2>
                <p className="whitespace-pre-wrap">{s.description}</p>
              </section>
            )}
          </>
        )}
      </main>
    </div>
  )
}
