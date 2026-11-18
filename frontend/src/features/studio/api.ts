import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type { Paginated } from '@/types/api'
import type { CourseCard, StudioCourse, UploadTicket, VideoAsset } from '@/types/courses'

type CourseInput = Partial<{
  title: string
  subtitle: string | null
  description: string | null
  category_id: string | null
  level: string
  language: string
  required_tier: number | null
  price_amount: number | null
  currency: string | null
  promo_video_id: string | null
  tags: string[]
}>

type LessonInput = Partial<{
  title: string
  type: string
  content: string | null
  video_id: string | null
  is_preview: boolean
  drip_days: number
}>

const one = async (p: Promise<{ data: { data: StudioCourse } }>) => (await p).data.data

export const studioApi = {
  courses: async (all = false) =>
    (await http.get<Paginated<CourseCard>>('/instructor/courses', { params: { all: all ? 1 : undefined } }))
      .data,
  course: (id: string) => getData<StudioCourse>(`/instructor/courses/${id}`),
  create: (body: CourseInput) => one(http.post('/instructor/courses', body)),
  update: (id: string, body: CourseInput) => one(http.patch(`/instructor/courses/${id}`, body)),
  remove: (id: string) => http.delete(`/instructor/courses/${id}`),
  thumbnail: (id: string, file: File) => {
    const fd = new FormData()
    fd.append('thumbnail', file)
    return one(http.post(`/instructor/courses/${id}/thumbnail`, fd))
  },
  submit: (id: string) => one(http.post(`/instructor/courses/${id}/submit`)),
  reorder: (id: string, sections: { id: string; lessons: string[] }[]) =>
    one(http.put(`/instructor/courses/${id}/curriculum`, { sections })),
  addSection: (courseId: string, title: string) =>
    one(http.post(`/instructor/courses/${courseId}/sections`, { title })),
  renameSection: (sectionId: string, title: string) =>
    one(http.patch(`/instructor/sections/${sectionId}`, { title })),
  deleteSection: (sectionId: string) => one(http.delete(`/instructor/sections/${sectionId}`)),
  addLesson: (sectionId: string, body: LessonInput) =>
    one(http.post(`/instructor/sections/${sectionId}/lessons`, body)),
  updateLesson: (lessonId: string, body: LessonInput) =>
    one(http.patch(`/instructor/lessons/${lessonId}`, body)),
  deleteLesson: (lessonId: string) => one(http.delete(`/instructor/lessons/${lessonId}`)),
  uploadAttachment: (lessonId: string, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return one(http.post(`/instructor/lessons/${lessonId}/attachment`, fd))
  },
  addResource: (lessonId: string, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return one(http.post(`/instructor/lessons/${lessonId}/resources`, fd))
  },
  deleteResource: (resourceId: string) => one(http.delete(`/instructor/resources/${resourceId}`)),
  createVideo: async (title: string) =>
    (await http.post<{ data: { video: VideoAsset; upload: UploadTicket } }>('/instructor/videos', { title }))
      .data.data,
  video: (id: string) => getData<VideoAsset>(`/instructor/videos/${id}`),
  videoUploaded: (id: string) => http.post(`/instructor/videos/${id}/uploaded`),
}

export const useStudioCourses = (all = false) =>
  useQuery({ queryKey: ['studio', 'courses', all], queryFn: () => studioApi.courses(all) })

export const useStudioCourse = (id: string) =>
  useQuery({ queryKey: ['studio', 'course', id], queryFn: () => studioApi.course(id) })

/** Every curriculum mutation returns the whole course; write it straight into the cache. */
export function useStudioMutation<TVars>(courseId: string, fn: (v: TVars) => Promise<StudioCourse>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: (course) => qc.setQueryData(['studio', 'course', courseId], course),
  })
}

export const adminCoursesApi = {
  list: async (status?: string) =>
    (await http.get<Paginated<CourseCard>>('/admin/courses', { params: { status: status || undefined } }))
      .data,
  approve: (id: string) => http.post(`/admin/courses/${id}/approve`),
  reject: (id: string, notes: string) => http.post(`/admin/courses/${id}/reject`, { notes }),
  update: (id: string, body: { is_featured?: boolean; unpublish?: boolean }) =>
    http.patch(`/admin/courses/${id}`, body),
}
