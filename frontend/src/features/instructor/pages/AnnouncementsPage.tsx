import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Megaphone } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { RichTextEditor } from '@/components/RichTextEditor'
import { RichTextView } from '@/components/RichTextView'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { formatDate } from '@/lib/format'
import { teachingApi } from '../api'
import { CoursePicker } from '../components/CoursePicker'

export default function AnnouncementsPage() {
  const qc = useQueryClient()
  const [course, setCourse] = useState('')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [sending, setSending] = useState(false)
  const { data } = useQuery({
    queryKey: ['instructor', 'announcements', course],
    queryFn: () => teachingApi.announcements(course),
    enabled: !!course,
  })

  const send = async () => {
    setSending(true)
    try {
      const a = await teachingApi.announce(course, { title, body })
      toast.success(`Sent to ${a.recipients_count} student${a.recipients_count === 1 ? '' : 's'}`)
      setTitle('')
      setBody('')
      void qc.invalidateQueries({ queryKey: ['instructor', 'announcements', course] })
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not send')
    } finally {
      setSending(false)
    }
  }

  return (
    <>
      <Seo title="Announcements" noindex />
      <PageHeader
        title="Announcements"
        description="Email everyone enrolled in a course. Announcements also appear on the course page."
      />
      <div className="mb-4">
        <CoursePicker value={course} onChange={setCourse} />
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">New announcement</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault()
                void send()
              }}
            >
              <label htmlFor="an-title" className="text-sm font-medium">
                Title
              </label>
              <Input
                id="an-title"
                required
                maxLength={200}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
              />
              <span id="an-body-label" className="text-sm font-medium">
                Message
              </span>
              <RichTextEditor
                value={body}
                onChange={setBody}
                aria-labelledby="an-body-label"
                placeholder="What's new for your students?"
              />
              <Button
                type="submit"
                loading={sending}
                disabled={!course || !title.trim() || !body.replace(/<[^>]*>/g, '').trim()}
                className="justify-self-start"
              >
                <Megaphone aria-hidden /> Send to enrolled students
              </Button>
            </form>
          </CardContent>
        </Card>
        <section aria-labelledby="sent-h" className="grid content-start gap-3">
          <h2 id="sent-h" className="font-semibold">
            Sent
          </h2>
          {data?.length === 0 && (
            <p className="text-muted-foreground text-sm">No announcements for this course yet.</p>
          )}
          {data?.map((a) => (
            <Card key={a.id}>
              <CardContent className="grid gap-2 p-4">
                <p className="font-medium">{a.title}</p>
                <p className="text-muted-foreground text-xs">
                  {formatDate(a.created_at)} · {a.recipients_count} recipient
                  {a.recipients_count === 1 ? '' : 's'}
                </p>
                <RichTextView html={a.body} />
              </CardContent>
            </Card>
          ))}
        </section>
      </div>
    </>
  )
}
