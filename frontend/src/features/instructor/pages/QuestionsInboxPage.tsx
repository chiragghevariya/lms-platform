import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { QuestionThread } from '@/features/learning/components/QuestionThread'
import { cn } from '@/lib/utils'
import { teachingApi } from '../api'

const TABS = [
  ['unanswered', 'Waiting for you'],
  ['answered', 'Answered'],
  ['all', 'All'],
] as const

export default function QuestionsInboxPage() {
  const qc = useQueryClient()
  const [status, setStatus] = useState<(typeof TABS)[number][0]>('unanswered')
  const { data, isLoading } = useQuery({
    queryKey: ['instructor', 'questions', status],
    queryFn: () => teachingApi.questions(status),
  })

  return (
    <>
      <Seo title="Q&A inbox" noindex />
      <PageHeader
        title="Q&A inbox"
        description="Questions from learners across your courses. Oldest unanswered first."
      />
      <div role="tablist" aria-label="Question status" className="mb-4 flex gap-1 border-b">
        {TABS.map(([v, label]) => (
          <button
            key={v}
            role="tab"
            aria-selected={status === v}
            onClick={() => setStatus(v)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
              status === v ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
            )}
          >
            {label}
            {status === v && data ? ` (${data.meta.total})` : ''}
          </button>
        ))}
      </div>
      {isLoading && <Skeleton className="h-40" />}
      {data?.data.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            {status === 'unanswered' ? 'All caught up — no questions waiting.' : 'Nothing here.'}
          </CardContent>
        </Card>
      )}
      <ul className="grid gap-3">
        {data?.data.map((q) => (
          <li key={q.id}>
            <QuestionThread
              question={q}
              showCourse
              onChanged={() => void qc.invalidateQueries({ queryKey: ['instructor', 'questions'] })}
            />
          </li>
        ))}
      </ul>
    </>
  )
}
