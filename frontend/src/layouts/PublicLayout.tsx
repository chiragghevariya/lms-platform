import { lazy, Suspense, useState } from 'react'
import { Menu } from 'lucide-react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { Logo } from '@/components/Logo'
import { Seo } from '@/components/Seo'
import { ThemeToggle } from '@/components/ThemeToggle'
import { ImpersonationBanner } from '@/features/admin/components/ImpersonationBanner'
import { SiteBanner } from '@/features/cms/components/SiteBanner'
import { Button } from '@/components/ui/button'
import { env } from '@/lib/env'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/auth-store'
import { useConsentStore } from '@/stores/consent-store'

const links = [
  { to: '/', label: 'Home', end: true },
  { to: '/courses', label: 'Courses' },
  { to: '/pricing', label: 'Pricing' },
  { to: '/blog', label: 'Blog' },
  { to: '/about', label: 'About' },
  { to: '/contact', label: 'Contact' },
]

const footerLinks = [
  { to: '/about', label: 'About' },
  { to: '/courses', label: 'Courses' },
  { to: '/pricing', label: 'Pricing' },
  { to: '/blog', label: 'Blog' },
  { to: '/contact', label: 'Contact' },
  { to: '/terms', label: 'Terms' },
  { to: '/privacy', label: 'Privacy' },
]

const loadMobileNav = () => import('./MobileNavMenu')
const MobileNavMenu = lazy(loadMobileNav)
const NotificationBell = lazy(() =>
  import('@/features/notifications/components/NotificationBell').then((m) => ({
    default: m.NotificationBell,
  })),
)
const UserMenu = lazy(() => import('@/components/UserMenu').then((m) => ({ default: m.UserMenu })))

/** Plain button until first use, then the Radix menu (opened): keeps Radix out of the first paint. */
function MobileNav() {
  const [armed, setArmed] = useState(false)
  const button = (
    <Button
      variant="ghost"
      size="icon"
      className="md:hidden"
      aria-label="Open menu"
      aria-haspopup="menu"
      onPointerEnter={() => void loadMobileNav()}
      onClick={() => setArmed(true)}
    >
      <Menu aria-hidden />
    </Button>
  )
  if (!armed) return button
  return (
    <Suspense fallback={button}>
      <MobileNavMenu links={links} defaultOpen />
    </Suspense>
  )
}

export function PublicLayout() {
  const user = useAuthStore((s) => s.user)

  return (
    <div className="flex min-h-dvh flex-col">
      <Seo />
      <ImpersonationBanner />
      <a
        href="#main"
        className="bg-primary text-primary-foreground sr-only z-50 rounded px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
      >
        Skip to content
      </a>
      <header className="bg-background/80 sticky top-0 z-40 border-b backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
          <Logo />
          <nav aria-label="Main" className="ml-4 hidden gap-1 md:flex">
            {links.map((l) => (
              <NavLink
                key={l.to}
                to={l.to}
                end={l.end}
                className={({ isActive }) =>
                  cn('hover:bg-muted rounded-md px-3 py-2 text-sm font-medium', isActive && 'text-primary')
                }
              >
                {l.label}
              </NavLink>
            ))}
          </nav>
          <div className="ml-auto flex items-center gap-2">
            <MobileNav />
            <ThemeToggle />
            {user && (
              <Suspense fallback={<span className="size-9" />}>
                <NotificationBell />
              </Suspense>
            )}
            {user ? (
              <Suspense fallback={<span className="bg-muted size-9 rounded-full" />}>
                <UserMenu user={user} />
              </Suspense>
            ) : (
              <>
                <Button variant="ghost" asChild className="hidden sm:inline-flex">
                  <Link to="/login">Log in</Link>
                </Button>
                <Button asChild>
                  <Link to="/register">Get started</Link>
                </Button>
              </>
            )}
          </div>
        </div>
      </header>
      <SiteBanner />
      {/* Reserve a full screen so the footer never jumps up while a page loads (CLS). */}
      <main id="main" className="min-h-[calc(100dvh-4rem)] flex-1">
        <Outlet />
      </main>
      <footer className="text-muted-foreground border-t">
        <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 py-8 text-sm sm:flex-row sm:justify-between">
          <p>
            © {new Date().getFullYear()} {env.appName} · {env.tagline}
          </p>
          <nav aria-label="Footer">
            <ul className="flex flex-wrap justify-center gap-x-4 gap-y-2">
              {footerLinks.map((l) => (
                <li key={l.to}>
                  <Link to={l.to} className="hover:text-foreground">
                    {l.label}
                  </Link>
                </li>
              ))}
              <li>
                <button
                  type="button"
                  className="hover:text-foreground"
                  onClick={() => useConsentStore.getState().reopen()}
                >
                  Cookie settings
                </button>
              </li>
            </ul>
          </nav>
        </div>
      </footer>
    </div>
  )
}
