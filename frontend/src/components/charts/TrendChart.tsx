import { useId, useState } from 'react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Button } from '@/components/ui/button'

export interface TrendPoint {
  label: string
  value: number
  /** Rendered as a lighter, labelled mark (e.g. "this month so far"). */
  estimate?: boolean
}

/**
 * One series over time (never two y-axes: a second measure gets its own chart).
 * Marks follow the dataviz spec: ≤24px bars with a 4px rounded data end, 2px line with a 10% wash,
 * hairline solid grid, hover tooltip, and a table view so values are never colour/hover-gated.
 */
export function TrendChart({
  title,
  description,
  data,
  kind = 'bar',
  format = (v: number) => v.toLocaleString(),
  axisFormat = compact,
  height = 220,
}: {
  title: string
  description?: string
  data: TrendPoint[]
  kind?: 'bar' | 'area'
  format?: (v: number) => string
  /** Short labels for the value axis (defaults to compact numbers). */
  axisFormat?: (v: number) => string
  height?: number
}) {
  const [table, setTable] = useState(false)
  const id = useId()
  const total = data.reduce((s, p) => s + p.value, 0)
  const axis = { stroke: 'var(--muted-foreground)', fontSize: 12, tickLine: false, axisLine: false } as const

  return (
    <figure className="bg-card grid gap-3 rounded-xl border p-4" aria-labelledby={`${id}-t`}>
      <div className="flex items-start justify-between gap-2">
        <figcaption>
          <p id={`${id}-t`} className="font-semibold">
            {title}
          </p>
          {description && <p className="text-muted-foreground text-sm">{description}</p>}
        </figcaption>
        <Button size="sm" variant="ghost" aria-pressed={table} onClick={() => setTable((t) => !t)}>
          {table ? 'Chart' : 'Table'}
        </Button>
      </div>
      {table ? (
        <div className="max-h-64 overflow-auto">
          <table className="w-full text-sm">
            <thead className="text-muted-foreground text-left">
              <tr>
                <th className="py-1 font-medium">Period</th>
                <th className="py-1 text-right font-medium">Value</th>
              </tr>
            </thead>
            <tbody className="divide-y tabular-nums">
              {data.map((p) => (
                <tr key={p.label}>
                  <td className="py-1">
                    {p.label}
                    {p.estimate && ' (so far)'}
                  </td>
                  <td className="py-1 text-right">{format(p.value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : total === 0 ? (
        <p className="text-muted-foreground grid place-items-center text-sm" style={{ height }}>
          No data in this period yet.
        </p>
      ) : (
        <div style={{ height }} aria-hidden>
          <ResponsiveContainer width="100%" height="100%">
            {kind === 'bar' ? (
              <BarChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: -8 }} barCategoryGap="20%">
                <CartesianGrid vertical={false} stroke="var(--chart-grid)" strokeWidth={1} />
                <XAxis dataKey="label" {...axis} interval="preserveStartEnd" minTickGap={16} />
                <YAxis
                  {...axis}
                  allowDecimals={false}
                  tickFormatter={(v: number) => axisFormat(v)}
                  width={48}
                />
                <Tooltip content={<Tip format={format} />} cursor={{ fill: 'var(--muted)', opacity: 0.6 }} />
                <Bar dataKey="value" maxBarSize={24} radius={[4, 4, 0, 0]} isAnimationActive={false}>
                  {data.map((p) => (
                    <Cell key={p.label} fill="var(--chart-1)" fillOpacity={p.estimate ? 0.4 : 1} />
                  ))}
                </Bar>
              </BarChart>
            ) : (
              <AreaChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: -8 }}>
                <CartesianGrid vertical={false} stroke="var(--chart-grid)" strokeWidth={1} />
                <XAxis dataKey="label" {...axis} interval="preserveStartEnd" minTickGap={24} />
                <YAxis
                  {...axis}
                  allowDecimals={false}
                  tickFormatter={(v: number) => axisFormat(v)}
                  width={48}
                />
                <Tooltip
                  content={<Tip format={format} />}
                  cursor={{ stroke: 'var(--muted-foreground)', strokeWidth: 1 }}
                />
                <Area
                  type="monotone"
                  dataKey="value"
                  stroke="var(--chart-1)"
                  strokeWidth={2}
                  fill="var(--chart-1)"
                  fillOpacity={0.1}
                  activeDot={{ r: 4, stroke: 'var(--card)', strokeWidth: 2 }}
                  dot={false}
                  isAnimationActive={false}
                />
              </AreaChart>
            )}
          </ResponsiveContainer>
        </div>
      )}
    </figure>
  )
}

function compact(v: number) {
  return Math.abs(v) >= 10000
    ? new Intl.NumberFormat(undefined, { notation: 'compact' }).format(v)
    : v.toLocaleString()
}

function Tip({
  active,
  payload,
  format,
}: {
  active?: boolean
  payload?: { payload: TrendPoint }[]
  format: (v: number) => string
}) {
  const p = active ? payload?.[0]?.payload : undefined
  if (!p) return null
  return (
    <div className="bg-card text-card-foreground rounded-md border px-3 py-2 text-sm shadow-md">
      <p className="text-muted-foreground text-xs">
        {p.label}
        {p.estimate && ' · so far'}
      </p>
      <p className="font-semibold tabular-nums">{format(p.value)}</p>
    </div>
  )
}
