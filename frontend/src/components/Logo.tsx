import { Link } from 'react-router-dom'
import { env } from '@/lib/env'
import { cn } from '@/lib/utils'

export function Logo({ className, to = '/' }: { className?: string; to?: string }) {
  return (
    <Link to={to} className={cn('flex items-center gap-2 font-bold tracking-tight', className)}>
      <img src="/favicon.svg" alt="" className="size-8" />
      <span className="text-lg">{env.appName}</span>
    </Link>
  )
}
