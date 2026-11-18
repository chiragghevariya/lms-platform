import { useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, Download, Video } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { UpgradeCta } from '@/features/membership/components/LockBadge'
import type { LiveSession } from '@/types/live'
import { liveApi } from '../api'
import { formatCountdown, useNow } from './useNow'

/**
 * Register / leave / join / add-to-calendar for one session. "Join now" turns on by itself
 * at join_opens_at (10 min before) using the local clock; the server re-checks on join.
 */
export function SessionActions({ session: s, compact = false }: { session: LiveSession; compact?: boolean }) {
  const qc = useQueryClient()
  const now = useNow()
  const [busy, setBusy] = useState(false)
  const v = s.viewer
  if (!v) return null

  const opensAt = new Date(v.join_opens_at).getTime()
  const endsAt = new Date(s.ends_at).getTime()
  const closed = s.status === 'cancelled' || s.status === 'ended' || now >= endsAt
  const canJoin = v.registered && v.eligible && !closed && (now >= opensAt || s.status === 'live')

  const refresh = () => qc.invalidateQueries({ queryKey: ['live'] })
  const act = async (fn: () => Promise<unknown>, ok: string) => {
    setBusy(true)
    try {
      await fn()
      toast.success(ok)
      await refresh()
      void qc.invalidateQueries({ queryKey: ['me', 'dashboard'] })
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Something went wrong')
    } finally {
      setBusy(false)
    }
  }

  if (s.status === 'cancelled') return <p className="text-destructive text-sm font-medium">Cancelled</p>
  if (closed) {
    return s.has_replay ? (
      <Button size="sm" variant="outline" asChild>
        <Link to={`/app/replays/${s.id}`}>Watch replay</Link>
      </Button>
    ) : (
      <p className="text-muted-foreground text-sm">{v.attended ? 'You attended' : 'Ended'}</p>
    )
  }
  if (!v.eligible) return s.required_tier ? <UpgradeCta tier={s.required_tier} /> : null

  return (
    <div className="flex flex-wrap items-center gap-2">
      {canJoin ? (
        <Button size="sm" variant="accent" asChild>
          <Link to={`/live/${s.id}`}>
            <Video aria-hidden /> Join now
          </Link>
        </Button>
      ) : v.registered ? (
        <span className="text-muted-foreground text-sm tabular-nums" aria-live="off">
          Join opens in {formatCountdown(opensAt - now)}
        </span>
      ) : null}

      {!v.registered && (
        <Button
          size="sm"
          loading={busy}
          disabled={!v.can_register}
          onClick={() =>
            void act(() => liveApi.register(s.id), "You're registered — check your email for the invite")
          }
        >
          {v.can_register ? 'Register' : 'Full'}
        </Button>
      )}
      {v.registered && !compact && (
        <>
          <Button size="sm" variant="ghost" asChild>
            <a href={s.calendar.google} target="_blank" rel="noopener noreferrer">
              <CalendarPlus aria-hidden /> Google Calendar
            </a>
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() =>
              liveApi
                .downloadIcs(s.calendar.ics, `${s.title}.ics`)
                .catch(() => toast.error('Download failed'))
            }
          >
            <Download aria-hidden /> .ics
          </Button>
          {s.status === 'scheduled' && now < opensAt && (
            <Button
              size="sm"
              variant="ghost"
              loading={busy}
              onClick={() => void act(() => liveApi.unregister(s.id), 'Registration cancelled')}
            >
              Cancel registration
            </Button>
          )}
        </>
      )}
    </div>
  )
}
