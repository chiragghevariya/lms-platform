import { useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, ChevronDown, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { qaApi } from '@/features/instructor/api'
import type { QaQuestion } from '@/types/instructor'

/** One question with expandable answers and a reply box (used by learners and the instructor inbox). */
export function QuestionThread({
  question: q,
  showCourse = false,
  onChanged,
}: {
  question: QaQuestion
  showCourse?: boolean
  onChanged?: () => void
}) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [reply, setReply] = useState('')
  const [sending, setSending] = useState(false)
  const thread = useQuery({
    queryKey: ['qa', 'question', q.id],
    queryFn: () => qaApi.show(q.id),
    enabled: open,
  })

  const send = async () => {
    setSending(true)
    try {
      await qaApi.answer(q.id, reply)
      setReply('')
      await qc.invalidateQueries({ queryKey: ['qa', 'question', q.id] })
      onChanged?.()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not post')
    } finally {
      setSending(false)
    }
  }
  const remove = async (fn: () => Promise<unknown>) => {
    try {
      await fn()
      await qc.invalidateQueries({ queryKey: ['qa'] })
      onChanged?.()
    } catch {
      toast.error('Could not delete')
    }
  }

  return (
    <article className="rounded-lg border">
      <button
        type="button"
        className="hover:bg-muted/40 flex w-full items-start gap-3 p-4 text-left"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
      >
        <div className="min-w-0 flex-1">
          <p className="font-medium">{q.title}</p>
          <p className="text-muted-foreground text-xs">
            {q.author.name} · {new Date(q.created_at).toLocaleDateString()}
            {showCourse && ` · ${q.course.title}${q.lesson ? ` › ${q.lesson.title}` : ''}`}
            {` · ${q.answers_count} repl${q.answers_count === 1 ? 'y' : 'ies'}`}
          </p>
        </div>
        {q.instructor_answered && (
          <Badge variant="secondary" className="shrink-0">
            <BadgeCheck className="mr-1 size-3" aria-hidden /> Instructor answered
          </Badge>
        )}
        <ChevronDown
          className={`size-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
          aria-hidden
        />
      </button>
      {open && (
        <div className="grid gap-3 border-t p-4">
          <p className="text-sm whitespace-pre-wrap">{q.body}</p>
          {q.is_mine && (
            <Button
              size="sm"
              variant="ghost"
              className="justify-self-start"
              onClick={() => void remove(() => qaApi.remove(q.id))}
            >
              <Trash2 aria-hidden /> Delete question
            </Button>
          )}
          <ul className="grid gap-2">
            {thread.data?.answers?.map((a) => (
              <li
                key={a.id}
                className={`rounded-md p-3 text-sm ${a.is_instructor ? 'bg-primary/5 border-primary/30 border' : 'bg-muted'}`}
              >
                <p className="mb-1 flex items-center gap-2 text-xs font-medium">
                  {a.author.name}
                  {a.is_instructor && <Badge className="h-5 px-1.5 text-[10px]">Instructor</Badge>}
                  <span className="text-muted-foreground font-normal">
                    {new Date(a.created_at).toLocaleString()}
                  </span>
                  {a.is_mine && (
                    <button
                      type="button"
                      className="text-muted-foreground ml-auto hover:underline"
                      onClick={() => void remove(() => qaApi.removeAnswer(a.id))}
                    >
                      Delete
                    </button>
                  )}
                </p>
                <p className="whitespace-pre-wrap">{a.body}</p>
              </li>
            ))}
          </ul>
          <form
            className="grid gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              if (reply.trim()) void send()
            }}
          >
            <label htmlFor={`reply-${q.id}`} className="sr-only">
              Reply
            </label>
            <textarea
              id={`reply-${q.id}`}
              rows={3}
              maxLength={5000}
              placeholder="Write a reply…"
              value={reply}
              onChange={(e) => setReply(e.target.value)}
              className="border-input bg-background rounded-md border px-3 py-2 text-sm"
            />
            <Button
              type="submit"
              size="sm"
              loading={sending}
              disabled={reply.trim().length < 2}
              className="justify-self-start"
            >
              Reply
            </Button>
          </form>
        </div>
      )}
    </article>
  )
}
