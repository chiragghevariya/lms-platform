import { Download } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { http } from '@/lib/api-client'

/**
 * Downloads `GET {path}?…filters&format=csv|xlsx` with the session cookie (axios → blob),
 * so exports respect the same filters as the table on screen.
 */
export function ExportButton({
  path,
  params,
  label = 'Export',
}: {
  path: string
  params?: Record<string, unknown>
  label?: string
}) {
  const [busy, setBusy] = useState(false)

  async function run(format: 'csv' | 'xlsx') {
    setBusy(true)
    try {
      const res = await http.get<Blob>(path, { params: { ...params, format }, responseType: 'blob' })
      const disposition = String(res.headers['content-disposition'] ?? '')
      const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `export.${format}`
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast.error('Export failed. Try narrowing the filters.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" disabled={busy}>
          <Download aria-hidden /> {busy ? 'Exporting…' : label}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => void run('csv')}>CSV (.csv)</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => void run('xlsx')}>Excel (.xlsx)</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
