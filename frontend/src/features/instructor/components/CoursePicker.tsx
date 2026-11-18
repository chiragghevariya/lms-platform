import { useEffect } from 'react'
import { useStudioCourses } from '@/features/studio/api'

/** Select one of my courses; picks the first automatically. */
export function CoursePicker({
  value,
  onChange,
  id = 'course-picker',
}: {
  value: string
  onChange: (id: string) => void
  id?: string
}) {
  const { data } = useStudioCourses()
  const courses = data?.data ?? []
  const first = courses[0]?.id
  useEffect(() => {
    if (!value && first) onChange(first)
  }, [value, first, onChange])

  return (
    <div className="flex items-center gap-2 text-sm">
      <label htmlFor={id}>Course</label>
      <select
        id={id}
        className="border-input bg-background h-9 max-w-xs rounded-md border px-2"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      >
        {courses.length === 0 && <option value="">No courses yet</option>}
        {courses.map((c) => (
          <option key={c.id} value={c.id}>
            {c.title}
          </option>
        ))}
      </select>
    </div>
  )
}
