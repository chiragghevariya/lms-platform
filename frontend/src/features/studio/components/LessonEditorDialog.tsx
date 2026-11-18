import { FileUp, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { RichTextEditor } from '@/components/RichTextEditor'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { formatBytes, formatClock } from '@/lib/format'
import type { LessonType, StudioLesson } from '@/types/courses'
import { studioApi, useStudioMutation } from '../api'
import { AssignmentSettingsForm } from './AssignmentSettingsForm'
import { QuizBuilder } from './QuizBuilder'
import { VideoUploader } from './VideoUploader'

const TYPES: [LessonType, string][] = [
  ['video', 'Video'],
  ['text', 'Article'],
  ['pdf', 'PDF'],
  ['quiz', 'Quiz'],
  ['assignment', 'Assignment'],
  ['live', 'Live class (coming soon)'],
]

export function LessonEditorDialog({
  courseId,
  lesson,
  onClose,
}: {
  courseId: string
  lesson: StudioLesson
  onClose: () => void
}) {
  const [title, setTitle] = useState(lesson.title)
  const [type, setType] = useState<LessonType>(lesson.type)
  const [content, setContent] = useState(lesson.content ?? '')
  const [isPreview, setIsPreview] = useState(lesson.is_preview)
  const [drip, setDrip] = useState(lesson.drip_days)
  const save = useStudioMutation(courseId, (body: Parameters<typeof studioApi.updateLesson>[1]) =>
    studioApi.updateLesson(lesson.id, body),
  )
  const attach = useStudioMutation(courseId, (f: File) => studioApi.uploadAttachment(lesson.id, f))
  const addRes = useStudioMutation(courseId, (f: File) => studioApi.addResource(lesson.id, f))
  const delRes = useStudioMutation(courseId, (id: string) => studioApi.deleteResource(id))

  const fileInput = (label: string, accept: string, onFile: (f: File) => void, pending: boolean) => (
    <Button type="button" variant="outline" size="sm" asChild>
      <label className="focus-within:ring-ring cursor-pointer focus-within:ring-2" aria-busy={pending}>
        <FileUp aria-hidden /> {pending ? 'Uploading…' : label}
        <input
          type="file"
          accept={accept}
          className="sr-only"
          onChange={(e) => {
            const f = e.target.files?.[0]
            e.target.value = ''
            if (f) onFile(f)
          }}
        />
      </label>
    </Button>
  )

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit lesson</DialogTitle>
          <DialogDescription>Changes to published courses go live immediately.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <FormField id="l-title" label="Title">
            {(a) => <Input {...a} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={160} />}
          </FormField>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="l-type">Type</Label>
              <select
                id="l-type"
                className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                value={type}
                onChange={(e) => setType(e.target.value as LessonType)}
              >
                {TYPES.map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </div>
            <FormField
              id="l-drip"
              label="Drip (days after the learner starts)"
              hint="0 = available immediately"
            >
              {(a) => (
                <Input
                  {...a}
                  type="number"
                  min={0}
                  max={365}
                  value={drip}
                  onChange={(e) => setDrip(Number(e.target.value))}
                />
              )}
            </FormField>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              className="accent-primary size-4"
              checked={isPreview}
              onChange={(e) => setIsPreview(e.target.checked)}
            />
            Free preview (anyone can watch, even without a membership)
          </label>

          {type === 'video' && (
            <div className="grid gap-2">
              <span className="text-sm font-medium">Video</span>
              {lesson.video && (
                <p className="flex items-center gap-2 text-sm">
                  {lesson.video.title}{' '}
                  <Badge variant={lesson.video.status === 'ready' ? 'secondary' : 'outline'}>
                    {lesson.video.status}
                  </Badge>
                  {lesson.video.duration_seconds ? (
                    <span className="text-muted-foreground">
                      {formatClock(lesson.video.duration_seconds)}
                    </span>
                  ) : null}
                </p>
              )}
              <VideoUploader
                title={title}
                onUploaded={(v) =>
                  save.mutate({ video_id: v.id }, { onSuccess: () => toast.success('Video attached') })
                }
              />
            </div>
          )}

          {type === 'pdf' && (
            <div className="flex items-center gap-3 text-sm">
              {lesson.has_attachment ? (
                <Badge variant="secondary">PDF attached</Badge>
              ) : (
                <span className="text-muted-foreground">No PDF yet</span>
              )}
              {fileInput(
                lesson.has_attachment ? 'Replace PDF' : 'Upload PDF',
                'application/pdf',
                (f) => attach.mutate(f, { onError: (e) => toast.error(e.message) }),
                attach.isPending,
              )}
            </div>
          )}

          {(type === 'quiz' || type === 'assignment') &&
            (lesson.type === type ? (
              type === 'quiz' ? (
                <QuizBuilder lessonId={lesson.id} courseId={courseId} />
              ) : (
                <AssignmentSettingsForm lessonId={lesson.id} courseId={courseId} />
              )
            ) : (
              <p className="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                Save the lesson as a {type} first, then set it up here.
              </p>
            ))}

          <div className="grid gap-2">
            <span id="l-content-label" className="text-sm font-medium">
              {type === 'text' ? 'Article' : 'Lesson notes'}
            </span>
            <RichTextEditor
              value={content}
              onChange={setContent}
              aria-labelledby="l-content-label"
              placeholder="Key points, links, instructions…"
            />
          </div>

          <div className="grid gap-2">
            <span className="text-sm font-medium">Downloadable resources</span>
            <ul className="divide-y rounded-md border text-sm empty:hidden">
              {lesson.resources.map((r) => (
                <li key={r.id} className="flex items-center gap-2 p-2">
                  <span className="flex-1">{r.title}</span>
                  <span className="text-muted-foreground text-xs">{formatBytes(r.size)}</span>
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Remove ${r.title}`}
                    onClick={() => delRes.mutate(r.id)}
                  >
                    <Trash2 aria-hidden />
                  </Button>
                </li>
              ))}
            </ul>
            <div>
              {fileInput(
                'Add file',
                '.pdf,.zip,.docx,.xlsx,.pptx,.csv,.txt,.png,.jpg,.jpeg,.psd,.fig,.mp3',
                (f) => addRes.mutate(f, { onError: (e) => toast.error(e.message) }),
                addRes.isPending,
              )}
            </div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          <Button
            loading={save.isPending}
            onClick={() =>
              save.mutate(
                { title, type, content: content || null, is_preview: isPreview, drip_days: drip },
                {
                  onSuccess: () => {
                    toast.success('Lesson saved')
                    onClose()
                  },
                  onError: (e) => toast.error(e.message),
                },
              )
            }
          >
            Save lesson
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
