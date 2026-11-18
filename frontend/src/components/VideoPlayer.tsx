import Hls from 'hls.js'
import Plyr from 'plyr'
import 'plyr/dist/plyr.css'
import { useEffect, useRef, useState } from 'react'
import { Alert } from '@/components/ui/alert'
import type { Playback } from '@/types/courses'

interface Props {
  /** Fetches a fresh short-lived signed URL (called on mount and whenever the token expires). */
  getSource: () => Promise<Playback>
  startAt?: number
  /** Resume position changed without watching (seek / pause with nothing new watched). */
  onProgress?: (seconds: number) => void
  /** Every `heartbeatSeconds` of playback (and on pause/end): position, media seconds actually watched since the last beat, speed. */
  onHeartbeat?: (beat: { position: number; watched: number; rate: number }) => void
  heartbeatSeconds?: number
  onEnded?: () => void
  title: string
}

const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]

/**
 * Plyr UI + hls.js. Signed URLs expire, so on an expired-token (403) network error we
 * fetch a new URL and resume at the same position. No download control, no context menu.
 */
export function VideoPlayer({
  getSource,
  startAt = 0,
  onProgress,
  onHeartbeat,
  heartbeatSeconds = 15,
  onEnded,
  title,
}: Props) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [error, setError] = useState<string | null>(null)
  const sourceRef = useRef(getSource)
  const progressRef = useRef(onProgress)
  const endedRef = useRef(onEnded)
  const heartbeatRef = useRef(onHeartbeat)

  useEffect(() => {
    sourceRef.current = getSource
    progressRef.current = onProgress
    endedRef.current = onEnded
    heartbeatRef.current = onHeartbeat
  })

  useEffect(() => {
    const container = containerRef.current
    if (!container) return
    // We create the <video> ourselves: Plyr.destroy() swaps the element for a clone, which
    // would desync a React-managed node (StrictMode double-mount, lesson changes).
    const video = document.createElement('video')
    video.playsInline = true
    video.setAttribute('controlslist', 'nodownload noremoteplayback')
    video.setAttribute('aria-label', title)
    container.replaceChildren(video)
    let hls: Hls | null = null
    let disposed = false
    let refreshes = 0
    // Watch-time accounting: only continuous forward playback counts; seeks/scrubbing don't.
    let watched = 0
    let lastTime = startAt

    const player = new Plyr(video, {
      controls: [
        'play-large',
        'rewind',
        'play',
        'fast-forward',
        'progress',
        'current-time',
        'duration',
        'mute',
        'volume',
        'settings',
        'pip',
        'fullscreen',
      ],
      settings: ['speed'],
      speed: { selected: 1, options: SPEEDS },
      keyboard: { focused: true, global: false },
      tooltips: { controls: true, seek: true },
      i18n: { play: `Play ${title}` },
    })

    // Dynamic watermark: lives inside Plyr's container so it stays visible in fullscreen,
    // and jumps to a new spot every few seconds so it can't simply be cropped out.
    let mark: HTMLDivElement | null = null
    let markTimer = 0
    const showWatermark = (text: string) => {
      const host = (player.elements as { container?: HTMLElement }).container
      if (!host || mark) return
      mark = document.createElement('div')
      mark.textContent = text
      mark.setAttribute('aria-hidden', 'true')
      mark.className =
        'pointer-events-none absolute z-10 select-none font-mono text-xs text-white/40 transition-all duration-1000 sm:text-sm'
      mark.style.textShadow = '0 0 2px rgba(0,0,0,.6)'
      host.style.position = 'relative'
      host.appendChild(mark)
      const move = () => {
        if (!mark) return
        mark.style.left = `${5 + Math.random() * 60}%`
        mark.style.top = `${5 + Math.random() * 75}%`
      }
      move()
      markTimer = window.setInterval(move, 7000)
    }

    const load = async (resumeAt: number) => {
      try {
        const src = await sourceRef.current()
        if (disposed) return
        if (src.poster) video.poster = src.poster
        if (src.watermark) showWatermark(`${src.watermark.text} · ${src.watermark.id}`)
        if (Hls.isSupported()) {
          hls?.destroy()
          hls = new Hls({ startPosition: resumeAt })
          hls.on(Hls.Events.ERROR, (_e, data) => {
            const status = (data.response as { code?: number } | undefined)?.code
            if (data.fatal && (status === 403 || status === 401) && refreshes < 3) {
              refreshes += 1
              void load(video.currentTime) // token expired → new signed URL, same position
            } else if (data.fatal) {
              setError('Playback failed. Please reload the page.')
            }
          })
          hls.loadSource(src.url)
          hls.attachMedia(video)
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = src.url // Safari native HLS
          video.addEventListener('loadedmetadata', () => (video.currentTime = resumeAt), { once: true })
        } else {
          setError('Your browser cannot play this video.')
        }
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Could not load the video.')
      }
    }

    const flush = () => {
      const w = Math.min(120, Math.floor(watched))
      if (w < 1) return false
      watched -= w
      heartbeatRef.current?.({
        position: Math.floor(video.currentTime),
        watched: w,
        rate: video.playbackRate || 1,
      })
      return true
    }
    const onTime = () => {
      const t = video.currentTime
      const d = t - lastTime
      if (!video.paused && !video.seeking && d > 0 && d < 3) watched += d
      lastTime = t
    }
    const onSeeked = () => {
      lastTime = video.currentTime
      progressRef.current?.(Math.floor(video.currentTime))
    }
    const onPause = () => {
      if (!flush()) progressRef.current?.(Math.floor(video.currentTime))
    }
    const onEnd = () => {
      flush()
      endedRef.current?.()
    }
    const noMenu = (e: Event) => e.preventDefault()
    const beat = window.setInterval(() => !video.paused && flush(), heartbeatSeconds * 1000)

    video.addEventListener('timeupdate', onTime)
    video.addEventListener('seeked', onSeeked)
    video.addEventListener('pause', onPause)
    video.addEventListener('ended', onEnd)
    video.addEventListener('contextmenu', noMenu)
    void load(startAt)

    return () => {
      disposed = true
      window.clearInterval(beat)
      window.clearInterval(markTimer)
      mark?.remove()
      flush() // leaving the lesson mid-play still credits what was watched
      video.removeEventListener('timeupdate', onTime)
      video.removeEventListener('seeked', onSeeked)
      video.removeEventListener('pause', onPause)
      video.removeEventListener('ended', onEnd)
      video.removeEventListener('contextmenu', noMenu)
      hls?.destroy()
      player.destroy()
      container.replaceChildren()
    }
    // Re-initialise only when the lesson (source getter identity per lesson) changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [title])

  return (
    <div className="overflow-hidden rounded-xl bg-black">
      {error && (
        <Alert variant="destructive" className="rounded-none border-0">
          {error}
        </Alert>
      )}
      {/* The <video> element is created in the effect. Captions: not yet supported — Bunny VTT
          caption tracks are a planned follow-up (docs/PROGRESS.md). */}
      <div ref={containerRef} />
    </div>
  )
}
