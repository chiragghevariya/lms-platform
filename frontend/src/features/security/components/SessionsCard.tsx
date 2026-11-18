import { Laptop, Smartphone } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { securityApi, useSecurityMutation, useSessions } from '../api'

export function SessionsCard() {
  const { data, isLoading } = useSessions()
  const revoke = useSecurityMutation(securityApi.revokeSession, [['me', 'sessions']])
  const others = useSecurityMutation(securityApi.logoutOthers, [
    ['me', 'sessions'],
    ['me', 'activity'],
  ])
  const [password, setPassword] = useState('')
  const [confirming, setConfirming] = useState(false)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Where you're signed in</CardTitle>
        <CardDescription>Browsers and apps with access to your account.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {isLoading && <Skeleton className="h-24" />}
        <ul className="divide-y rounded-lg border">
          {data?.map((s) => (
            <li key={s.id} className="flex items-center gap-3 p-3">
              {s.type === 'app' ? (
                <Smartphone className="text-muted-foreground size-5 shrink-0" aria-hidden />
              ) : (
                <Laptop className="text-muted-foreground size-5 shrink-0" aria-hidden />
              )}
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 font-medium">
                  <span className="truncate">{s.name}</span>
                  {s.is_current && <Badge variant="secondary">This device</Badge>}
                </p>
                <p className="text-muted-foreground text-xs">
                  {[s.ip_address, s.last_active_at && `Active ${new Date(s.last_active_at).toLocaleString()}`]
                    .filter(Boolean)
                    .join(' · ')}
                </p>
              </div>
              {!s.is_current && (
                <Button
                  size="sm"
                  variant="ghost"
                  disabled={revoke.isPending}
                  onClick={() => revoke.mutate(s.id, { onSuccess: () => toast.success('Signed out') })}
                  aria-label={`Sign out ${s.name}`}
                >
                  Sign out
                </Button>
              )}
            </li>
          ))}
          {data?.length === 0 && (
            <li className="text-muted-foreground p-3 text-sm">No active sessions found.</li>
          )}
        </ul>

        {!confirming ? (
          <Button variant="outline" className="justify-self-start" onClick={() => setConfirming(true)}>
            Log out other devices
          </Button>
        ) : (
          <form
            className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              others.mutate(password, {
                onSuccess: (r) => {
                  toast.success(`Signed out of ${r.revoked} other session(s)`)
                  setConfirming(false)
                  setPassword('')
                },
              })
            }}
          >
            <FormField id="others-password" label="Confirm your password" error={others.error?.message}>
              {(a) => (
                <Input
                  {...a}
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              )}
            </FormField>
            <Button type="submit" variant="destructive" loading={others.isPending} disabled={!password}>
              Log out others
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
