import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type { LearnLesson } from '@/types/courses'
import type { MyCourse } from '@/types/learning'

export interface HeartbeatResult {
  position_seconds: number
  watched_seconds: number
  completed: boolean
}

export const learnApi = {
  lesson: (id: string) => getData<LearnLesson>(`/learn/lessons/${id}`),
  progress: async (id: string, body: { position_seconds?: number; completed?: boolean }) =>
    (await http.put<{ data: HeartbeatResult }>(`/learn/lessons/${id}/progress`, body)).data.data,
  /** Sent every `heartbeat_seconds` while the video plays; the server caps credit by real time. */
  heartbeat: async (
    id: string,
    body: { position_seconds: number; watched_seconds: number; playback_rate: number },
  ) => (await http.post<{ data: HeartbeatResult }>(`/learn/lessons/${id}/heartbeat`, body)).data.data,
  addNote: async (id: string, body: { body: string; position_seconds?: number | null }) =>
    (await http.post<{ data: LearnLesson['notes'][number] }>(`/learn/lessons/${id}/notes`, body)).data.data,
  deleteNote: (noteId: string) => http.delete(`/learn/notes/${noteId}`),
  enrollments: () => getData<MyCourse[]>('/learn/enrollments'),
  /** Downloads a protected file through the API (cookies/XSRF), then saves it. */
  download: async (url: string, filename: string) => {
    const res = await http.get<Blob>(url, { responseType: 'blob' })
    const href = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = href
    a.download = filename
    a.click()
    URL.revokeObjectURL(href)
  },
  attachmentBlobUrl: async (lessonId: string) => {
    const res = await http.get<Blob>(`/learn/lessons/${lessonId}/attachment`, { responseType: 'blob' })
    return URL.createObjectURL(res.data)
  },
}

export const useLesson = (id: string) =>
  useQuery({ queryKey: ['learn', 'lesson', id], queryFn: () => learnApi.lesson(id), retry: false })

export const useEnrollments = () =>
  useQuery({ queryKey: ['learn', 'enrollments'], queryFn: learnApi.enrollments })

export function useLessonMutation<TVars, TData>(lessonId: string, fn: (v: TVars) => Promise<TData>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['learn', 'lesson', lessonId] })
    },
  })
}
