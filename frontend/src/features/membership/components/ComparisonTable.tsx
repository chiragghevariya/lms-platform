import { Check, Minus } from 'lucide-react'
import { Card } from '@/components/ui/card'
import type { Plan } from '@/types/api'

/** Feature-by-tier matrix built from each plan's `features` [{key,label,value}]. */
export function ComparisonTable({ plans }: { plans: Plan[] }) {
  const rows = new Map<string, string>()
  plans.forEach((p) => p.features.forEach((f) => rows.set(f.key, f.label)))
  if (!rows.size) return null

  return (
    <Card className="overflow-x-auto">
      <table className="w-full min-w-[32rem] text-left text-sm">
        <caption className="sr-only">Compare membership tiers</caption>
        <thead>
          <tr className="border-b">
            <th scope="col" className="p-4 font-medium">
              <span className="sr-only">Feature</span>
            </th>
            {plans.map((p) => (
              <th key={p.id} scope="col" className="p-4 text-center font-semibold">
                {p.name}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {[...rows].map(([key, label]) => (
            <tr key={key}>
              <th scope="row" className="text-muted-foreground p-4 font-normal">
                {label}
              </th>
              {plans.map((p) => {
                const value = p.features.find((f) => f.key === key)?.value
                return (
                  <td key={p.id} className="p-4 text-center">
                    {value === true ? (
                      <>
                        <Check className="text-accent mx-auto size-5" aria-hidden />
                        <span className="sr-only">Included</span>
                      </>
                    ) : value === false || value === undefined ? (
                      <>
                        <Minus className="text-muted-foreground mx-auto size-5" aria-hidden />
                        <span className="sr-only">Not included</span>
                      </>
                    ) : (
                      <span className="font-medium">{value}</span>
                    )}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  )
}
