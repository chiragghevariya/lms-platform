import { Info, Megaphone, TriangleAlert, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useSite } from '../api'
import type { Banner } from '../types'

const STYLE: Record<Banner['variant'], { cls: string; icon: typeof Info }> = {
  info: { cls: 'bg-secondary text-secondary-foreground', icon: Info },
  promo: { cls: 'bg-primary text-primary-foreground', icon: Megaphone },
  warning: {
    cls: 'bg-amber-100 text-amber-950 dark:bg-amber-900/60 dark:text-amber-50',
    icon: TriangleAlert,
  },
}

const storageKey = (id: string) => `banner-dismissed:${id}`

function isDismissed(id: string) {
  try {
    return localStorage.getItem(storageKey(id)) === '1'
  } catch {
    return false
  }
}

/** Admin-managed site banner (Settings → Website → Banners); dismissal is remembered per banner. */
export function SiteBanner() {
  const { data } = useSite()
  const banner = data?.banner
  const [dismissed, setDismissed] = useState<string | null>(null)

  if (!banner || dismissed === banner.id || isDismissed(banner.id)) return null

  const { cls, icon: Icon } = STYLE[banner.variant] ?? STYLE.info
  const internal = banner.link_url?.startsWith('/')

  function dismiss() {
    try {
      localStorage.setItem(storageKey(banner!.id), '1')
    } catch {
      /* private mode: hide for this visit only */
    }
    setDismissed(banner!.id)
  }

  return (
    <div role="region" aria-label="Announcement" className={cn('text-sm', cls)}>
      <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2">
        <Icon className="size-4 shrink-0" aria-hidden />
        <p className="flex-1">
          {banner.message}
          {banner.link_url && banner.link_text && (
            <>
              {' '}
              {internal ? (
                <Link to={banner.link_url} className="font-semibold underline underline-offset-2">
                  {banner.link_text}
                </Link>
              ) : (
                <a
                  href={banner.link_url}
                  className="font-semibold underline underline-offset-2"
                  rel="noopener noreferrer"
                  target="_blank"
                >
                  {banner.link_text}
                </a>
              )}
            </>
          )}
        </p>
        <button
          type="button"
          onClick={dismiss}
          className="rounded p-1 opacity-80 hover:opacity-100 focus-visible:ring-2 focus-visible:ring-current focus-visible:outline-none"
          aria-label="Dismiss announcement"
        >
          <X className="size-4" aria-hidden />
        </button>
      </div>
    </div>
  )
}
