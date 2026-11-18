import { CheckCircle2, Smartphone } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { homePathFor } from '@/lib/membership'
import { useAuthStore } from '@/stores/auth-store'
import { useLoginActivity } from '../api'
import { SessionsCard } from '../components/SessionsCard'
import { TwoFactorCard } from '../components/TwoFactorCard'
import { YourDataCard } from '@/features/privacy/YourDataCard'

const EVENT_LABELS: Record<string, string> = {
  login_success: 'Signed in',
  login_failed: 'Failed sign-in',
  locked_out: 'Account locked',
  blocked_status: 'Blocked (suspended)',
  two_factor_challenged: '2FA requested',
  two_factor_failed: 'Wrong 2FA code',
  logout: 'Signed out',
  logout_other_devices: 'Signed out other devices',
  password_reset: 'Password reset',
  password_changed: 'Password changed',
}

export default function SecurityPage() {
  const user = useAuthStore((s) => s.user)!
  const activity = useLoginActivity()

  return (
    <>
      <Seo title="Security" noindex />
      <PageHeader title="Security" description="Sessions, verification and sign-in history." />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Smartphone className="size-5" aria-hidden /> Phone verification
            </CardTitle>
            <CardDescription>{user.phone ?? 'No phone number on file.'}</CardDescription>
          </CardHeader>
          <CardContent>
            {user.phone_verified ? (
              <Badge variant="secondary">
                <CheckCircle2 className="mr-1 size-3" aria-hidden /> Verified
              </Badge>
            ) : (
              <Button asChild variant="outline">
                <Link to={user.phone ? '/verify-phone' : `${homePathFor(user)}/profile`}>
                  {user.phone ? 'Verify with OTP' : 'Add phone number'}
                </Link>
              </Button>
            )}
          </CardContent>
        </Card>
        <TwoFactorCard />
        <div className="lg:col-span-2">
          <SessionsCard />
        </div>
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Recent sign-in activity</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            {activity.isLoading ? (
              <Skeleton className="h-32" />
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="text-muted-foreground">
                  <tr>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      Event
                    </th>
                    <th scope="col" className="py-2 pr-4 font-medium">
                      When
                    </th>
                    <th scope="col" className="hidden py-2 pr-4 font-medium sm:table-cell">
                      IP
                    </th>
                    <th scope="col" className="py-2 font-medium">
                      Via
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {activity.data?.data.map((a, i) => (
                    <tr key={`${a.created_at}-${i}`}>
                      <td className="py-2 pr-4">{EVENT_LABELS[a.event] ?? a.event}</td>
                      <td className="py-2 pr-4">{new Date(a.created_at).toLocaleString()}</td>
                      <td className="hidden py-2 pr-4 sm:table-cell">{a.ip_address}</td>
                      <td className="py-2 capitalize">{a.channel}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
        <YourDataCard />
      </div>
    </>
  )
}
