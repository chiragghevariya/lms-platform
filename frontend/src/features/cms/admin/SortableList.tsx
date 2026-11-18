import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { GripVertical } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * Drag-and-drop (mouse, touch, keyboard: focus the handle, Space, arrows, Space) list.
 * `onReorder` receives the ids in their new order.
 */
export function SortableList<T extends { id: string }>({
  items,
  label,
  nameOf,
  onReorder,
  render,
}: {
  items: T[]
  label: string
  nameOf: (item: T) => string
  onReorder: (ids: string[]) => void
  render: (item: T) => ReactNode
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const name = (id: string | number) => {
    const it = items.find((i) => i.id === id)
    return it ? `“${nameOf(it)}”` : 'item'
  }
  const position = (id: string | number) => items.findIndex((i) => i.id === id) + 1

  function onDragEnd({ active, over }: DragEndEvent) {
    if (!over || active.id === over.id) return
    const from = items.findIndex((i) => i.id === active.id)
    const to = items.findIndex((i) => i.id === over.id)
    if (from < 0 || to < 0) return
    onReorder(arrayMove(items, from, to).map((i) => i.id))
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={onDragEnd}
      accessibility={{
        announcements: {
          onDragStart: ({ active }) => `Picked up ${name(active.id)}.`,
          onDragOver: ({ active, over }) =>
            over ? `${name(active.id)} moved to position ${position(over.id)}.` : '',
          onDragEnd: ({ active, over }) =>
            over
              ? `${name(active.id)} dropped at position ${position(over.id)}.`
              : `${name(active.id)} dropped.`,
          onDragCancel: ({ active }) => `Moving ${name(active.id)} was cancelled.`,
        },
      }}
    >
      <SortableContext items={items.map((i) => i.id)} strategy={verticalListSortingStrategy}>
        <ul aria-label={label} className="grid gap-2">
          {items.map((item) => (
            <Row key={item.id} id={item.id} name={nameOf(item)}>
              {render(item)}
            </Row>
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  )
}

function Row({ id, name, children }: { id: string; name: string; children: ReactNode }) {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } =
    useSortable({ id })
  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'bg-card flex items-start gap-2 rounded-lg border p-3',
        isDragging && 'relative z-10 opacity-70 shadow-lg',
      )}
    >
      <button
        ref={setActivatorNodeRef}
        type="button"
        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring mt-0.5 cursor-grab rounded p-1 focus-visible:ring-2 focus-visible:outline-none"
        aria-label={`Reorder ${name}`}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-4" aria-hidden />
      </button>
      <div className="min-w-0 flex-1">{children}</div>
    </li>
  )
}
