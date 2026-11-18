import { useQuery } from '@tanstack/react-query'
import { Gift } from 'lucide-react'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, formatMinor } from '@/lib/format'
import { affiliateApi } from '../api'
import { ShareLink } from '../components/ShareLink'

const STATUS: Record<string, string> = {
  invited: 'Signed up',
  pending: 'Subscribed · reward on hold',
  banked: 'Banked',
  granted: 'Reward added',
  reversed: 'Refunded',
  rejected: 'Not eligible',
}

export default function InvitePage() {
  const { data, isLoading } = useQuery({ queryKey: ['me', 'invite'], queryFn: affiliateApi.invite })
  const reward = data
    ? data.reward.type === 'days'
      ? `${data.reward.days} free days`
      : Object.entries(data.reward.credit)
          .map(([c, v]) => formatMinor(v, c))
          .join(' / ') + ' credit'
    : ''

  return (
    <>
      <Seo title="Invite friends" noindex />
      <PageHeader
        title="Invite friends"
        description={data ? `Get ${reward} for every friend who becomes a paying member.` : undefined}
      />
      {isLoading || !data ? (
        <Skeleton className="h-64" />
      ) : !data.enabled ? (
        <p className="text-muted-foreground">Friend invites are paused right now.</p>
      ) : (
        <div className="grid gap-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Gift className="text-accent size-5" aria-hidden /> Your invite link
              </CardTitle>
              <CardDescription>
                Rewards are added {data.reward.hold_days} days after your friend's first payment. If you're
                already paying, free days are banked and kick in when your plan ends.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <ShareLink
                url={data.link}
                text="I'm learning money-making skills here — join with my invite:"
                label="Invite link"
              />
            </CardContent>
          </Card>
          <div className="grid gap-4 sm:grid-cols-4">
            {[
              ['Friends invited', data.stats.invited],
              ['Became members', data.stats.subscribed],
              ['Free days earned', data.stats.days_earned],
              ['Days banked', data.stats.days_banked],
            ].map(([l, v]) => (
              <Card key={l as string}>
                <CardContent className="p-5">
                  <p className="text-muted-foreground text-sm">{l}</p>
                  <p className="text-2xl font-bold tabular-nums">{v}</p>
                </CardContent>
              </Card>
            ))}
          </div>
          {data.rewards.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Your invites</CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="divide-y text-sm">
                  {data.rewards.map((r) => (
                    <li key={r.id} className="flex items-center justify-between gap-2 py-2">
                      <span>
                        {r.friend}
                        <span className="text-muted-foreground block text-xs">
                          joined {formatDate(r.created_at)}
                        </span>
                      </span>
                      <span className="text-right">
                        <Badge variant={r.status === 'granted' ? 'default' : 'secondary'}>
                          {STATUS[r.status] ?? r.status}
                        </Badge>
                        {r.status === 'pending' && r.available_at && (
                          <span className="text-muted-foreground block text-xs">
                            from {formatDate(r.available_at)}
                          </span>
                        )}
                      </span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          )}
        </div>
      )}
    </>
  )
}
