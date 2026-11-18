import { Download } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, trimMoney } from '@/lib/format'
import type { Payment } from '@/types/api'
import { billingApi, useInvoices } from '../api'

export function InvoicesCard() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useInvoices(page)
  const [downloading, setDownloading] = useState<string | null>(null)

  const download = async (p: Payment) => {
    setDownloading(p.id)
    try {
      await billingApi.downloadInvoice(p)
    } catch {
      toast.error('Could not download the invoice. Please try again.')
    } finally {
      setDownloading(null)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Invoices & payments</CardTitle>
      </CardHeader>
      <CardContent className="overflow-x-auto">
        {isLoading ? (
          <Skeleton className="h-24" />
        ) : !data?.data.length ? (
          <p className="text-muted-foreground text-sm">No payments yet.</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
              <tr>
                <th scope="col" className="py-2 pr-4 font-medium">
                  Date
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  Invoice
                </th>
                <th scope="col" className="hidden py-2 pr-4 font-medium sm:table-cell">
                  Description
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  Amount
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  Status
                </th>
                <th scope="col" className="py-2">
                  <span className="sr-only">Download</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.data.map((p) => (
                <tr key={p.id}>
                  <td className="py-2 pr-4">{formatDate(p.paid_at ?? p.created_at)}</td>
                  <td className="py-2 pr-4 font-mono text-xs">{p.invoice_number ?? '—'}</td>
                  <td className="hidden py-2 pr-4 sm:table-cell">{p.description}</td>
                  <td className="py-2 pr-4">{trimMoney(p.amount.formatted)}</td>
                  <td className="py-2 pr-4">
                    <Badge
                      variant={p.status === 'succeeded' ? 'secondary' : 'outline'}
                      className={p.status === 'failed' ? 'text-destructive' : ''}
                    >
                      {p.status === 'succeeded' ? 'Paid' : p.status}
                    </Badge>
                  </td>
                  <td className="py-2 text-right">
                    {p.has_pdf && (
                      <Button
                        size="sm"
                        variant="ghost"
                        loading={downloading === p.id}
                        onClick={() => download(p)}
                        aria-label={`Download invoice ${p.invoice_number}`}
                      >
                        <Download aria-hidden /> PDF
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {data && data.meta.last_page > 1 && (
          <div className="mt-4 flex justify-end gap-2">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={page >= data.meta.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
