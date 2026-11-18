import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import NotFound from '@/components/NotFound'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { VideoPlayer } from '@/components/VideoPlayer'
import { UpgradeCta } from '@/features/membership/components/LockBadge'
import { formatDate } from '@/lib/format'
import { liveApi } from '../api'

export default function ReplayPage() {
  const { id = '' } = useParams()
  const {
    data: s,
    isLoading,
    error,
  } = useQuery({ queryKey: ['live', 'session', id], queryFn: () => liveApi.show(id), retry: false })
  if (error) return <NotFound />

  return (
    <div className="grid gap-4">
      <Seo title={s ? `Replay: ${s.title}` : 'Replay'} noindex />
      <Button variant="ghost" size="sm" className="justify-self-start" asChild>
        <Link to="/app/replays">
          <ArrowLeft aria-hidden /> Replays
        </Link>
      </Button>
      {isLoading || !s ? (
        <Skeleton className="aspect-video" />
      ) : !s.viewer?.eligible && s.required_tier ? (
        <div className="grid justify-items-center gap-3 rounded-xl border p-10 text-center">
          <p className="font-semibold">This replay is for {s.required_tier} members and above.</p>
          <UpgradeCta tier={s.required_tier} size="default" />
        </div>
      ) : s.has_replay ? (
        <VideoPlayer title={`Replay: ${s.title}`} getSource={() => liveApi.replayPlayback(id)} />
      ) : (
        <p className="text-muted-foreground">The recording is still processing.</p>
      )}
      {s && (
        <div>
          <h1 className="text-2xl font-bold">{s.title}</h1>
          <p className="text-muted-foreground">
            {s.type_label} with {s.instructor.name} · recorded {formatDate(s.starts_at)}
          </p>
        </div>
      )}
    </div>
  )
}
