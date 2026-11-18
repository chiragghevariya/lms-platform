import { Check, Copy, Share2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { shareLinks } from '../api'

/** Read-only link + copy + WhatsApp / LinkedIn / X (and the native share sheet on phones). */
export function ShareLink({ url, text, label = 'Your link' }: { url: string; text: string; label?: string }) {
  const [copied, setCopied] = useState(false)
  const links = shareLinks(url, text)
  const canShare = typeof navigator !== 'undefined' && 'share' in navigator

  return (
    <div className="grid gap-2">
      <label htmlFor="share-url" className="text-sm font-medium">
        {label}
      </label>
      <div className="flex flex-col gap-2 sm:flex-row">
        <Input
          id="share-url"
          readOnly
          value={url}
          onFocus={(e) => e.currentTarget.select()}
          className="font-mono text-sm"
        />
        <Button
          variant="outline"
          onClick={async () => {
            try {
              await navigator.clipboard.writeText(url)
              setCopied(true)
              setTimeout(() => setCopied(false), 2000)
            } catch {
              toast.error('Copy failed — select the link and copy it manually')
            }
          }}
        >
          {copied ? <Check aria-hidden /> : <Copy aria-hidden />} {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="secondary" asChild>
          <a href={links.whatsapp} target="_blank" rel="noopener noreferrer">
            WhatsApp
          </a>
        </Button>
        <Button size="sm" variant="secondary" asChild>
          <a href={links.linkedin} target="_blank" rel="noopener noreferrer">
            LinkedIn
          </a>
        </Button>
        <Button size="sm" variant="secondary" asChild>
          <a href={links.x} target="_blank" rel="noopener noreferrer">
            X
          </a>
        </Button>
        {canShare && (
          <Button size="sm" variant="ghost" onClick={() => navigator.share({ url, text }).catch(() => {})}>
            <Share2 aria-hidden /> More
          </Button>
        )}
      </div>
    </div>
  )
}
