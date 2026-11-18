import {
  closestCorners,
  DndContext,
  type DragEndEvent,
  type DragOverEvent,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { FileText, GripVertical, Pencil, Plus, Trash2, Video } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatClock } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { StudioCourse, StudioLesson } from '@/types/courses'
import { studioApi, useStudioMutation } from '../api'
import { LessonEditorDialog } from './LessonEditorDialog'

type Layout = { id: string; title: string; lessons: StudioLesson[] }[]

/**
 * Drag-and-drop sections and lessons (lessons can move between sections). Keyboard:
 * focus a handle, Space to lift, arrows to move, Space to drop. Saves the order on drop.
 */
export function CurriculumBuilder({ course }: { course: StudioCourse }) {
  const [layout, setLayout] = useState<Layout>(course.sections)
  const [editing, setEditing] = useState<StudioLesson | null>(null)
  const [newSection, setNewSection] = useState('')
  const reorder = useStudioMutation(course.id, (l: Layout) =>
    studioApi.reorder(
      course.id,
      l.map((s) => ({ id: s.id, lessons: s.lessons.map((x) => x.id) })),
    ),
  )
  const addSection = useStudioMutation(course.id, (t: string) => studioApi.addSection(course.id, t))
  const addLesson = useStudioMutation(course.id, (sectionId: string) =>
    studioApi.addLesson(sectionId, { title: 'New lesson', type: 'video' }),
  )
  const delSection = useStudioMutation(course.id, (id: string) => studioApi.deleteSection(id))
  const delLesson = useStudioMutation(course.id, (id: string) => studioApi.deleteLesson(id))
  const rename = useStudioMutation(course.id, ({ id, title }: { id: string; title: string }) =>
    studioApi.renameSection(id, title),
  )

  // Re-sync local drag state whenever the server returns a new curriculum (derived during render).
  const [synced, setSynced] = useState(course.sections)
  if (synced !== course.sections) {
    setSynced(course.sections)
    setLayout(course.sections)
  }

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  // Screen-reader announcements by title (dnd-kit's defaults read out raw ids).
  const nameOf = (id: string | number) => {
    const sec = layout.find((s) => s.id === id)
    if (sec) return `section “${sec.title}”`
    const lesson = layout.flatMap((s) => s.lessons).find((l) => l.id === id)
    return lesson ? `lesson “${lesson.title}”` : 'item'
  }
  const announcements = {
    onDragStart: ({ active }: { active: { id: string | number } }) =>
      `Picked up ${nameOf(active.id)}. Use the arrow keys to move, Space to drop, Escape to cancel.`,
    onDragOver: ({
      active,
      over,
    }: {
      active: { id: string | number }
      over: { id: string | number } | null
    }) =>
      over
        ? `${nameOf(active.id)} is over ${nameOf(over.id)}.`
        : `${nameOf(active.id)} is no longer over a drop target.`,
    onDragEnd: ({
      active,
      over,
    }: {
      active: { id: string | number }
      over: { id: string | number } | null
    }) =>
      over
        ? `Dropped ${nameOf(active.id)} at the position of ${nameOf(over.id)}.`
        : `Dropped ${nameOf(active.id)}.`,
    onDragCancel: ({ active }: { active: { id: string | number } }) =>
      `Moving ${nameOf(active.id)} was cancelled.`,
  }
  const sectionOf = (id: string) => layout.find((s) => s.id === id || s.lessons.some((l) => l.id === id))

  // Moving a lesson over another section's lesson → transfer it there (live preview).
  const onDragOver = ({ active, over }: DragOverEvent) => {
    if (!over || layout.some((s) => s.id === active.id)) return
    const from = sectionOf(String(active.id))
    const to = sectionOf(String(over.id))
    if (!from || !to || from.id === to.id) return
    setLayout((prev) =>
      prev.map((s) => {
        if (s.id === from.id) return { ...s, lessons: s.lessons.filter((l) => l.id !== active.id) }
        if (s.id === to.id) {
          const moving = from.lessons.find((l) => l.id === active.id)!
          const idx = s.lessons.findIndex((l) => l.id === over.id)
          const lessons = [...s.lessons]
          lessons.splice(idx < 0 ? lessons.length : idx, 0, moving)
          return { ...s, lessons }
        }
        return s
      }),
    )
  }

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over) return
    let next = layout
    const isSection = layout.some((s) => s.id === active.id)
    if (isSection) {
      const target = sectionOf(String(over.id))
      const oldI = layout.findIndex((s) => s.id === active.id)
      const newI = layout.findIndex((s) => s.id === target?.id)
      if (newI >= 0 && oldI !== newI) next = arrayMove(layout, oldI, newI)
    } else {
      const sec = sectionOf(String(active.id))
      if (sec) {
        const oldI = sec.lessons.findIndex((l) => l.id === active.id)
        const newI = sec.lessons.findIndex((l) => l.id === over.id)
        if (newI >= 0 && oldI !== newI)
          next = layout.map((s) =>
            s.id === sec.id ? { ...s, lessons: arrayMove(s.lessons, oldI, newI) } : s,
          )
      }
    }
    setLayout(next)
    reorder.mutate(next, {
      onError: (e) => {
        toast.error(e.message)
        setLayout(course.sections)
      },
    })
  }

  return (
    <div className="grid gap-4">
      <DndContext
        sensors={sensors}
        collisionDetection={closestCorners}
        accessibility={{ announcements }}
        onDragOver={onDragOver}
        onDragEnd={onDragEnd}
      >
        <SortableContext items={layout.map((s) => s.id)} strategy={verticalListSortingStrategy}>
          {layout.map((section, i) => (
            <SortableSection
              key={section.id}
              section={section}
              index={i}
              onRename={(title) => rename.mutate({ id: section.id, title })}
              onDelete={() => {
                if (window.confirm(`Delete “${section.title}” and its lessons?`))
                  delSection.mutate(section.id)
              }}
              onAddLesson={() => addLesson.mutate(section.id)}
              onEditLesson={setEditing}
              onDeleteLesson={(l) => {
                if (window.confirm(`Delete “${l.title}”?`)) delLesson.mutate(l.id)
              }}
            />
          ))}
        </SortableContext>
      </DndContext>
      {layout.length === 0 && (
        <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">
          Add your first section to start building the curriculum.
        </p>
      )}
      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          if (newSection.trim()) addSection.mutate(newSection.trim(), { onSuccess: () => setNewSection('') })
        }}
      >
        <Input
          aria-label="New section title"
          placeholder="New section title"
          value={newSection}
          onChange={(e) => setNewSection(e.target.value)}
          maxLength={160}
        />
        <Button type="submit" variant="outline" loading={addSection.isPending}>
          <Plus aria-hidden /> Add section
        </Button>
      </form>
      {editing && (
        <LessonEditorDialog
          courseId={course.id}
          lesson={course.sections.flatMap((s) => s.lessons).find((l) => l.id === editing.id) ?? editing}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  )
}

function SortableSection(props: {
  section: Layout[number]
  index: number
  onRename: (t: string) => void
  onDelete: () => void
  onAddLesson: () => void
  onEditLesson: (l: StudioLesson) => void
  onDeleteLesson: (l: StudioLesson) => void
}) {
  const { section } = props
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: section.id,
  })
  const [title, setTitle] = useState(section.title)

  return (
    <section
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn('bg-card rounded-xl border', isDragging && 'opacity-60 shadow-lg')}
      aria-label={`Section ${props.index + 1}: ${section.title}`}
    >
      <div className="flex items-center gap-2 border-b p-3">
        <button
          type="button"
          className="text-muted-foreground hover:text-foreground cursor-grab rounded p-1"
          aria-label={`Reorder section ${section.title}`}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" aria-hidden />
        </button>
        <Input
          aria-label="Section title"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          onBlur={() => title.trim() && title !== section.title && props.onRename(title.trim())}
          className="h-9 flex-1 font-semibold"
        />
        <Button
          size="icon"
          variant="ghost"
          aria-label={`Delete section ${section.title}`}
          onClick={props.onDelete}
        >
          <Trash2 aria-hidden />
        </Button>
      </div>
      <SortableContext items={section.lessons.map((l) => l.id)} strategy={verticalListSortingStrategy}>
        <ul className="divide-y">
          {section.lessons.map((l) => (
            <SortableLesson
              key={l.id}
              lesson={l}
              onEdit={() => props.onEditLesson(l)}
              onDelete={() => props.onDeleteLesson(l)}
            />
          ))}
        </ul>
      </SortableContext>
      <div className="p-3">
        <Button size="sm" variant="ghost" onClick={props.onAddLesson}>
          <Plus aria-hidden /> Add lesson
        </Button>
      </div>
    </section>
  )
}

function SortableLesson({
  lesson,
  onEdit,
  onDelete,
}: {
  lesson: StudioLesson
  onEdit: () => void
  onDelete: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: lesson.id,
  })
  const Icon = lesson.type === 'video' ? Video : FileText
  const needsVideo = lesson.type === 'video' && lesson.video?.status !== 'ready'

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn('bg-card flex items-center gap-2 px-3 py-2 text-sm', isDragging && 'opacity-60')}
    >
      <button
        type="button"
        className="text-muted-foreground hover:text-foreground cursor-grab rounded p-1"
        aria-label={`Reorder lesson ${lesson.title}`}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-4" aria-hidden />
      </button>
      <Icon className="text-muted-foreground size-4" aria-hidden />
      <span className="flex-1 truncate">{lesson.title}</span>
      {lesson.is_preview && <Badge variant="secondary">Preview</Badge>}
      {lesson.drip_days > 0 && <Badge variant="outline">Day {lesson.drip_days}</Badge>}
      {needsVideo && (
        <Badge variant="outline" className="text-destructive">
          {lesson.video ? lesson.video.status : 'No video'}
        </Badge>
      )}
      <span className="text-muted-foreground w-12 text-right text-xs tabular-nums">
        {lesson.duration_seconds ? formatClock(lesson.duration_seconds) : ''}
      </span>
      <Button
        size="icon"
        variant="ghost"
        className="size-8"
        aria-label={`Edit lesson ${lesson.title}`}
        onClick={onEdit}
      >
        <Pencil aria-hidden />
      </Button>
      <Button
        size="icon"
        variant="ghost"
        className="size-8"
        aria-label={`Delete lesson ${lesson.title}`}
        onClick={onDelete}
      >
        <Trash2 aria-hidden />
      </Button>
    </li>
  )
}
