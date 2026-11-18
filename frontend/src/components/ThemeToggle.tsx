import { Moon, Sun } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { Button } from '@/components/ui/button'

const load = () => import('./ThemeMenu')
const ThemeMenu = lazy(load)

/** Plain button until first use, then the full Radix menu (opened) — same look, lighter first load. */
export function ThemeToggle() {
  const [armed, setArmed] = useState(false)
  const button = (
    <Button
      variant="ghost"
      size="icon"
      aria-label="Change colour theme"
      aria-haspopup="menu"
      onPointerEnter={() => void load()}
      onFocus={() => void load()}
      onClick={() => setArmed(true)}
    >
      <Sun className="dark:hidden" aria-hidden />
      <Moon className="hidden dark:block" aria-hidden />
    </Button>
  )
  if (!armed) return button
  return (
    <Suspense fallback={button}>
      <ThemeMenu defaultOpen />
    </Suspense>
  )
}
