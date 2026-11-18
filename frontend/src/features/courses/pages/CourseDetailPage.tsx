import * as Accordion from '@radix-ui/react-accordion'
import { useQuery } from '@tanstack/react-query'
import {
  Award,
  Bookmark,
  BookmarkCheck,
  CheckCircle2,
  ChevronDown,
  Clock,
  FileText,
  Lock,
  PlayCircle,
  Video,
} from 'lucide-react'
import { useState, lazy, Suspense } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Seo } from '@/components/Seo'
import { RichTextView } from '@/components/RichTextView'
import { StarInput, Stars } from '@/components/StarRating'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { openRazorpay } from '@/features/billing/razorpay'
import { qaApi } from '@/features/instructor/api'
import { useBookmarkToggle } from '@/features/learning/api'
import { billingApi } from '@/features/billing/api'
import { UpgradeCta } from '@/features/membership/components/LockBadge'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatClock, formatDate, formatMinutes, trimMoney } from '@/lib/format'
import { useAuthStore } from '@/stores/auth-store'
import type { MembershipTier } from '@/types/api'
import type { CourseDetail, OutlineLesson } from '@/types/courses'
import { coursesApi, useCourse, useReviews, useSubmitReview } from '../api'
import NotFound from '@/components/NotFound'
import { ApiError } from '@/lib/api-client'

// hls.js + Plyr (~680 KB) load only when a preview is actually opened.
const VideoPlayer = lazy(() => import('@/components/VideoPlayer').then((m) => ({ default: m.VideoPlayer })))

const TIER_SLUG: Record<number, MembershipTier> = { 1: 'starter', 2: 'pro', 3: 'elite' }
const typeIcon = (t: OutlineLesson['type']) => (t === 'video' ? Video : t === 'pdf' ? FileText : PlayCircle)

function lockLabel(l: OutlineLesson) {
  if (l.lock_reason === 'drip_locked' && l.available_at) return `Unlocks ${formatDate(l.available_at)}`
  return 'Locked'
}

export default function CourseDetailPage() {
  const { slug = '' } = useParams()
  const { data: course, isLoading, error } = useCourse(slug)
  const [preview, setPreview] = useState<{ id: string; title: string } | null>(null)

  if (isLoading)
    return (
      <div className="mx-auto max-w-6xl px-4 py-12">
        <Skeleton className="h-96" />
      </div>
    )
  if (error instanceof ApiError && error.status === 404) return <NotFound />
  if (!course) return null

  const firstLesson = course.curriculum[0]?.lessons[0]

  return (
    <>
      <Seo
        title={course.title}
        path={`/courses/${course.slug}`}
        description={course.subtitle ?? undefined}
        image={course.thumbnail_url ?? undefined}
      />
      <section className="bg-foreground text-background">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 lg:grid-cols-[1fr_22rem]">
          <div className="grid content-start gap-4">
            <nav aria-label="Breadcrumb" className="text-sm opacity-80">
              <Link to="/courses" className="hover:underline">
                Courses
              </Link>
              {course.category && (
                <>
                  {' / '}
                  <Link to={`/courses?category=${course.category.slug}`} className="hover:underline">
                    {course.category.name}
                  </Link>
                </>
              )}
            </nav>
            <h1 className="text-3xl font-extrabold tracking-tight text-balance sm:text-4xl">
              {course.title}
            </h1>
            {course.subtitle && <p className="text-lg opacity-90">{course.subtitle}</p>}
            <div className="flex flex-wrap items-center gap-3 text-sm">
              {course.tier ? (
                <TierBadge tier={course.tier} className="bg-background text-foreground border-transparent" />
              ) : (
                <Badge variant="secondary">Free with any account</Badge>
              )}
              {course.rating_count > 0 && (
                <span className="flex items-center gap-1">
                  <strong>{course.rating_avg.toFixed(1)}</strong> <Stars value={course.rating_avg} /> (
                  {course.rating_count})
                </span>
              )}
              <span className="flex items-center gap-1 opacity-90">
                <Clock className="size-4" aria-hidden /> {formatMinutes(course.duration_minutes)}
              </span>
              <span className="opacity-90">{course.lessons_count} lessons</span>
              <span className="capitalize opacity-90">{course.level.replace('_', ' ')}</span>
            </div>
            <p className="text-sm opacity-80">By {course.instructors.map((i) => i.name).join(', ')}</p>
          </div>
          <EnrollCard
            course={course}
            onPreview={() =>
              firstLesson?.is_preview && setPreview({ id: firstLesson.id, title: firstLesson.title })
            }
          />
        </div>
      </section>

      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 lg:grid-cols-[1fr_22rem]">
        <div className="grid min-w-0 content-start gap-10">
          {course.description && (
            <section aria-labelledby="about-heading">
              <h2 id="about-heading" className="mb-2 text-2xl font-bold">
                About this course
              </h2>
              <RichTextView html={course.description} />
            </section>
          )}

          <section aria-labelledby="curriculum-heading">
            <h2 id="curriculum-heading" className="mb-4 text-2xl font-bold">
              Curriculum
            </h2>
            <Accordion.Root
              type="multiple"
              defaultValue={[course.curriculum[0]?.id ?? '']}
              className="divide-y rounded-xl border"
            >
              {course.curriculum.map((section) => (
                <Accordion.Item key={section.id} value={section.id}>
                  <Accordion.Header>
                    <Accordion.Trigger className="hover:bg-muted/50 group flex w-full items-center justify-between gap-3 p-4 text-left font-semibold">
                      {section.title}
                      <span className="text-muted-foreground flex items-center gap-2 text-sm font-normal">
                        {section.lessons.length} lessons
                        <ChevronDown
                          className="size-4 transition-transform group-data-[state=open]:rotate-180"
                          aria-hidden
                        />
                      </span>
                    </Accordion.Trigger>
                  </Accordion.Header>
                  <Accordion.Content>
                    <ul className="divide-y border-t">
                      {section.lessons.map((l) => {
                        const Icon = l.completed ? CheckCircle2 : l.unlocked ? typeIcon(l.type) : Lock
                        return (
                          <li key={l.id} className="flex items-center gap-3 px-4 py-3 text-sm">
                            <Icon
                              className={
                                l.completed
                                  ? 'text-accent size-4 shrink-0'
                                  : 'text-muted-foreground size-4 shrink-0'
                              }
                              aria-hidden
                            />
                            <span className="flex-1">
                              {l.unlocked && course.access.has_access ? (
                                <Link to={`/learn/${course.slug}/${l.id}`} className="hover:underline">
                                  {l.title}
                                </Link>
                              ) : (
                                l.title
                              )}
                              {l.completed && <span className="sr-only"> (completed)</span>}
                            </span>
                            {l.is_preview && l.type === 'video' && (
                              <Button
                                size="sm"
                                variant="link"
                                className="h-auto p-0"
                                onClick={() => setPreview({ id: l.id, title: l.title })}
                              >
                                Preview
                              </Button>
                            )}
                            {!l.unlocked && (
                              <span className="text-muted-foreground text-xs">{lockLabel(l)}</span>
                            )}
                            <span className="text-muted-foreground w-12 text-right text-xs tabular-nums">
                              {l.duration_seconds ? formatClock(l.duration_seconds) : ''}
                            </span>
                          </li>
                        )
                      })}
                    </ul>
                  </Accordion.Content>
                </Accordion.Item>
              ))}
            </Accordion.Root>
          </section>

          <section aria-labelledby="instructor-heading" className="grid gap-4">
            <h2 id="instructor-heading" className="text-2xl font-bold">
              {course.instructors.length > 1 ? 'Instructors' : 'Your instructor'}
            </h2>
            {course.instructors.map((i) => (
              <Card key={i.id}>
                <CardContent className="flex gap-4 p-5">
                  <Avatar className="size-16 text-lg">
                    {i.avatar_url && <AvatarImage src={i.avatar_url} alt="" />}
                    <AvatarFallback>{i.name.slice(0, 2).toUpperCase()}</AvatarFallback>
                  </Avatar>
                  <div className="grid gap-1">
                    <p className="font-semibold">{i.name}</p>
                    {i.headline && <p className="text-muted-foreground text-sm">{i.headline}</p>}
                    {i.bio && <p className="text-sm">{i.bio}</p>}
                  </div>
                </CardContent>
              </Card>
            ))}
          </section>

          {course.access.has_access && <AnnouncementsSection slug={course.slug} />}
          <ReviewsSection course={course} />
        </div>
      </div>

      <Dialog open={!!preview} onOpenChange={(o) => !o && setPreview(null)}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Preview: {preview?.title}</DialogTitle>
          </DialogHeader>
          {preview && (
            <Suspense fallback={<div className="aspect-video animate-pulse rounded-xl bg-black/80" />}>
              <VideoPlayer title={preview.title} getSource={() => coursesApi.playback(preview.id)} />
            </Suspense>
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}

function EnrollCard({ course, onPreview }: { course: CourseDetail; onPreview: () => void }) {
  const user = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const [buying, setBuying] = useState(false)
  const a = course.access
  const nextId = a.next_lesson_id ?? course.curriculum[0]?.lessons[0]?.id
  const tier = a.required_tier ? TIER_SLUG[a.required_tier] : null

  const buy = async () => {
    if (!user) return navigate('/login', { state: { from: `/courses/${course.slug}` } })
    setBuying(true)
    try {
      const result = await coursesApi.purchase(course.slug)
      if (result.redirect_url) return window.location.assign(result.redirect_url)
      if (result.razorpay) {
        const resp = await openRazorpay(result.razorpay)
        await billingApi.verifyRazorpay({ ...resp })
        navigate(`/billing/success?gateway=razorpay&course=${course.slug}`)
      }
    } catch (e) {
      if (!(e instanceof DOMException && e.name === 'AbortError'))
        toast.error(e instanceof Error ? e.message : 'Purchase failed')
    } finally {
      setBuying(false)
    }
  }

  return (
    <Card className="text-card-foreground self-start">
      {course.thumbnail_url ? (
        <img
          src={course.thumbnail_url}
          srcSet={course.thumbnail_srcset ?? undefined}
          sizes="(min-width: 1024px) 380px, 100vw"
          width={1280}
          height={720}
          fetchPriority="high"
          alt=""
          className="aspect-video w-full rounded-t-xl object-cover"
        />
      ) : (
        <div
          className="from-primary/70 to-accent/60 aspect-video rounded-t-xl bg-gradient-to-br"
          aria-hidden
        />
      )}
      <CardContent className="grid gap-3 p-5">
        {a.has_access ? (
          <>
            {a.enrolled && (
              <div className="grid gap-1">
                <div
                  role="progressbar"
                  aria-label="Course progress"
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-valuenow={a.progress_percent}
                  className="bg-muted h-2 overflow-hidden rounded-full"
                >
                  <div className="bg-accent h-full" style={{ width: `${a.progress_percent}%` }} />
                </div>
                <p className="text-sm">
                  <strong>{a.progress_percent}%</strong> complete
                </p>
              </div>
            )}
            <Button asChild size="lg">
              <Link to={`/learn/${course.slug}/${nextId}`}>
                {a.completed_at ? 'Review course' : a.enrolled ? 'Continue learning' : 'Start learning'}
              </Link>
            </Button>
            {a.certificate_id && (
              <Button asChild variant="outline">
                <Link to="/app/certificates">
                  <Award aria-hidden /> View your certificate
                </Link>
              </Button>
            )}
          </>
        ) : (
          <>
            {tier ? (
              <>
                <p className="text-sm">
                  Included with <strong className="capitalize">{tier}</strong> membership or higher.
                </p>
                {user ? (
                  <UpgradeCta tier={tier} size="default" className="w-full" />
                ) : (
                  <Button asChild size="lg">
                    <Link to="/register">Join to start learning</Link>
                  </Button>
                )}
              </>
            ) : (
              <Button asChild size="lg">
                <Link to={user ? `/learn/${course.slug}/${nextId}` : '/register'}>
                  {user ? 'Start learning' : 'Sign up free to start'}
                </Link>
              </Button>
            )}
            {a.can_purchase && course.price && (
              <Button variant="outline" loading={buying} onClick={buy}>
                Buy this course · {trimMoney(course.price.formatted)}
              </Button>
            )}
          </>
        )}
        {user && <SaveCourseButton courseId={course.id} saved={a.bookmarked} />}
        {course.curriculum[0]?.lessons[0]?.is_preview && (
          <Button variant="ghost" onClick={onPreview}>
            <PlayCircle aria-hidden /> Watch a free preview
          </Button>
        )}
      </CardContent>
    </Card>
  )
}

function AnnouncementsSection({ slug }: { slug: string }) {
  const { data } = useQuery({
    queryKey: ['courses', 'announcements', slug],
    queryFn: () => qaApi.courseAnnouncements(slug),
    retry: false,
  })
  if (!data?.length) return null
  return (
    <section aria-labelledby="ann-h" className="grid gap-3">
      <h2 id="ann-h" className="text-xl font-bold">
        Announcements
      </h2>
      {data.slice(0, 3).map((a) => (
        <Card key={a.id}>
          <CardContent className="grid gap-1 p-4">
            <p className="font-semibold">{a.title}</p>
            <p className="text-muted-foreground text-xs">
              {a.author.name} · {formatDate(a.created_at)}
            </p>
            <RichTextView html={a.body} />
          </CardContent>
        </Card>
      ))}
    </section>
  )
}

function SaveCourseButton({ courseId, saved }: { courseId: string; saved: boolean }) {
  const toggle = useBookmarkToggle()
  return (
    <Button
      variant="ghost"
      aria-pressed={saved}
      loading={toggle.isPending}
      onClick={() =>
        toggle.mutate(
          { on: !saved, course_id: courseId },
          {
            onSuccess: () => toast.success(saved ? 'Removed from bookmarks' : 'Saved to bookmarks'),
            onError: (e) => toast.error(e.message),
          },
        )
      }
    >
      {saved ? <BookmarkCheck aria-hidden /> : <Bookmark aria-hidden />}
      {saved ? 'Saved' : 'Save for later'}
    </Button>
  )
}

function ReviewsSection({ course }: { course: CourseDetail }) {
  const { data } = useReviews(course.slug)
  const submit = useSubmitReview(course.slug)
  const [rating, setRating] = useState(course.my_review?.rating ?? 0)
  const [comment, setComment] = useState(course.my_review?.comment ?? '')
  const canReview = course.access.has_access && course.access.enrolled && course.access.reason !== 'manage'

  return (
    <section aria-labelledby="reviews-heading" className="grid gap-4">
      <h2 id="reviews-heading" className="text-2xl font-bold">
        Reviews
      </h2>
      {canReview && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {course.my_review ? 'Update your review' : 'Rate this course'}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault()
                submit.mutate(
                  { rating, comment: comment || null },
                  {
                    onSuccess: () => toast.success('Thanks for your review!'),
                    onError: (err) => toast.error(err.message),
                  },
                )
              }}
            >
              <StarInput value={rating} onChange={setRating} />
              <label className="sr-only" htmlFor="review-comment">
                Comment
              </label>
              <textarea
                id="review-comment"
                rows={3}
                maxLength={2000}
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="What did you learn? (optional)"
                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
              />
              <Button
                type="submit"
                disabled={!rating}
                loading={submit.isPending}
                className="justify-self-start"
              >
                Submit review
              </Button>
            </form>
          </CardContent>
        </Card>
      )}
      {data?.data.length ? (
        <ul className="grid gap-4">
          {data.data.map((r) => (
            <li key={r.id} className="rounded-lg border p-4">
              <div className="flex items-center justify-between">
                <span className="font-medium">{r.user.name}</span>
                <Stars value={r.rating} />
              </div>
              <p className="text-muted-foreground mt-2 text-sm">{r.comment}</p>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-muted-foreground text-sm">No written reviews yet.</p>
      )}
    </section>
  )
}
