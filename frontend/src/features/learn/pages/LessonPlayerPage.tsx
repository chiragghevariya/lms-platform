import * as Tabs from '@radix-ui/react-tabs'
import { useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Bookmark,
  BookmarkCheck,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Circle,
  Download,
  FileText,
  Lock,
  Menu,
  Trash2,
  X,
} from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { RichTextView } from '@/components/RichTextView'
import { Seo } from '@/components/Seo'
import { ThemeToggle } from '@/components/ThemeToggle'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { VideoPlayer } from '@/components/VideoPlayer'
import { coursesApi, useCourse } from '@/features/courses/api'
import { useBookmarkToggle } from '@/features/learning/api'
import { AssignmentPanel } from '@/features/learning/components/AssignmentPanel'
import { LessonQuestions } from '@/features/learning/components/LessonQuestions'
import { QuizPlayer } from '@/features/learning/components/QuizPlayer'
import { UpgradeCta } from '@/features/membership/components/LockBadge'
import { ApiError } from '@/lib/api-client'
import { formatBytes, formatClock, formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { MembershipTier } from '@/types/api'
import type { LearnLesson } from '@/types/courses'
import { learnApi, useLesson } from '../api'

const TIER_SLUG: Record<number, MembershipTier> = { 1: 'starter', 2: 'pro', 3: 'elite' }

export default function LessonPlayerPage() {
  const { slug = '', lessonId = '' } = useParams()
  const { data: course } = useCourse(slug)
  const lesson = useLesson(lessonId)
  const [sidebar, setSidebar] = useState(false)

  return (
    <div className="flex min-h-dvh flex-col">
      <Seo title={lesson.data?.title ?? 'Lesson'} noindex />
      <header className="bg-card sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-3">
        <Button variant="ghost" size="sm" asChild>
          <Link to={`/courses/${slug}`}>
            <ArrowLeft aria-hidden /> <span className="hidden sm:inline">Course</span>
          </Link>
        </Button>
        <p className="min-w-0 flex-1 truncate font-semibold">{course?.title}</p>
        {course?.access.enrolled && (
          <span className="text-muted-foreground hidden text-sm sm:inline">
            {course.access.progress_percent}% complete
          </span>
        )}
        <ThemeToggle />
        <Button
          variant="ghost"
          size="icon"
          className="lg:hidden"
          aria-label={sidebar ? 'Hide curriculum' : 'Show curriculum'}
          aria-expanded={sidebar}
          aria-controls="curriculum-panel"
          onClick={() => setSidebar((s) => !s)}
        >
          {sidebar ? <X aria-hidden /> : <Menu aria-hidden />}
        </Button>
      </header>

      <div className="flex flex-1">
        <main id="main" className="min-w-0 flex-1 p-4 lg:p-6">
          {lesson.isLoading && <Skeleton className="aspect-video w-full" />}
          {lesson.error && <LockedLesson error={lesson.error} slug={slug} />}
          {lesson.data && <LessonBody key={lesson.data.id} lesson={lesson.data} slug={slug} />}
        </main>

        <aside
          id="curriculum-panel"
          aria-label="Course curriculum"
          className={cn(
            'bg-card w-80 shrink-0 overflow-y-auto border-l',
            sidebar ? 'fixed inset-y-14 right-0 z-20 shadow-xl' : 'hidden lg:block',
          )}
        >
          {course?.curriculum.map((s) => (
            <div key={s.id}>
              <p className="bg-muted/50 px-4 py-2 text-sm font-semibold">{s.title}</p>
              <ul>
                {s.lessons.map((l) => {
                  const current = l.id === lessonId
                  const Icon = l.completed ? CheckCircle2 : l.unlocked ? Circle : Lock
                  const inner = (
                    <>
                      <Icon
                        className={cn(
                          'mt-0.5 size-4 shrink-0',
                          l.completed ? 'text-accent' : 'text-muted-foreground',
                        )}
                        aria-hidden
                      />
                      <span className="flex-1">
                        {l.title}
                        {l.completed && <span className="sr-only"> (completed)</span>}
                        {!l.unlocked && <span className="sr-only"> (locked)</span>}
                        {l.lock_reason === 'drip_locked' && l.available_at && (
                          <span className="text-muted-foreground block text-xs">
                            Unlocks {formatDate(l.available_at)}
                          </span>
                        )}
                      </span>
                      <span className="text-muted-foreground text-xs tabular-nums">
                        {l.duration_seconds ? formatClock(l.duration_seconds) : ''}
                      </span>
                    </>
                  )
                  return (
                    <li key={l.id}>
                      {l.unlocked ? (
                        <Link
                          to={`/learn/${slug}/${l.id}`}
                          aria-current={current ? 'page' : undefined}
                          onClick={() => setSidebar(false)}
                          className={cn(
                            'hover:bg-muted flex gap-3 px-4 py-3 text-sm',
                            current && 'bg-secondary text-secondary-foreground font-medium',
                          )}
                        >
                          {inner}
                        </Link>
                      ) : (
                        <div className="text-muted-foreground flex gap-3 px-4 py-3 text-sm">{inner}</div>
                      )}
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
        </aside>
      </div>
    </div>
  )
}

function LockedLesson({ error, slug }: { error: Error; slug: string }) {
  const body =
    error instanceof ApiError
      ? (error.body as { code?: string; required_tier?: number; available_at?: string } | undefined)
      : undefined
  const tier = body?.required_tier ? TIER_SLUG[body.required_tier] : null

  return (
    <div className="mx-auto grid max-w-lg place-items-center gap-4 py-20 text-center">
      <Lock className="text-muted-foreground size-12" aria-hidden />
      <h1 className="text-2xl font-bold">
        {body?.code === 'drip_locked' ? 'Coming soon' : 'This lesson is locked'}
      </h1>
      <p className="text-muted-foreground">
        {body?.code === 'drip_locked' && body.available_at
          ? `It unlocks on ${formatDate(body.available_at)}.`
          : error.message}
      </p>
      {tier && body?.code !== 'drip_locked' ? (
        <UpgradeCta tier={tier} size="default" />
      ) : (
        <Button asChild variant="outline">
          <Link to={`/courses/${slug}`}>Back to course</Link>
        </Button>
      )}
    </div>
  )
}

const COMPLETION_HINT: Partial<Record<LearnLesson['type'], string>> = {
  video: 'Completes after you watch 90%',
  quiz: 'Completes when you pass the quiz',
  assignment: 'Completes when your work is graded as passing',
}

function LessonBody({ lesson, slug }: { lesson: LearnLesson; slug: string }) {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [completed, setCompleted] = useState(lesson.progress.completed)
  const positionRef = useRef(lesson.progress.position_seconds)
  const bookmark = useBookmarkToggle()

  const onCompletionChange = useCallback(
    (done: boolean) => {
      setCompleted(done)
      void qc.invalidateQueries({ queryKey: ['courses', 'detail', slug] })
      void qc.invalidateQueries({ queryKey: ['me'] })
      void qc.invalidateQueries({ queryKey: ['learn', 'enrollments'] })
      if (done) toast.success('Lesson complete')
    },
    [qc, slug],
  )

  const apply = useCallback(
    (res: { completed: boolean }) => {
      if (res.completed !== completed) onCompletionChange(res.completed)
    },
    [completed, onCompletionChange],
  )

  const saveProgress = useCallback(
    async (body: { position_seconds?: number; completed?: boolean }) => {
      try {
        apply(await learnApi.progress(lesson.id, body))
      } catch (e) {
        if (body.completed !== undefined)
          toast.error(e instanceof Error ? e.message : 'Could not update progress')
      }
    },
    [lesson.id, apply],
  )

  const heartbeat = useCallback(
    async (beat: { position: number; watched: number; rate: number }) => {
      positionRef.current = beat.position
      try {
        apply(
          await learnApi.heartbeat(lesson.id, {
            position_seconds: beat.position,
            watched_seconds: beat.watched,
            playback_rate: Math.min(2, Math.max(0.25, beat.rate)),
          }),
        )
      } catch {
        /* best-effort; the next beat carries on */
      }
    },
    [lesson.id, apply],
  )

  const goNext = () => lesson.next_id && navigate(`/learn/${slug}/${lesson.next_id}`)
  const [params] = useSearchParams()
  const initialTab = params.get('tab') === 'qa' ? 'qa' : 'overview'

  return (
    <div className="mx-auto grid max-w-5xl gap-6">
      {lesson.type === 'video' &&
        (lesson.video?.status === 'ready' ? (
          <VideoPlayer
            title={lesson.title}
            startAt={lesson.progress.completed ? 0 : lesson.progress.position_seconds}
            getSource={() => coursesApi.playback(lesson.id)}
            heartbeatSeconds={lesson.progress.heartbeat_seconds}
            onHeartbeat={(b) => void heartbeat(b)}
            onProgress={(s) => {
              positionRef.current = s
              void saveProgress({ position_seconds: s })
            }}
          />
        ) : (
          <Alert>This video is still processing. Check back in a few minutes.</Alert>
        ))}
      {lesson.type === 'pdf' && lesson.has_attachment && (
        <PdfViewer lessonId={lesson.id} title={lesson.title} />
      )}

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          {lesson.section && <p className="text-muted-foreground text-sm">{lesson.section}</p>}
          <h1 className="text-2xl font-bold">{lesson.title}</h1>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            aria-pressed={lesson.bookmarked}
            loading={bookmark.isPending}
            onClick={() =>
              bookmark.mutate(
                {
                  on: !lesson.bookmarked,
                  lesson_id: lesson.id,
                  position_seconds: lesson.type === 'video' ? positionRef.current : undefined,
                },
                {
                  onSuccess: () =>
                    toast.success(lesson.bookmarked ? 'Bookmark removed' : 'Lesson bookmarked'),
                  onError: (e) => toast.error(e.message),
                },
              )
            }
          >
            {lesson.bookmarked ? <BookmarkCheck aria-hidden /> : <Bookmark aria-hidden />}
            {lesson.bookmarked ? 'Bookmarked' : 'Bookmark'}
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={!lesson.prev_id}
            onClick={() => lesson.prev_id && navigate(`/learn/${slug}/${lesson.prev_id}`)}
          >
            <ChevronLeft aria-hidden /> Previous
          </Button>
          {lesson.progress.can_mark_complete ? (
            <Button
              variant={completed ? 'secondary' : 'accent'}
              size="sm"
              onClick={() => void saveProgress({ completed: !completed })}
              aria-pressed={completed}
            >
              <CheckCircle2 aria-hidden /> {completed ? 'Completed' : 'Mark complete'}
            </Button>
          ) : (
            <span
              className={cn(
                'inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-sm',
                completed ? 'bg-secondary text-secondary-foreground font-medium' : 'text-muted-foreground',
              )}
              role="status"
            >
              {completed ? (
                <CheckCircle2 className="size-4" aria-hidden />
              ) : (
                <Circle className="size-4" aria-hidden />
              )}
              {completed ? 'Completed' : COMPLETION_HINT[lesson.type]}
            </span>
          )}
          <Button size="sm" disabled={!lesson.next_id} onClick={goNext}>
            Next <ChevronRight aria-hidden />
          </Button>
        </div>
      </div>

      {lesson.type === 'quiz' && (
        <QuizPlayer lessonId={lesson.id} onPassed={() => !completed && onCompletionChange(true)} />
      )}
      {lesson.type === 'assignment' && (
        <AssignmentPanel lessonId={lesson.id} onGraded={lesson.next_id ? goNext : undefined} />
      )}

      <Tabs.Root defaultValue={initialTab} className="grid gap-4">
        <Tabs.List aria-label="Lesson tabs" className="flex gap-1 border-b">
          {(
            [
              ['overview', 'Overview'],
              ['notes', `Notes${lesson.notes.length ? ` (${lesson.notes.length})` : ''}`],
              ['resources', `Resources${lesson.resources.length ? ` (${lesson.resources.length})` : ''}`],
              ['qa', 'Q&A'],
            ] as const
          ).map(([v, l]) => (
            <Tabs.Trigger
              key={v}
              value={v}
              className="data-[state=active]:border-primary data-[state=active]:text-foreground text-muted-foreground -mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium"
            >
              {l}
            </Tabs.Trigger>
          ))}
        </Tabs.List>
        <Tabs.Content value="overview">
          {lesson.content ? (
            <RichTextView html={lesson.content} />
          ) : (
            <p className="text-muted-foreground text-sm">No written notes for this lesson.</p>
          )}
        </Tabs.Content>
        <Tabs.Content value="notes">
          <NotesPanel lesson={lesson} getPosition={() => positionRef.current} />
        </Tabs.Content>
        <Tabs.Content value="resources">
          {lesson.resources.length ? (
            <ul className="divide-y rounded-lg border">
              {lesson.resources.map((r) => (
                <li key={r.id} className="flex items-center gap-3 p-3 text-sm">
                  <FileText className="text-muted-foreground size-4" aria-hidden />
                  <span className="flex-1">{r.title}</span>
                  <span className="text-muted-foreground text-xs">{formatBytes(r.size)}</span>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                      learnApi
                        .download(`/learn/resources/${r.id}`, r.title)
                        .catch(() => toast.error('Download failed'))
                    }
                    aria-label={`Download ${r.title}`}
                  >
                    <Download aria-hidden />
                  </Button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-muted-foreground text-sm">No downloadable resources.</p>
          )}
        </Tabs.Content>
        <Tabs.Content value="qa">
          <LessonQuestions lessonId={lesson.id} />
        </Tabs.Content>
      </Tabs.Root>
    </div>
  )
}

function NotesPanel({ lesson, getPosition }: { lesson: LearnLesson; getPosition: () => number }) {
  const qc = useQueryClient()
  const [body, setBody] = useState('')
  const [saving, setSaving] = useState(false)
  const refresh = () => qc.invalidateQueries({ queryKey: ['learn', 'lesson', lesson.id] })

  return (
    <div className="grid gap-4">
      <form
        className="grid gap-2"
        onSubmit={async (e) => {
          e.preventDefault()
          if (!body.trim()) return
          setSaving(true)
          try {
            await learnApi.addNote(lesson.id, {
              body,
              position_seconds: lesson.type === 'video' ? getPosition() : null,
            })
            setBody('')
            await refresh()
          } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Could not save note')
          } finally {
            setSaving(false)
          }
        }}
      >
        <label htmlFor="note" className="text-sm font-medium">
          New note{lesson.type === 'video' ? ' (saved at the current video time)' : ''}
        </label>
        <textarea
          id="note"
          rows={3}
          maxLength={5000}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
        />
        <Button
          type="submit"
          size="sm"
          loading={saving}
          disabled={!body.trim()}
          className="justify-self-start"
        >
          Save note
        </Button>
      </form>
      <ul className="grid gap-2">
        {lesson.notes.map((n) => (
          <li key={n.id} className="flex gap-3 rounded-lg border p-3 text-sm">
            {n.position_seconds !== null && (
              <span className="bg-secondary text-secondary-foreground h-fit rounded px-1.5 font-mono text-xs">
                {formatClock(n.position_seconds)}
              </span>
            )}
            <p className="flex-1 whitespace-pre-wrap">{n.body}</p>
            <Button
              size="icon"
              variant="ghost"
              className="size-8"
              aria-label="Delete note"
              onClick={() => learnApi.deleteNote(n.id).then(refresh)}
            >
              <Trash2 aria-hidden />
            </Button>
          </li>
        ))}
      </ul>
    </div>
  )
}

function PdfViewer({ lessonId, title }: { lessonId: string; title: string }) {
  const [url, setUrl] = useState<string | null>(null)
  useEffect(() => {
    let revoked: string | null = null
    learnApi
      .attachmentBlobUrl(lessonId)
      .then((u) => setUrl((revoked = u)))
      .catch(() => toast.error('Could not load the PDF'))
    return () => {
      if (revoked) URL.revokeObjectURL(revoked)
    }
  }, [lessonId])

  return url ? (
    <iframe src={`${url}#toolbar=0`} title={title} className="h-[75dvh] w-full rounded-xl border" />
  ) : (
    <Skeleton className="h-[75dvh]" />
  )
}
