import { useEffect, useRef, useState } from 'react'
import { Alert } from '@/components/ui/alert'
import type { JoinPayload } from '@/types/live'

type SdkJoin = Extract<JoinPayload, { mode: 'zoom_sdk' }>

/**
 * Zoom Meeting SDK "component view" inside our page. Loaded lazily (it's large). The registration's
 * customer key is passed so Zoom's participant report maps back to the learner for attendance.
 */
export default function ZoomEmbed({ join, onLeave }: { join: SdkJoin; onLeave: () => void }) {
  const rootRef = useRef<HTMLDivElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [status, setStatus] = useState<'connecting' | 'connected'>('connecting')
  const leaveRef = useRef(onLeave)
  useEffect(() => {
    leaveRef.current = onLeave
  })

  useEffect(() => {
    let disposed = false
    let destroy: (() => void) | null = null
    void (async () => {
      try {
        const { default: ZoomMtgEmbedded } = await import('@zoom/meetingsdk/embedded')
        if (disposed || !rootRef.current) return
        const client = ZoomMtgEmbedded.createClient()
        destroy = () => ZoomMtgEmbedded.destroyClient()
        await client.init({
          zoomAppRoot: rootRef.current,
          language: 'en-US',
          patchJsMedia: true,
          leaveOnPageUnload: true,
          customize: { video: { isResizable: true, viewSizes: { default: { width: 1000, height: 562 } } } },
        })
        client.on('connection-change', (p: { state?: string }) => {
          if (p.state === 'Connected') setStatus('connected')
          if (p.state === 'Closed') leaveRef.current()
        })
        await client.join({
          signature: join.signature,
          meetingNumber: join.meeting_number,
          password: join.password,
          userName: join.user_name,
          userEmail: join.user_email,
          customerKey: join.customer_key,
        })
      } catch (e) {
        if (disposed) return
        const reason = (e as { reason?: string; message?: string })?.reason ?? (e as Error)?.message
        setError(
          reason
            ? `Couldn't start the in-browser meeting: ${reason}`
            : "Couldn't start the in-browser meeting.",
        )
      }
    })()
    return () => {
      disposed = true
      destroy?.()
    }
  }, [join])

  return (
    <div className="grid gap-3">
      {error && <Alert variant="destructive">{error} Use “Open in Zoom” instead.</Alert>}
      {!error && status === 'connecting' && (
        <p role="status" className="text-muted-foreground text-sm">
          Connecting to the class… allow camera and microphone access if your browser asks.
        </p>
      )}
      <div ref={rootRef} className="min-h-[60dvh] w-full" />
    </div>
  )
}
