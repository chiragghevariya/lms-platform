import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { useMemo, useState } from 'react'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { http } from '@/lib/api-client'

export interface AuditEntry {
  id: number
  event: string | null
  description: string
  subject: { type: string; id: string | number | null; label: string | null } | null
  causer: { id: string; name: string; email: string } | null
  changes: { attributes?: Record<string, unknown>; old?: Record<string, unknown> }
  properties: Record<string, unknown>
  created_at: string | null
}

interface Page {
  data: AuditEntry[]
  meta: { total: number; current_page: number; last_page: number; per_page: number }
}

const SUBJECTS = [
  'User',
  'Payment',
  'Subscription',
  'Plan',
  'PlanPrice',
  'Coupon',
  'Course',
  'Category',
  'CourseReview',
  'Faq',
  'Testimonial',
  'Banner',
  'Post',
  'Page',
  'CmsSection',
]
const EVENTS = [
  'created',
  'updated',
  'deleted',
  'user.status',
  'user.roles',
  'user.password_reset',
  'impersonation.started',
  'impersonation.stopped',
  'payment.refunded',
  'settings.updated',
  'course.approved',
  'course.rejected',
  'course.featured',
  'course.unpublished',
  'review.hidden',
  'review.restored',
  'review.deleted',
  'export.users',
  'export.payments',
  'export.commissions',
]

const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const show = (v: unknown) =>
  v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v)

export default function AdminAuditLogPage() {
  const [f, setF] = useState({ search: '', event: '', subject_type: '', from: '', to: '', page: 1 })
  const [open, setOpen] = useState<AuditEntry | null>(null)
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'audit', params],
    queryFn: async () => (await http.get<Page>('/admin/audit-log', { params })).data,
    placeholderData: keepPreviousData,
  })
  const set = (patch: Partial<typeof f>) => setF((s) => ({ ...s, page: 1, ...patch }))

  const columns = useMemo<ColumnDef<AuditEntry, unknown>[]>(
    () => [
      {
        id: 'when',
        header: 'When',
        cell: ({ row }) => <span className="whitespace-nowrap">{when(row.original.created_at)}</span>,
      },
      {
        id: 'who',
        header: 'Who',
        cell: ({ row }) =>
          row.original.causer ? (
            <span>
              {row.original.causer.name}
              <span className="text-muted-foreground block text-xs">{row.original.causer.email}</span>
            </span>
          ) : (
            <span className="text-muted-foreground">System</span>
          ),
      },
      {
        id: 'what',
        header: 'What',
        cell: ({ row }) => (
          <span>
            {row.original.description}
            {row.original.event && (
              <Badge variant="secondary" className="ml-2 font-mono text-[11px]">
                {row.original.event}
              </Badge>
            )}
          </span>
        ),
      },
      {
        id: 'subject',
        header: 'Record',
        cell: ({ row }) =>
          row.original.subject ? (
            <span>
              <span className="text-muted-foreground">{row.original.subject.type}</span>{' '}
              {row.original.subject.label ?? `#${row.original.subject.id}`}
            </span>
          ) : (
            '—'
          ),
      },
    ],
    [],
  )

  return (
    <>
      <Seo title="Audit log" noindex />
      <PageHeader
        title="Audit log"
        description="Every admin action and change to admin-managed records, newest first. Secrets are never recorded."
      />
      <DataTable
        label="Audit log"
        storageKey="audit-log"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(r) => String(r.id)}
        onPageChange={(page) => setF((s) => ({ ...s, page }))}
        onRowClick={setOpen}
        toolbar={
          <>
            <Input
              aria-label="Search descriptions"
              placeholder="Search…"
              className="w-48"
              value={f.search}
              onChange={(e) => set({ search: e.target.value })}
            />
            <select
              aria-label="Action"
              className={toolbarSelect}
              value={f.event}
              onChange={(e) => set({ event: e.target.value })}
            >
              <option value="">All actions</option>
              {EVENTS.map((e) => (
                <option key={e}>{e}</option>
              ))}
            </select>
            <select
              aria-label="Record type"
              className={toolbarSelect}
              value={f.subject_type}
              onChange={(e) => set({ subject_type: e.target.value })}
            >
              <option value="">All records</option>
              {SUBJECTS.map((s) => (
                <option key={s}>{s}</option>
              ))}
            </select>
            <Input
              aria-label="From date"
              type="date"
              className="w-40"
              value={f.from}
              onChange={(e) => set({ from: e.target.value })}
            />
            <Input
              aria-label="To date"
              type="date"
              className="w-40"
              value={f.to}
              onChange={(e) => set({ to: e.target.value })}
            />
          </>
        }
      />

      <Sheet open={!!open} onOpenChange={(o) => !o && setOpen(null)}>
        <SheetContent>
          {open && (
            <>
              <SheetHeader>
                <SheetTitle>{open.description}</SheetTitle>
                <SheetDescription>
                  {when(open.created_at)} ·{' '}
                  {open.causer ? `${open.causer.name} (${open.causer.email})` : 'System'}
                </SheetDescription>
              </SheetHeader>
              <SheetBody>
                {open.subject && (
                  <p className="text-sm">
                    <span className="text-muted-foreground">Record:</span> {open.subject.type}{' '}
                    {open.subject.label ?? ''}{' '}
                    <span className="text-muted-foreground font-mono text-xs">
                      {String(open.subject.id ?? '')}
                    </span>
                  </p>
                )}
                {open.changes.attributes && (
                  <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                      <caption className="sr-only">Changed fields</caption>
                      <thead className="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                          <th className="p-2 font-medium">Field</th>
                          <th className="p-2 font-medium">Before</th>
                          <th className="p-2 font-medium">After</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y">
                        {Object.entries(open.changes.attributes).map(([k, v]) => (
                          <tr key={k}>
                            <td className="p-2 font-mono text-xs">{k}</td>
                            <td className="text-muted-foreground p-2 break-all">
                              {show(open.changes.old?.[k])}
                            </td>
                            <td className="p-2 break-all">{show(v)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {Object.keys(open.properties).length > 0 && (
                  <div>
                    <p className="mb-1 text-sm font-medium">Details</p>
                    <pre className="bg-muted overflow-x-auto rounded-lg p-3 text-xs">
                      {JSON.stringify(open.properties, null, 2)}
                    </pre>
                  </div>
                )}
              </SheetBody>
            </>
          )}
        </SheetContent>
      </Sheet>
    </>
  )
}
