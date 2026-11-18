import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError, http } from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'

/** GDPR / DPDP self-service: download everything we hold, or delete the account. */
export function YourDataCard() {
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState(false)

  async function download() {
    setBusy(true)
    try {
      const res = await http.get<Blob>('/me/export', { responseType: 'blob' })
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url
      a.download = `my-data-${new Date().toISOString().slice(0, 10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      toast.error(
        e instanceof ApiError && e.status === 429
          ? 'You can download your data 3 times an hour.'
          : 'Download failed.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Your data</CardTitle>
        <CardDescription>
          Download a copy of everything we store about you, or delete your account permanently.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Button variant="outline" onClick={download} loading={busy}>
          <Download aria-hidden /> Download my data
        </Button>
        <Button variant="destructive" onClick={() => setOpen(true)}>
          <Trash2 aria-hidden /> Delete account
        </Button>
        {open && <DeleteDialog onClose={() => setOpen(false)} />}
      </CardContent>
    </Card>
  )
}

function DeleteDialog({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const setUser = useAuthStore((s) => s.setUser)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const check = useQuery({
    queryKey: ['me', 'deletion-check'],
    queryFn: async () =>
      (await http.get<{ data: { blockers: string[]; requires_password: boolean } }>('/me/deletion-check'))
        .data.data,
  })
  const blockers = check.data?.blockers ?? []

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      await http.delete('/me', { data: { confirm, password: password || undefined } })
      qc.clear()
      setUser(null)
      toast.success('Your account has been deleted.')
      navigate('/', { replace: true })
    } catch (e) {
      const err = e instanceof ApiError ? e : null
      setError(
        err?.errors.password?.[0] ??
          err?.errors.account?.join(' ') ??
          err?.message ??
          'Could not delete the account.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete your account?</DialogTitle>
          <DialogDescription>
            This can't be undone. Your profile, notes, progress and certificates are removed and any renewing
            membership is cancelled. Invoices are kept for tax law, without access to your account.
          </DialogDescription>
        </DialogHeader>
        {blockers.length > 0 ? (
          <ul role="alert" className="text-destructive list-disc pl-5 text-sm">
            {blockers.map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        ) : (
          <div className="grid gap-3">
            {check.data?.requires_password && (
              <div className="grid gap-1">
                <Label htmlFor="del-password">Password</Label>
                <Input
                  id="del-password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              </div>
            )}
            <div className="grid gap-1">
              <Label htmlFor="del-confirm">
                Type <strong>DELETE</strong> to confirm
              </Label>
              <Input
                id="del-confirm"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                autoComplete="off"
              />
            </div>
            {error && (
              <p role="alert" className="text-destructive text-sm">
                {error}
              </p>
            )}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            loading={busy}
            disabled={
              blockers.length > 0 || confirm !== 'DELETE' || (!!check.data?.requires_password && !password)
            }
            onClick={submit}
          >
            Delete permanently
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
