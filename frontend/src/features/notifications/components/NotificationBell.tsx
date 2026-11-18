import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'
import type { AppNotification } from '@/types/notifications'
import { notificationsApi, useUnreadCount } from '../api'
import { timeAgo } from './timeAgo'

export function NotificationBell() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const { data: count } = useUnreadCount(true)
  const unread = count?.unread ?? 0
  const { data } = useQuery({
    queryKey: ['notifications', 'latest'],
    queryFn: () => notificationsApi.list(1, false),
    enabled: open,
  })

  const refresh = () => void qc.invalidateQueries({ queryKey: ['notifications'] })
  const openItem = async (n: AppNotification) => {
    setOpen(false)
    if (!n.read_at) await notificationsApi.markRead(n.id).catch(() => {})
    refresh()
    if (n.url) {
      const u = new URL(n.url, window.location.origin)
      if (u.origin === window.location.origin) navigate(u.pathname + u.search)
      else window.location.assign(n.url)
    }
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative"
          aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
        >
          <Bell aria-hidden />
          {unread > 0 && (
            <span
              className="bg-destructive absolute -top-0.5 -right-0.5 grid min-w-5 place-items-center rounded-full px-1 text-[10px] font-bold text-white"
              aria-hidden
            >
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-80 p-0">
        <div className="flex items-center justify-between border-b px-3 py-2">
          <span className="text-sm font-semibold">Notifications</span>
          {unread > 0 && (
            <button
              type="button"
              className="text-primary text-xs hover:underline"
              onClick={() => notificationsApi.markAllRead().then(refresh)}
            >
              Mark all read
            </button>
          )}
        </div>
        <ul className="max-h-96 overflow-y-auto">
          {data?.data.length === 0 && (
            <li className="text-muted-foreground p-4 text-center text-sm">You're all caught up.</li>
          )}
          {data?.data.slice(0, 8).map((n) => (
            <li key={n.id}>
              <button
                type="button"
                onClick={() => void openItem(n)}
                className={cn(
                  'hover:bg-muted flex w-full gap-2 px-3 py-2 text-left',
                  !n.read_at && 'bg-primary/5',
                )}
              >
                <span
                  className={cn(
                    'mt-1.5 size-2 shrink-0 rounded-full',
                    n.read_at ? 'bg-transparent' : 'bg-primary',
                  )}
                  aria-hidden
                />
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium">{n.title}</span>
                  {n.body && (
                    <span className="text-muted-foreground line-clamp-2 block text-xs">{n.body}</span>
                  )}
                  <span className="text-muted-foreground text-[11px]">
                    {timeAgo(n.created_at)}
                    {!n.read_at && <span className="sr-only"> (unread)</span>}
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
        <div className="flex justify-between border-t px-3 py-2 text-xs">
          <Link
            to="/app/notifications"
            onClick={() => setOpen(false)}
            className="text-primary hover:underline"
          >
            See all
          </Link>
          <Link
            to="/app/notification-settings"
            onClick={() => setOpen(false)}
            className="text-muted-foreground hover:underline"
          >
            Settings
          </Link>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
