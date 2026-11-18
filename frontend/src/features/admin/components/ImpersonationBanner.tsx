import { useQueryClient } from '@tanstack/react-query'
import { Eye } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { http } from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import type { User } from '@/types/api'

/** Shown on every page while an admin is "viewing as" a user (admin panel impersonation). */
export function ImpersonationBanner() {
  const user = useAuthStore((s) => s.user)
  const setUser = useAuthStore((s) => s.setUser)
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)

  if (!user?.impersonator) return null

  async function stop() {
    setBusy(true)
    try {
      const res = await http.post<{ data: User }>('/impersonation/stop')
      qc.clear()
      setUser(res.data.data)
      navigate(`/admin/users?open=${user!.id}`)
    } catch {
      toast.error('Could not stop impersonating. Reload the page and try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      role="status"
      className="sticky top-0 z-[60] flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-400 px-4 py-2 text-sm text-amber-950"
    >
      <Eye className="size-4" aria-hidden />
      <span>
        You are viewing as <strong>{user.name}</strong> ({user.email}). Signed in as {user.impersonator.name}.
        Billing and security changes are blocked.
      </span>
      <Button
        size="sm"
        variant="outline"
        className="h-7 border-amber-950/40 bg-transparent"
        disabled={busy}
        onClick={stop}
      >
        {busy ? 'Returning…' : 'Stop viewing as user'}
      </Button>
    </div>
  )
}
