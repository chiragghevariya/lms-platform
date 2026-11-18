import { GraduationCap, LogOut, Presentation, Shield, ShieldCheck, User as UserIcon } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useLogout } from '@/features/auth/hooks'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { hasAnyRole, homePathFor } from '@/lib/membership'
import type { User } from '@/types/api'

const initials = (name: string) =>
  name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')

export function UserMenu({ user }: { user: User }) {
  const logout = useLogout()
  const navigate = useNavigate()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="rounded-full" aria-label="Open account menu">
          <Avatar>
            {user.avatar_url && <AvatarImage src={user.avatar_url} alt="" />}
            <AvatarFallback>{initials(user.name)}</AvatarFallback>
          </Avatar>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <DropdownMenuLabel className="flex flex-col gap-1">
          <span className="truncate">{user.name}</span>
          <span className="text-muted-foreground truncate text-xs font-normal">{user.email}</span>
          <span>
            <TierBadge tier={user.membership.tier} />
          </span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link to="/app">
            <GraduationCap aria-hidden /> My learning
          </Link>
        </DropdownMenuItem>
        {hasAnyRole(user, ['instructor', 'admin', 'super_admin']) && (
          <DropdownMenuItem asChild>
            <Link to="/instructor">
              <Presentation aria-hidden /> Instructor studio
            </Link>
          </DropdownMenuItem>
        )}
        {user.is_staff && (
          <DropdownMenuItem asChild>
            <Link to="/admin">
              <Shield aria-hidden /> Admin
            </Link>
          </DropdownMenuItem>
        )}
        <DropdownMenuItem asChild>
          <Link to={`${homePathFor(user)}/profile`}>
            <UserIcon aria-hidden /> Profile
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem asChild>
          <Link to={`${homePathFor(user)}/security`}>
            <ShieldCheck aria-hidden /> Security
          </Link>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={() =>
            logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })
          }
        >
          <LogOut aria-hidden /> Log out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
