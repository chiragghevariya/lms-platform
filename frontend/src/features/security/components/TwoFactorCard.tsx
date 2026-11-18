import { ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Alert } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { useRefreshSession } from '@/features/auth/hooks'
import type { TwoFactorSetup } from '@/types/api'
import { securityApi, useSecurityMutation, useTwoFactorStatus } from '../api'

function RecoveryCodes({ codes }: { codes: string[] }) {
  return (
    <div className="grid gap-2">
      <p className="text-sm font-medium">Recovery codes — store them somewhere safe. Each works once.</p>
      <ul className="bg-muted grid grid-cols-2 gap-1 rounded-md p-3 font-mono text-sm">
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
    </div>
  )
}

export function TwoFactorCard() {
  const { data: status } = useTwoFactorStatus()
  const refresh = useRefreshSession()
  const keys = [['me', 'two-factor']]
  const enable = useSecurityMutation(securityApi.enableTwoFactor, keys)
  const confirm = useSecurityMutation(securityApi.confirmTwoFactor, keys)
  const disable = useSecurityMutation(securityApi.disableTwoFactor, keys)
  const regen = useSecurityMutation(securityApi.regenerateCodes, keys)
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)

  if (!status?.available) return null

  const passwordField = (id: string, error?: string) => (
    <FormField id={id} label="Current password" error={error}>
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
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <ShieldCheck className="text-accent size-5" aria-hidden /> Two-factor authentication
          {status.enabled && <Badge>On</Badge>}
        </CardTitle>
        <CardDescription>Protect your admin account with a code from an authenticator app.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {status.enabled ? (
          <>
            <p className="text-muted-foreground text-sm">{status.recovery_codes_left} recovery codes left.</p>
            {codes && <RecoveryCodes codes={codes} />}
            {passwordField('tfa-password', disable.error?.message ?? regen.error?.message)}
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                disabled={!password}
                loading={regen.isPending}
                onClick={() => regen.mutate(password, { onSuccess: (r) => setCodes(r.recovery_codes) })}
              >
                New recovery codes
              </Button>
              <Button
                variant="destructive"
                disabled={!password}
                loading={disable.isPending}
                onClick={() =>
                  disable.mutate(password, {
                    onSuccess: () => {
                      toast.success('Two-factor authentication disabled')
                      setPassword('')
                      setCodes(null)
                      void refresh()
                    },
                  })
                }
              >
                Disable
              </Button>
            </div>
          </>
        ) : setup ? (
          <form
            className="grid gap-4"
            onSubmit={(e) => {
              e.preventDefault()
              confirm.mutate(code, {
                onSuccess: () => {
                  toast.success('Two-factor authentication enabled')
                  setCodes(setup.recovery_codes)
                  setSetup(null)
                  setCode('')
                  void refresh()
                },
              })
            }}
          >
            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
              <li>Scan this QR code with Google Authenticator, 1Password, Authy…</li>
              <li>Enter the 6-digit code it shows.</li>
            </ol>
            <div
              className="w-48 rounded-lg bg-white p-2"
              role="img"
              aria-label="QR code for your authenticator app"
              dangerouslySetInnerHTML={{ __html: setup.qr_svg }}
            />
            <p className="text-muted-foreground text-xs">
              Can't scan? Enter this key: <code className="text-foreground break-all">{setup.secret}</code>
            </p>
            <RecoveryCodes codes={setup.recovery_codes} />
            <FormField id="tfa-code" label="Authentication code" error={confirm.error?.message}>
              {(a) => (
                <Input
                  {...a}
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                />
              )}
            </FormField>
            <Button
              type="submit"
              loading={confirm.isPending}
              disabled={code.length < 6}
              className="justify-self-start"
            >
              Confirm & enable
            </Button>
          </form>
        ) : (
          <>
            {codes && (
              <Alert variant="success">
                <RecoveryCodes codes={codes} />
              </Alert>
            )}
            {passwordField('tfa-enable-password', enable.error?.message)}
            <Button
              className="justify-self-start"
              disabled={!password}
              loading={enable.isPending}
              onClick={() =>
                enable.mutate(password, {
                  onSuccess: (s) => {
                    setSetup(s)
                    setPassword('')
                  },
                })
              }
            >
              Set up two-factor
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}
