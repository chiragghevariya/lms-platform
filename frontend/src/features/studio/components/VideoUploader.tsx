import { UploadCloud } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import * as tus from 'tus-js-client'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { http } from '@/lib/api-client'
import type { VideoAsset } from '@/types/courses'
import { studioApi } from '../api'

/**
 * Direct browser → provider upload. Bunny: resumable TUS with a server-signed ticket (no
 * API key in the browser). Demo driver: plain multipart POST to our API.
 * Shows upload progress, then polls until encoding finishes.
 */
export function VideoUploader({
  title,
  onUploaded,
}: {
  title: string
  onUploaded: (video: VideoAsset) => void
}) {
  const [progress, setProgress] = useState<number | null>(null)
  const [status, setStatus] = useState<'idle' | 'uploading' | 'processing' | 'ready' | 'error'>('idle')
  const [error, setError] = useState<string | null>(null)
  const uploadRef = useRef<tus.Upload | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => () => void uploadRef.current?.abort(), [])

  const poll = async (id: string) => {
    for (let i = 0; i < 120; i++) {
      const v = await studioApi.video(id)
      if (v.status === 'ready' || v.status === 'failed') return v
      await new Promise((r) => setTimeout(r, 5000))
    }
    return studioApi.video(id)
  }

  const start = async (file: File) => {
    setError(null)
    setStatus('uploading')
    setProgress(0)
    try {
      const { video, upload } = await studioApi.createVideo(title || file.name)

      if (upload.protocol === 'tus') {
        await new Promise<void>((resolve, reject) => {
          const u = new tus.Upload(file, {
            endpoint: upload.endpoint,
            retryDelays: [0, 3000, 5000, 10000, 20000],
            headers: upload.headers,
            metadata: { filetype: file.type, title: title || file.name },
            onProgress: (sent, total) => setProgress(Math.round((sent / total) * 100)),
            onSuccess: () => resolve(),
            onError: (e) => reject(e),
          })
          uploadRef.current = u
          u.findPreviousUploads().then((prev) => {
            if (prev[0]) u.resumeFromPreviousUpload(prev[0])
            u.start()
          })
        })
        await studioApi.videoUploaded(video.id)
      } else {
        const fd = new FormData()
        fd.append('file', file)
        await http.post(upload.endpoint.replace(/^.*\/api\/v1/, ''), fd, {
          onUploadProgress: (e) => e.total && setProgress(Math.round((e.loaded / e.total) * 100)),
        })
      }

      setStatus('processing')
      const final = await poll(video.id)
      if (final.status === 'failed') throw new Error(final.error ?? 'Encoding failed')
      setStatus('ready')
      onUploaded(final)
    } catch (e) {
      setStatus('error')
      setError(e instanceof Error ? e.message : 'Upload failed')
    }
  }

  return (
    <div className="grid gap-3 rounded-lg border border-dashed p-4">
      <input
        ref={inputRef}
        type="file"
        accept="video/mp4,video/quicktime,video/webm,video/x-matroska"
        className="sr-only"
        aria-label="Choose a video file"
        onChange={(e) => {
          const f = e.target.files?.[0]
          e.target.value = ''
          if (f) void start(f)
        }}
      />
      {status === 'idle' || status === 'error' ? (
        <Button type="button" variant="outline" onClick={() => inputRef.current?.click()}>
          <UploadCloud aria-hidden /> {status === 'error' ? 'Try another file' : 'Upload video'}
        </Button>
      ) : (
        <div className="grid gap-1.5" aria-live="polite">
          <div className="flex justify-between text-sm">
            <span>
              {status === 'uploading'
                ? 'Uploading…'
                : status === 'processing'
                  ? 'Processing video…'
                  : 'Video ready'}
            </span>
            {status === 'uploading' && <span className="tabular-nums">{progress}%</span>}
          </div>
          <div
            role="progressbar"
            aria-label="Upload progress"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={status === 'uploading' ? (progress ?? 0) : 100}
            className="bg-muted h-2 overflow-hidden rounded-full"
          >
            <div
              className={
                status === 'processing'
                  ? 'bg-primary h-full w-full animate-pulse'
                  : 'bg-primary h-full transition-[width]'
              }
              style={status === 'uploading' ? { width: `${progress ?? 0}%` } : undefined}
            />
          </div>
          {status === 'uploading' && (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              className="justify-self-start"
              onClick={() => {
                void uploadRef.current?.abort()
                setStatus('idle')
              }}
            >
              Cancel upload
            </Button>
          )}
        </div>
      )}
      {error && <Alert variant="destructive">{error}</Alert>}
    </div>
  )
}
