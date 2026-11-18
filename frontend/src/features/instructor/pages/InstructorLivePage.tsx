import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, ExternalLink, RefreshCw, Users, XCircle } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { browserTimezone, formatSessionTime, instructorLiveApi } from '@/features/live/api'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { ApiError } from '@/lib/api-client'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { InstructorLiveSession, LiveProvider, LiveType, ScheduleInput } from '@/types/live'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const PROVIDER_LABEL: Record<LiveProvider, string> = {
  zoom: 'Zoom (embedded)',
  external_link: 'External link (Google Meet, …)',
  agora: 'Agora',
  '100ms': '100ms',
}
/** Fields that render their own error under the input. */
const SHOWN = ['title', 'course_id', 'starts_at', 'repeat', 'provider', 'join_url']
const field = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm'

export default function InstructorLivePage() {
  const qc = useQueryClient()
  const [scope, setScope] = useState<'upcoming' | 'past'>('upcoming')
  const [scheduling, setScheduling] = useState(false)
  const [cancelling, setCancelling] = useState<InstructorLiveSession | null>(null)
  const [roster, setRoster] = useState<InstructorLiveSession | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['instructor', 'live', scope],
    queryFn: () => instructorLiveApi.list(scope),
  })

  const start = async (s: InstructorLiveSession) => {
    // Open the tab synchronously (popup blockers), then point it at the fresh host link.
    const tab = window.open('about:blank', '_blank')
    try {
      const { url } = await instructorLiveApi.start(s.id)
      if (tab) tab.location.assign(url)
      else window.location.assign(url)
    } catch (e) {
      tab?.close()
      toast.error(e instanceof Error ? e.message : 'Could not start')
    }
  }

  return (
    <>
      <Seo title="Live classes" noindex />
      <PageHeader
        title="Live classes"
        description="Schedule classes, webinars and mentorship calls. Attendance and recordings sync back automatically."
        actions={
          <Button onClick={() => setScheduling(true)}>
            <CalendarPlus aria-hidden /> Schedule
          </Button>
        }
      />
      <div role="tablist" aria-label="Sessions" className="mb-4 flex gap-1 border-b">
        {(['upcoming', 'past'] as const).map((v) => (
          <button
            key={v}
            role="tab"
            aria-selected={scope === v}
            onClick={() => setScope(v)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium capitalize',
              scope === v ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
            )}
          >
            {v}
          </button>
        ))}
      </div>
      {isLoading && <Skeleton className="h-32" />}
      {data?.data.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            {scope === 'upcoming' ? 'Nothing scheduled yet.' : 'No past sessions.'}
          </CardContent>
        </Card>
      )}
      <ul className="grid gap-3">
        {data?.data.map((s) => (
          <li key={s.id}>
            <Card>
              <CardContent className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold">{s.title}</span>
                    <TierBadge tier={s.required_tier} />
                    <Badge variant="outline">{s.status}</Badge>
                    {s.is_series && <Badge variant="secondary">Weekly series</Badge>}
                  </div>
                  <p className="text-muted-foreground text-sm">
                    {formatSessionTime(s.starts_at, { year: 'numeric' })} · {s.duration} min · {s.type_label}{' '}
                    · {PROVIDER_LABEL[s.provider]}
                  </p>
                  <p className="text-muted-foreground text-sm">
                    {s.registered_count} registered{s.capacity ? ` of ${s.capacity}` : ''}
                    {s.attended_count !== null && ` · ${s.attended_count} attended`}
                    {s.recording_status && ` · recording ${s.recording_status}`}
                    {s.course && ` · ${s.course.title}`}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {(s.status === 'scheduled' || s.status === 'live') && (
                    <Button size="sm" variant="accent" onClick={() => void start(s)}>
                      <ExternalLink aria-hidden /> Start as host
                    </Button>
                  )}
                  <Button size="sm" variant="outline" onClick={() => setRoster(s)}>
                    <Users aria-hidden /> {s.status === 'ended' ? 'Attendance' : 'Registrants'}
                  </Button>
                  {s.status === 'scheduled' && (
                    <Button size="sm" variant="ghost" onClick={() => setCancelling(s)}>
                      <XCircle aria-hidden /> Cancel
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          </li>
        ))}
      </ul>

      {scheduling && (
        <ScheduleDialog
          onClose={() => setScheduling(false)}
          onDone={() => void qc.invalidateQueries({ queryKey: ['instructor', 'live'] })}
        />
      )}
      {cancelling && (
        <CancelDialog
          session={cancelling}
          onClose={() => setCancelling(null)}
          onDone={() => void qc.invalidateQueries({ queryKey: ['instructor', 'live'] })}
        />
      )}
      {roster && <RosterDialog session={roster} onClose={() => setRoster(null)} />}
    </>
  )
}

function ScheduleDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { data: options } = useQuery({
    queryKey: ['instructor', 'live', 'options'],
    queryFn: instructorLiveApi.options,
  })
  const [v, setV] = useState(() => ({
    title: '',
    description: '',
    type: 'class' as LiveType,
    required_tier: '',
    course_id: '',
    date: new Date(Date.now() + 86400000).toLocaleDateString('en-CA'),
    time: '19:00',
    timezone: browserTimezone(),
    duration: 60,
    capacity: '',
    provider: '' as LiveProvider | '',
    join_url: '',
    repeat: false,
    weekdays: [] as number[],
    occurrences: 4,
  }))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const provider = v.provider || options?.providers[0] || 'external_link'
  const set = <K extends keyof typeof v>(k: K, val: (typeof v)[K]) => setV((s) => ({ ...s, [k]: val }))
  const err = (k: string) =>
    errors[k] && (
      <p role="alert" className="text-destructive text-xs">
        {errors[k]}
      </p>
    )

  const submit = async () => {
    setSaving(true)
    setErrors({})
    const body: ScheduleInput = {
      title: v.title,
      description: v.description || null,
      type: v.type,
      required_tier: v.required_tier ? Number(v.required_tier) : null,
      course_id: v.course_id || null,
      starts_at: `${v.date}T${v.time}`,
      timezone: v.timezone,
      duration: Number(v.duration),
      capacity: v.capacity ? Number(v.capacity) : null,
      provider,
      join_url: provider === 'external_link' ? v.join_url : null,
      repeat: v.repeat
        ? {
            weekdays: v.weekdays.length ? v.weekdays : [isoWeekday(v.date)],
            occurrences: Number(v.occurrences),
          }
        : null,
    }
    try {
      const created = await instructorLiveApi.create(body)
      toast.success(created.length > 1 ? `${created.length} sessions scheduled` : 'Session scheduled')
      onDone()
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        setErrors(
          Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k.split('.')[0]!, m[0] ?? ''])),
        )
      } else toast.error(e instanceof Error ? e.message : 'Could not schedule')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Schedule a live session</DialogTitle>
          <DialogDescription>
            Learners in the chosen tier can register; they get an email invite and reminders.
          </DialogDescription>
        </DialogHeader>
        <form
          id="schedule-form"
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            void submit()
          }}
        >
          <div className="grid gap-1">
            <label htmlFor="ls-title" className="text-sm font-medium">
              Title
            </label>
            <Input
              id="ls-title"
              required
              maxLength={200}
              value={v.title}
              onChange={(e) => set('title', e.target.value)}
              aria-invalid={!!errors.title}
            />
            {err('title')}
          </div>
          <div className="grid gap-1">
            <label htmlFor="ls-desc" className="text-sm font-medium">
              Description
            </label>
            <textarea
              id="ls-desc"
              rows={3}
              maxLength={5000}
              value={v.description}
              onChange={(e) => set('description', e.target.value)}
              className="border-input bg-background rounded-md border px-3 py-2 text-sm"
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-1">
              <label htmlFor="ls-type" className="text-sm font-medium">
                Type
              </label>
              <select
                id="ls-type"
                className={field}
                value={v.type}
                onChange={(e) => set('type', e.target.value as LiveType)}
              >
                {options?.types.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="grid gap-1">
              <label htmlFor="ls-tier" className="text-sm font-medium">
                Who can join
              </label>
              <select
                id="ls-tier"
                className={field}
                value={v.required_tier}
                onChange={(e) => set('required_tier', e.target.value)}
              >
                <option value="">{v.course_id ? 'Same as the course' : 'All members'}</option>
                <option value="1">Starter and above</option>
                <option value="2">Pro and above</option>
                <option value="3">Elite only</option>
              </select>
            </div>
            <div className="grid gap-1">
              <label htmlFor="ls-course" className="text-sm font-medium">
                Course (optional)
              </label>
              <select
                id="ls-course"
                className={field}
                value={v.course_id}
                onChange={(e) => set('course_id', e.target.value)}
                aria-invalid={!!errors.course_id}
              >
                <option value="">None</option>
                {options?.courses.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.title}
                  </option>
                ))}
              </select>
              {err('course_id')}
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-4">
            <div className="grid gap-1">
              <label htmlFor="ls-date" className="text-sm font-medium">
                Date
              </label>
              <Input
                id="ls-date"
                type="date"
                required
                value={v.date}
                onChange={(e) => set('date', e.target.value)}
                aria-invalid={!!errors.starts_at}
              />
            </div>
            <div className="grid gap-1">
              <label htmlFor="ls-time" className="text-sm font-medium">
                Start time
              </label>
              <Input
                id="ls-time"
                type="time"
                required
                value={v.time}
                onChange={(e) => set('time', e.target.value)}
              />
            </div>
            <div className="grid gap-1">
              <label htmlFor="ls-dur" className="text-sm font-medium">
                Duration (min)
              </label>
              <Input
                id="ls-dur"
                type="number"
                min={15}
                max={480}
                step={15}
                value={v.duration}
                onChange={(e) => set('duration', Number(e.target.value))}
              />
            </div>
            <div className="grid gap-1">
              <label htmlFor="ls-cap" className="text-sm font-medium">
                Capacity
              </label>
              <Input
                id="ls-cap"
                type="number"
                min={1}
                placeholder={v.type === 'mentorship_1on1' ? '1' : 'Unlimited'}
                disabled={v.type === 'mentorship_1on1'}
                value={v.capacity}
                onChange={(e) => set('capacity', e.target.value)}
              />
            </div>
          </div>
          {err('starts_at')}
          <div className="grid gap-1">
            <label htmlFor="ls-tz" className="text-sm font-medium">
              Timezone
            </label>
            <select
              id="ls-tz"
              className={field}
              value={v.timezone}
              onChange={(e) => set('timezone', e.target.value)}
            >
              {timezones().map((tz) => (
                <option key={tz} value={tz}>
                  {tz}
                </option>
              ))}
            </select>
            <p className="text-muted-foreground text-xs">
              Learners see the time converted to their own timezone.
            </p>
          </div>

          <fieldset className="grid gap-2 rounded-md border p-3">
            <legend className="px-1 text-sm font-medium">Repeat</legend>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={v.repeat} onChange={(e) => set('repeat', e.target.checked)} />{' '}
              Weekly series
            </label>
            {v.repeat && (
              <div className="flex flex-wrap items-center gap-3">
                <div className="flex gap-1" role="group" aria-label="Days of the week">
                  {WEEKDAYS.map((d, i) => {
                    const on =
                      v.weekdays.includes(i + 1) || (!v.weekdays.length && isoWeekday(v.date) === i + 1)
                    return (
                      <button
                        key={d}
                        type="button"
                        aria-pressed={on}
                        onClick={() =>
                          set(
                            'weekdays',
                            on
                              ? v.weekdays.filter((x) => x !== i + 1)
                              : [...new Set([...v.weekdays, isoWeekday(v.date), i + 1])],
                          )
                        }
                        className={cn(
                          'rounded-md border px-2 py-1 text-xs',
                          on && 'bg-primary text-primary-foreground border-primary',
                        )}
                      >
                        {d}
                      </button>
                    )
                  })}
                </div>
                <label htmlFor="ls-occ" className="text-sm">
                  Sessions
                </label>
                <Input
                  id="ls-occ"
                  type="number"
                  min={2}
                  max={52}
                  className="h-8 w-20"
                  value={v.occurrences}
                  onChange={(e) => set('occurrences', Number(e.target.value))}
                />
              </div>
            )}
            {err('repeat')}
          </fieldset>

          <div className="grid gap-1">
            <label htmlFor="ls-provider" className="text-sm font-medium">
              Meeting
            </label>
            <select
              id="ls-provider"
              className={field}
              value={provider}
              onChange={(e) => set('provider', e.target.value as LiveProvider)}
            >
              {options?.providers.map((p) => (
                <option key={p} value={p}>
                  {PROVIDER_LABEL[p]}
                </option>
              ))}
            </select>
            {options && !options.providers.includes('zoom') && (
              <p className="text-muted-foreground text-xs">
                Zoom isn't connected yet — ask an admin to add the Zoom keys.
              </p>
            )}
            {err('provider')}
          </div>
          {provider === 'external_link' && (
            <div className="grid gap-1">
              <label htmlFor="ls-url" className="text-sm font-medium">
                Meeting link
              </label>
              <Input
                id="ls-url"
                type="url"
                placeholder="https://meet.google.com/…"
                value={v.join_url}
                onChange={(e) => set('join_url', e.target.value)}
                aria-invalid={!!errors.join_url}
              />
              {err('join_url')}
            </div>
          )}
          {/* Anything not shown next to a field (timezone, duration, …) still surfaces here. */}
          {Object.entries(errors).some(([k]) => !SHOWN.includes(k)) && (
            <Alert variant="destructive">
              {Object.entries(errors)
                .filter(([k]) => !SHOWN.includes(k))
                .map(([k, m]) => (
                  <p key={k}>{m}</p>
                ))}
            </Alert>
          )}
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button type="submit" form="schedule-form" loading={saving}>
            Schedule
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function CancelDialog({
  session,
  onClose,
  onDone,
}: {
  session: InstructorLiveSession
  onClose: () => void
  onDone: () => void
}) {
  const [reason, setReason] = useState('')
  const [following, setFollowing] = useState(false)
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cancel “{session.title}”?</DialogTitle>
          <DialogDescription>
            {session.registered_count} registered learner(s) will be emailed.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-3">
          <label htmlFor="cancel-reason" className="text-sm font-medium">
            Reason (shared with learners)
          </label>
          <Input
            id="cancel-reason"
            maxLength={500}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          {session.is_series && (
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={following} onChange={(e) => setFollowing(e.target.checked)} />{' '}
              Also cancel the following sessions in this series
            </label>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Keep it
          </Button>
          <Button
            variant="destructive"
            loading={busy}
            onClick={async () => {
              setBusy(true)
              try {
                const { cancelled } = await instructorLiveApi.cancel(session.id, { reason, following })
                toast.success(`${cancelled} session${cancelled === 1 ? '' : 's'} cancelled`)
                onDone()
                onClose()
              } catch (e) {
                toast.error(e instanceof Error ? e.message : 'Could not cancel')
              } finally {
                setBusy(false)
              }
            }}
          >
            Cancel session
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function RosterDialog({ session, onClose }: { session: InstructorLiveSession; onClose: () => void }) {
  const { data, refetch, isFetching } = useQuery({
    queryKey: ['instructor', 'live', 'roster', session.id],
    queryFn: () => instructorLiveApi.registrations(session.id),
  })
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {session.status === 'ended' ? 'Attendance' : 'Registrants'}: {session.title}
          </DialogTitle>
          <DialogDescription>
            {session.status === 'ended'
              ? data?.meta.attendance_synced_at
                ? `Synced from ${session.provider === 'zoom' ? 'Zoom' : 'join clicks'} on ${formatDate(data.meta.attendance_synced_at)}.`
                : session.provider === 'zoom'
                  ? 'Waiting for Zoom’s participant report (usually a few minutes after the class ends).'
                  : 'Attendance = learners who clicked Join.'
              : 'People registered so far.'}
          </DialogDescription>
        </DialogHeader>
        {session.status === 'ended' && session.provider === 'zoom' && (
          <Button
            size="sm"
            variant="outline"
            className="justify-self-start"
            loading={isFetching}
            onClick={() =>
              instructorLiveApi.syncAttendance(session.id).then(
                () => {
                  toast.success('Sync queued')
                  setTimeout(() => void refetch(), 3000)
                },
                () => toast.error('Could not queue sync'),
              )
            }
          >
            <RefreshCw aria-hidden /> Sync attendance now
          </Button>
        )}
        <table className="w-full text-sm">
          <thead className="text-muted-foreground text-left">
            <tr>
              <th className="py-2 font-medium">Name</th>
              <th className="py-2 font-medium">Email</th>
              {session.status === 'ended' && <th className="py-2 font-medium">Attended</th>}
            </tr>
          </thead>
          <tbody className="divide-y">
            {data?.data.map((r) => (
              <tr key={r.id}>
                <td className="py-2">{r.name}</td>
                <td className="text-muted-foreground py-2">{r.email}</td>
                {session.status === 'ended' && (
                  <td className="py-2">
                    {r.attended
                      ? `Yes · ${r.attended_minutes} min`
                      : r.attended_minutes
                        ? `${r.attended_minutes} min (under threshold)`
                        : 'No'}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        {data?.data.length === 0 && <p className="text-muted-foreground text-sm">No one yet.</p>}
      </DialogContent>
    </Dialog>
  )
}

/** ISO weekday (1 = Mon) of a YYYY-MM-DD date. */
function isoWeekday(date: string) {
  const d = new Date(`${date}T12:00:00`)
  return ((d.getDay() + 6) % 7) + 1
}

function timezones(): string[] {
  const list =
    (Intl as unknown as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf?.('timeZone') ?? []
  const own = browserTimezone()
  return [...new Set([own, 'Asia/Kolkata', 'UTC', ...list])]
}
