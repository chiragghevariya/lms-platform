import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type RowSelectionState,
  type VisibilityState,
} from '@tanstack/react-table'
import { Check, ChevronLeft, ChevronRight, Columns3 } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

export interface PageMeta {
  current_page: number
  last_page: number
  total: number
  per_page?: number
}

export interface DataTableProps<T> {
  /** Accessible name of the table ("Users"). */
  label: string
  columns: ColumnDef<T, unknown>[]
  data: T[] | undefined
  meta?: PageMeta
  loading?: boolean
  getRowId: (row: T) => string
  onPageChange?: (page: number) => void
  /** Filters / search, rendered in one row above the table. */
  toolbar?: ReactNode
  /** Extra buttons on the right of the toolbar (e.g. Export). */
  actions?: ReactNode
  /** Enables row checkboxes; receives the selected ids and a reset callback. */
  bulkActions?: (selectedIds: string[], clear: () => void) => ReactNode
  /** Opens a detail drawer etc. Rows become keyboard-activatable. */
  onRowClick?: (row: T) => void
  /** localStorage key for the column-visibility choice. */
  storageKey?: string
  empty?: ReactNode
}

function loadVisibility(key?: string): VisibilityState {
  if (!key) return {}
  try {
    return JSON.parse(localStorage.getItem(`dt:${key}`) ?? '{}') as VisibilityState
  } catch {
    return {}
  }
}

/**
 * Server-side paginated table (TanStack Table, headless) with row selection + bulk actions,
 * column toggles and an optional row click (detail drawer). Filtering and sorting stay in
 * the page's query params so they also drive exports.
 */
export function DataTable<T>({
  label,
  columns,
  data,
  meta,
  loading,
  getRowId,
  onPageChange,
  toolbar,
  actions,
  bulkActions,
  onRowClick,
  storageKey,
  empty = 'Nothing matches these filters.',
}: DataTableProps<T>) {
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({})
  const [visibility, setVisibility] = useState<VisibilityState>(() => loadVisibility(storageKey))

  useEffect(() => {
    if (!storageKey) return
    try {
      localStorage.setItem(`dt:${storageKey}`, JSON.stringify(visibility))
    } catch {
      /* private mode: keep in memory only */
    }
  }, [storageKey, visibility])

  const allColumns = useMemo<ColumnDef<T, unknown>[]>(() => {
    if (!bulkActions) return columns
    return [
      {
        id: '_select',
        enableHiding: false,
        header: ({ table }) => (
          <input
            type="checkbox"
            aria-label="Select all rows on this page"
            className="size-4 align-middle"
            checked={table.getIsAllPageRowsSelected()}
            ref={(el) => {
              if (el) el.indeterminate = table.getIsSomePageRowsSelected()
            }}
            onChange={table.getToggleAllPageRowsSelectedHandler()}
          />
        ),
        cell: ({ row }) => (
          <input
            type="checkbox"
            aria-label="Select row"
            className="size-4 align-middle"
            checked={row.getIsSelected()}
            onClick={(e) => e.stopPropagation()}
            onChange={row.getToggleSelectedHandler()}
          />
        ),
      },
      ...columns,
    ]
  }, [bulkActions, columns])

  // TanStack Table's API isn't compiler-memoisable; the compiler skips this component, which is intended.
  // eslint-disable-next-line react-hooks/incompatible-library
  const table = useReactTable({
    data: data ?? [],
    columns: allColumns,
    getRowId,
    state: { rowSelection, columnVisibility: visibility },
    onRowSelectionChange: setRowSelection,
    onColumnVisibilityChange: setVisibility,
    enableRowSelection: !!bulkActions,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    pageCount: meta?.last_page ?? 1,
  })

  const selected = Object.keys(rowSelection).filter((k) => rowSelection[k])
  const clear = () => setRowSelection({})
  const hideable = table.getAllLeafColumns().filter((c) => c.getCanHide())
  const page = meta?.current_page ?? 1
  const goTo = (p: number) => {
    clear()
    onPageChange?.(p)
  }

  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-center gap-2">
        {toolbar}
        <div className="ml-auto flex flex-wrap items-center gap-2">
          {actions}
          {hideable.length > 0 && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                  <Columns3 aria-hidden /> Columns
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>Show columns</DropdownMenuLabel>
                {hideable.map((c) => (
                  <DropdownMenuItem
                    key={c.id}
                    onSelect={(e) => {
                      e.preventDefault()
                      c.toggleVisibility()
                    }}
                  >
                    <Check className={cn('size-4', !c.getIsVisible() && 'invisible')} aria-hidden />
                    {typeof c.columnDef.header === 'string' ? c.columnDef.header : c.id}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      </div>

      {bulkActions && selected.length > 0 && (
        <div
          role="region"
          aria-label="Bulk actions"
          className="bg-secondary text-secondary-foreground flex flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-sm"
        >
          <span className="font-medium">{selected.length} selected</span>
          {bulkActions(selected, clear)}
          <Button size="sm" variant="ghost" className="ml-auto" onClick={clear}>
            Clear
          </Button>
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full text-sm" aria-label={label} aria-busy={loading || undefined}>
          <thead className="bg-muted/50 text-muted-foreground text-left">
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id}>
                {hg.headers.map((h) => (
                  <th
                    key={h.id}
                    scope="col"
                    className={cn('p-3 font-medium whitespace-nowrap', h.column.id === '_select' && 'w-10')}
                  >
                    {h.isPlaceholder ? null : flexRender(h.column.columnDef.header, h.getContext())}
                  </th>
                ))}
              </tr>
            ))}
          </thead>
          <tbody className="divide-y">
            {loading && !data
              ? Array.from({ length: 6 }).map((_, i) => (
                  <tr key={i}>
                    <td colSpan={table.getVisibleLeafColumns().length} className="p-3">
                      <Skeleton className="h-5" />
                    </td>
                  </tr>
                ))
              : table.getRowModel().rows.map((row) => (
                  <tr
                    key={row.id}
                    data-state={row.getIsSelected() ? 'selected' : undefined}
                    className={cn(
                      'data-[state=selected]:bg-secondary/50',
                      onRowClick && 'hover:bg-muted/40 focus-visible:bg-muted/40 cursor-pointer outline-none',
                    )}
                    tabIndex={onRowClick ? 0 : undefined}
                    onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                    onKeyDown={
                      onRowClick
                        ? (e) => {
                            if (e.target !== e.currentTarget) return
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault()
                              onRowClick(row.original)
                            }
                          }
                        : undefined
                    }
                  >
                    {row.getVisibleCells().map((cell) => (
                      <td key={cell.id} className="p-3 align-middle">
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                ))}
            {!loading && data?.length === 0 && (
              <tr>
                <td
                  colSpan={table.getVisibleLeafColumns().length}
                  className="text-muted-foreground p-8 text-center"
                >
                  {empty}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {meta && (
        <nav aria-label={`${label} pages`} className="flex items-center justify-between gap-2 text-sm">
          <p className="text-muted-foreground tabular-nums">
            {meta.total.toLocaleString()} {meta.total === 1 ? 'result' : 'results'} · page {page} of{' '}
            {Math.max(1, meta.last_page)}
          </p>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => goTo(page - 1)}>
              <ChevronLeft aria-hidden /> Previous
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={page >= meta.last_page}
              onClick={() => goTo(page + 1)}
            >
              Next <ChevronRight aria-hidden />
            </Button>
          </div>
        </nav>
      )}
    </div>
  )
}

/** Native select styled like the inputs; used in table toolbars. */
export const toolbarSelect = 'border-input bg-background h-9 rounded-md border px-2 text-sm'
