import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Settings } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import type { AppNotification } from '@/types/notifications'
import { notificationsApi } from '../api'
import { timeAgo } from '../components/timeAgo'

export default function NotificationsPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['notifications', 'page', page, unreadOnly],
    queryFn: () => notificationsApi.list(page, unreadOnly),
  })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['notifications'] })

  const open = async (n: AppNotification) => {
    if (!n.read_at) await notificationsApi.markRead(n.id).catch(() => {})
    refresh()
    if (n.url) {
      const u = new URL(n.url, window.location.origin)
      if (u.origin === window.location.origin) navigate(u.pathname + u.search)
      else window.location.assign(n.url)
    }
  }

  return (
    <>
      <Seo title="Notifications" noindex />
      <PageHeader
        title="Notifications"
        description={data ? `${data.meta.unread} unread` : undefined}
        actions={
          <div className="flex gap-2">
            {!!data?.meta.unread && (
              <Button variant="outline" onClick={() => notificationsApi.markAllRead().then(refresh)}>
                Mark all read
              </Button>
            )}
            <Button variant="ghost" asChild>
              <Link to="/app/notification-settings">
                <Settings aria-hidden /> Settings
              </Link>
            </Button>
          </div>
        }
      />
      <label className="mb-4 flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={unreadOnly}
          onChange={(e) => {
            setUnreadOnly(e.target.checked)
            setPage(1)
          }}
        />{' '}
        Unread only
      </label>
      {isLoading && <Skeleton className="h-40" />}
      {data?.data.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            Nothing here — you're all caught up.
          </CardContent>
        </Card>
      )}
      <ul className="divide-y rounded-xl border">
        {data?.data.map((n) => (
          <li key={n.id}>
            <button
              type="button"
              onClick={() => void open(n)}
              className={cn('hover:bg-muted flex w-full gap-3 p-4 text-left', !n.read_at && 'bg-primary/5')}
            >
              <span
                className={cn(
                  'mt-1.5 size-2 shrink-0 rounded-full',
                  n.read_at ? 'bg-transparent' : 'bg-primary',
                )}
                aria-hidden
              />
              <span className="min-w-0 flex-1">
                <span className="block font-medium">
                  {n.title}
                  {!n.read_at && <span className="sr-only"> (unread)</span>}
                </span>
                {n.body && <span className="text-muted-foreground block text-sm">{n.body}</span>}
              </span>
              <span className="text-muted-foreground shrink-0 text-xs">{timeAgo(n.created_at)}</span>
            </button>
          </li>
        ))}
      </ul>
      {data && data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Previous
          </Button>
          <span className="text-sm">
            Page {page} of {data.meta.last_page}
          </span>
          <Button
            size="sm"
            variant="outline"
            disabled={page >= data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </>
  )
}
