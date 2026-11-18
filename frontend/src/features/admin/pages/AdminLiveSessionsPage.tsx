import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { DataTable } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { formatSessionTime } from '@/features/live/api'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatDate } from '@/lib/format'
import type { InstructorLiveSession } from '@/types/live'
import { ReasonDialog } from '../catalog/ReasonDialog'
import { Tabs } from '../catalog/Tabs'
import { catalogAdminApi } from '../catalog-api'

type Scope = 'upcoming' | 'past'

const STATUS_LABEL: Record<string, string> = {
  scheduled: 'Scheduled',
  live: 'Live now',
  ended: 'Ended',
  cancelled: 'Cancelled',
}

export default function AdminLiveSessionsPage() {
  const qc = useQueryClient()
  const [scope, setScope] = useState<Scope>('upcoming')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'live', scope, page],
    queryFn: () => catalogAdminApi.liveSessions(scope, page),
    placeholderData: keepPreviousData,
  })
  const [cancelling, setCancelling] = useState<InstructorLiveSession | null>(null)
  const [following, setFollowing] = useState(false)
  const [open, setOpen] = useState<InstructorLiveSession | null>(null)

  const columns: ColumnDef<InstructorLiveSession, unknown>[] = [
    {
      id: 'session',
      header: 'Session',
      enableHiding: false,
      cell: ({ row: { original: s } }) => (
        <div className="grid gap-1">
          <p className="font-medium">{s.title}</p>
          <div className="flex flex-wrap gap-1">
            <Badge variant="secondary">{s.type_label}</Badge>
            {s.is_series && <Badge variant="outline">Series</Badge>}
            {s.course && <span className="text-muted-foreground text-xs">{s.course.title}</span>}
          </div>
        </div>
      ),
    },
    {
      id: 'when',
      header: 'When',
      cell: ({ row }) => (
        <span className="whitespace-nowrap">{formatSessionTime(row.original.starts_at)}</span>
      ),
    },
    { id: 'instructor', header: 'Instructor', cell: ({ row }) => row.original.instructor.name },
    { id: 'tier', header: 'Tier', cell: ({ row }) => <TierBadge tier={row.original.required_tier} /> },
    {
      id: 'attendance',
      header: 'Registered / attended',
      cell: ({ row: { original: s } }) => (
        <span className="tabular-nums">
          {s.registered_count}
          {s.capacity ? ` / ${s.capacity} seats` : ''}
          {s.attended_count !== null && ` · ${s.attended_count} attended`}
        </span>
      ),
    },
    {
      id: 'status',
      header: 'Status',
      cell: ({ row: { original: s } }) => (
        <div className="grid gap-0.5">
          <Badge
            variant={s.status === 'live' ? 'default' : s.status === 'cancelled' ? 'outline' : 'secondary'}
          >
            {STATUS_LABEL[s.status] ?? s.status}
          </Badge>
          {s.recording_status && (
            <span className="text-muted-foreground text-xs">Recording: {s.recording_status}</span>
          )}
        </div>
      ),
    },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      cell: ({ row: { original: s } }) => (
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setOpen(s)}>
            Registrations
          </Button>
          {s.status === 'scheduled' && (
            <Button size="sm" variant="outline" onClick={() => setCancelling(s)}>
              Cancel
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <Seo title="Live sessions" noindex />
      <PageHeader
        title="Live sessions"
        description="Every instructor's classes and webinars. Cancelling notifies everyone registered."
      />
      <Tabs
        label="Sessions"
        idPrefix="live"
        value={scope}
        onChange={(v) => {
          setScope(v)
          setPage(1)
        }}
        tabs={[
          { value: 'upcoming', label: 'Upcoming' },
          { value: 'past', label: 'Past & cancelled' },
        ]}
      />
      <div role="tabpanel" id="live-panel" aria-labelledby={`live-tab-${scope}`}>
        <DataTable
          label={scope === 'upcoming' ? 'Upcoming live sessions' : 'Past live sessions'}
          columns={columns}
          data={data?.data}
          meta={data?.meta}
          loading={isLoading}
          getRowId={(s) => s.id}
          onPageChange={setPage}
          storageKey="admin-live"
          empty={scope === 'upcoming' ? 'Nothing scheduled.' : 'No past sessions yet.'}
        />
      </div>

      <ReasonDialog
        open={!!cancelling}
        onOpenChange={(o) => {
          if (!o) {
            setCancelling(null)
            setFollowing(false)
          }
        }}
        title={`Cancel “${cancelling?.title ?? ''}”?`}
        description="Registered learners are told the reason by email and in-app."
        confirmLabel="Cancel session"
        destructive
        required={false}
        extra={
          cancelling?.is_series ? (
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={following} onChange={(e) => setFollowing(e.target.checked)} />
              Also cancel the following sessions in this series
            </label>
          ) : null
        }
        onConfirm={async (reason) => {
          const res = await catalogAdminApi.cancelLive(cancelling!.id, { reason, following })
          toast.success(res.cancelled > 1 ? `${res.cancelled} sessions cancelled` : 'Session cancelled')
          await qc.invalidateQueries({ queryKey: ['admin', 'live'] })
        }}
      />

      <Sheet open={!!open} onOpenChange={(o) => !o && setOpen(null)}>
        <SheetContent>{open && <RegistrationsPanel session={open} />}</SheetContent>
      </Sheet>
    </>
  )
}

function RegistrationsPanel({ session }: { session: InstructorLiveSession }) {
  const { data, isLoading, refetch } = useQuery({
    queryKey: ['admin', 'live', 'registrations', session.id],
    queryFn: () => catalogAdminApi.liveRegistrations(session.id),
  })
  const sync = useMutation({
    mutationFn: () => catalogAdminApi.syncAttendance(session.id),
    onSuccess: () => {
      toast.success('Attendance sync queued; refresh in a minute.')
      setTimeout(() => void refetch(), 5000)
    },
    onError: (e) => toast.error(e instanceof Error ? e.message : 'Could not sync attendance.'),
  })
  const canSync = session.provider === 'zoom' && session.status === 'ended'

  return (
    <>
      <SheetHeader>
        <SheetTitle>{session.title}</SheetTitle>
        <SheetDescription>
          {formatSessionTime(session.starts_at)} · {session.instructor.name}
        </SheetDescription>
      </SheetHeader>
      <SheetBody>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-muted-foreground text-sm">
            {data?.meta.attendance_synced_at
              ? `Attendance synced ${formatDate(data.meta.attendance_synced_at)}`
              : 'Attendance not synced yet'}
          </p>
          {canSync && (
            <Button size="sm" variant="outline" disabled={sync.isPending} onClick={() => sync.mutate()}>
              <RefreshCw aria-hidden /> Sync attendance
            </Button>
          )}
        </div>
        {isLoading ? (
          <Skeleton className="h-40" />
        ) : !data?.data.length ? (
          <p className="text-muted-foreground text-sm">No one has registered.</p>
        ) : (
          <ul className="divide-y rounded-lg border" aria-label="Registrations">
            {data.data.map((r) => (
              <li key={r.id} className="flex items-center justify-between gap-2 p-3 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium">{r.name}</p>
                  <p className="text-muted-foreground truncate text-xs">{r.email}</p>
                </div>
                {r.attended ? (
                  <Badge>Attended {r.attended_minutes} min</Badge>
                ) : (
                  <Badge variant="outline">{session.status === 'ended' ? 'Absent' : 'Registered'}</Badge>
                )}
              </li>
            ))}
          </ul>
        )}
      </SheetBody>
    </>
  )
}
