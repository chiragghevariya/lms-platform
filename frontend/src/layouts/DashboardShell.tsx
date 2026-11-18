import { Menu, X, type LucideIcon } from 'lucide-react'
import { Fragment, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Logo } from '@/components/Logo'
import { Seo } from '@/components/Seo'
import { ThemeToggle } from '@/components/ThemeToggle'
import { ImpersonationBanner } from '@/features/admin/components/ImpersonationBanner'
import { NotificationBell } from '@/features/notifications/components/NotificationBell'
import { Button } from '@/components/ui/button'
import { UserMenu } from '@/components/UserMenu'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  /** Section heading the item is listed under (consecutive items share it). */
  group?: string
}

/** Shared sidebar shell for Student / Instructor / Admin areas. Mobile-first: drawer under `lg`. */
export function DashboardShell({ nav, area, homePath }: { nav: NavItem[]; area: string; homePath: string }) {
  const user = useAuthStore((s) => s.user)
  const [open, setOpen] = useState(false)

  const sidebar = (
    <nav aria-label={`${area} navigation`} className="flex flex-col gap-1 p-3">
      {nav.map(({ to, label, icon: Icon, end, group }, i) => (
        <Fragment key={to}>
          {group && group !== nav[i - 1]?.group && (
            <p className="text-muted-foreground px-3 pt-4 pb-1 text-xs font-semibold tracking-wider uppercase first:pt-0">
              {group}
            </p>
          )}
          <NavLink
            to={to}
            end={end}
            onClick={() => setOpen(false)}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                isActive
                  ? 'bg-secondary text-secondary-foreground'
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground',
              )
            }
          >
            <Icon className="size-4" aria-hidden />
            {label}
          </NavLink>
        </Fragment>
      ))}
    </nav>
  )

  return (
    <>
      <ImpersonationBanner />
      <div className="flex min-h-dvh">
        {/* Default head tags for the area; pages with their own <Seo> override these. */}
        <Seo title={area} noindex />
        <a
          href="#main"
          className="bg-primary text-primary-foreground sr-only z-50 rounded px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
        >
          Skip to content
        </a>
        <aside className="bg-card sticky top-0 hidden h-dvh w-64 shrink-0 overflow-y-auto border-r lg:block">
          <div className="flex h-16 items-center border-b px-4">
            <Logo to={homePath} />
          </div>
          <p className="text-muted-foreground px-6 pt-4 text-xs font-semibold tracking-wider uppercase">
            {area}
          </p>
          {sidebar}
        </aside>

        {open && (
          <div
            className="fixed inset-0 z-50 lg:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="Navigation"
          >
            <button
              className="absolute inset-0 bg-black/50"
              aria-label="Close navigation"
              onClick={() => setOpen(false)}
            />
            <aside className="bg-card relative h-full w-72 max-w-[85%] overflow-y-auto border-r shadow-xl">
              <div className="flex h-16 items-center justify-between border-b px-4">
                <Logo to={homePath} />
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={() => setOpen(false)}
                  aria-label="Close navigation"
                >
                  <X aria-hidden />
                </Button>
              </div>
              {sidebar}
            </aside>
          </div>
        )}

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="bg-background/80 sticky top-0 z-30 flex h-16 items-center gap-2 border-b px-4 backdrop-blur">
            <Button
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={() => setOpen(true)}
              aria-label="Open navigation"
            >
              <Menu aria-hidden />
            </Button>
            <span className="font-semibold lg:hidden">{area}</span>
            <div className="ml-auto flex items-center gap-2">
              <ThemeToggle />
              {user && <NotificationBell />}
              {user && <UserMenu user={user} />}
            </div>
          </header>
          <main id="main" className="mx-auto w-full max-w-6xl flex-1 p-4 sm:p-6 lg:p-8">
            <Outlet />
          </main>
        </div>
      </div>
    </>
  )
}
