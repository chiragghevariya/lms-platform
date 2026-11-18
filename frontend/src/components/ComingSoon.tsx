import { Hammer } from 'lucide-react'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { PageHeader } from './PageHeader'

/** Honest placeholder for screens scheduled in a later phase. */
export function ComingSoon({ title, phase }: { title: string; phase: string }) {
  return (
    <>
      <PageHeader title={title} />
      <Card className="border-dashed">
        <CardHeader className="items-center py-12 text-center">
          <Hammer className="text-muted-foreground size-8" aria-hidden />
          <CardTitle>Coming in {phase}</CardTitle>
          <CardDescription>
            This area is scaffolded and routed; its features land in a later phase.
          </CardDescription>
        </CardHeader>
      </Card>
    </>
  )
}
