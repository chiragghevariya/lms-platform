import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatMinutes } from '@/lib/format'
import { studioApi, useStudioCourses } from '../api'
import { STATUS_BADGE } from '../components/StatusBadge'

export default function StudioCoursesPage() {
  const { data, isLoading } = useStudioCourses()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [title, setTitle] = useState('')
  const [creating, setCreating] = useState(false)

  return (
    <>
      <Seo title="My courses" noindex />
      <PageHeader
        title="Courses"
        description="Build, submit and manage your courses."
        actions={
          <Button onClick={() => setOpen(true)}>
            <Plus aria-hidden /> New course
          </Button>
        }
      />
      <Card className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-3 font-medium">
                Course
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Status
              </th>
              <th scope="col" className="hidden px-4 py-3 font-medium sm:table-cell">
                Lessons
              </th>
              <th scope="col" className="hidden px-4 py-3 font-medium sm:table-cell">
                Length
              </th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={4} className="p-4">
                  <Skeleton className="h-16" />
                </td>
              </tr>
            )}
            {data?.data.map((c) => (
              <tr key={c.id}>
                <td className="px-4 py-3">
                  <Link to={`/instructor/courses/${c.id}/edit`} className="font-medium hover:underline">
                    {c.title}
                  </Link>
                </td>
                <td className="px-4 py-3">{STATUS_BADGE[c.status]}</td>
                <td className="hidden px-4 py-3 sm:table-cell">{c.lessons_count}</td>
                <td className="hidden px-4 py-3 sm:table-cell">{formatMinutes(c.duration_minutes)}</td>
              </tr>
            ))}
            {data?.data.length === 0 && (
              <tr>
                <td colSpan={4} className="text-muted-foreground p-8 text-center">
                  No courses yet — create your first one.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New course</DialogTitle>
          </DialogHeader>
          <form
            className="grid gap-4"
            onSubmit={async (e) => {
              e.preventDefault()
              setCreating(true)
              try {
                const c = await studioApi.create({ title })
                navigate(`/instructor/courses/${c.id}/edit`)
              } catch (err) {
                toast.error(err instanceof Error ? err.message : 'Could not create the course')
              } finally {
                setCreating(false)
              }
            }}
          >
            <FormField id="nc-title" label="Working title" hint="You can change it later.">
              {(a) => (
                <Input
                  {...a}
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  minLength={5}
                  maxLength={160}
                  required
                />
              )}
            </FormField>
            <DialogFooter>
              <Button type="submit" loading={creating} disabled={title.trim().length < 5}>
                Create draft
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}
