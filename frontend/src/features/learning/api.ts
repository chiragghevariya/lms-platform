import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  Achievements,
  AnswerMap,
  AssignmentDetail,
  AssignmentSettings,
  BookmarkItem,
  Certificate,
  CertificateVerification,
  Dashboard,
  GradingItem,
  LearningProfile,
  NoteItem,
  PublicLearner,
  QuizAttempt,
  QuizSummary,
  StudioQuiz,
  StudioQuestion,
  Submission,
} from '@/types/learning'

const data = async <T>(p: Promise<{ data: { data: T } }>) => (await p).data.data

export const learningApi = {
  dashboard: () => getData<Dashboard>('/me/dashboard'),
  achievements: () => getData<Achievements>('/me/achievements'),
  certificates: () => getData<Certificate[]>('/me/certificates'),
  verify: (code: string) =>
    getData<CertificateVerification>(`/certificates/verify/${encodeURIComponent(code)}`),
  bookmarks: () => getData<BookmarkItem[]>('/me/bookmarks'),
  addBookmark: (body: { course_id?: string; lesson_id?: string; position_seconds?: number | null }) =>
    data<{ id: string }>(http.post('/me/bookmarks', body)),
  removeBookmark: (target: { course_id?: string; lesson_id?: string }) =>
    http.delete('/me/bookmarks', { data: target }),
  deleteBookmark: (id: string) => http.delete(`/me/bookmarks/${id}`),
  notes: (params: { q?: string; course?: string }) => getData<NoteItem[]>('/me/notes', params),
  learningProfile: () => getData<LearningProfile>('/me/learning-profile'),
  updateLearningProfile: (body: Partial<LearningProfile>) =>
    data<LearningProfile>(http.patch('/me/learning-profile', body)),
  publicProfile: (handle: string) => getData<PublicLearner>(`/learners/${encodeURIComponent(handle)}`),

  quiz: (lessonId: string) => getData<QuizSummary>(`/learn/lessons/${lessonId}/quiz`),
  startQuiz: (lessonId: string) => data<QuizAttempt>(http.post(`/learn/lessons/${lessonId}/quiz/attempts`)),
  attempt: (id: string) => getData<QuizAttempt>(`/learn/quiz-attempts/${id}`),
  saveAnswers: (id: string, answers: AnswerMap) =>
    http.put(`/learn/quiz-attempts/${id}/answers`, { answers }),
  submitQuiz: (id: string, answers: AnswerMap) =>
    data<QuizAttempt>(http.post(`/learn/quiz-attempts/${id}/submit`, { answers })),

  assignment: (lessonId: string) => getData<AssignmentDetail>(`/learn/lessons/${lessonId}/assignment`),
  submitAssignment: (lessonId: string, body: { text: string; files: File[] }) => {
    const fd = new FormData()
    if (body.text.trim()) fd.append('text', body.text)
    body.files.forEach((f) => fd.append('files[]', f))
    return data<Submission>(http.post(`/learn/lessons/${lessonId}/assignment/submission`, fd))
  },
}

export const instructorAssessmentApi = {
  quiz: (lessonId: string) => getData<StudioQuiz | null>(`/instructor/lessons/${lessonId}/quiz`),
  saveQuiz: (
    lessonId: string,
    body: Omit<StudioQuiz, 'id' | 'questions'> & { questions: StudioQuestion[] },
  ) => data<StudioQuiz>(http.put(`/instructor/lessons/${lessonId}/quiz`, body)),
  assignment: (lessonId: string) =>
    getData<AssignmentSettings | null>(`/instructor/lessons/${lessonId}/assignment`),
  saveAssignment: (lessonId: string, body: AssignmentSettings) =>
    data<AssignmentSettings>(http.put(`/instructor/lessons/${lessonId}/assignment`, body)),
  submissions: (status: string) =>
    http
      .get<{ data: GradingItem[]; meta: { total: number } }>('/instructor/submissions', {
        params: { status },
      })
      .then((r) => r.data),
  grade: (id: string, body: { status: 'graded' | 'returned'; score: number | null; feedback: string }) =>
    data<Submission>(http.patch(`/instructor/submissions/${id}`, body)),
}

export const useDashboard = () => useQuery({ queryKey: ['me', 'dashboard'], queryFn: learningApi.dashboard })
export const useAchievements = () =>
  useQuery({ queryKey: ['me', 'achievements'], queryFn: learningApi.achievements })
export const useCertificates = () =>
  useQuery({ queryKey: ['me', 'certificates'], queryFn: learningApi.certificates })
export const useBookmarks = () => useQuery({ queryKey: ['me', 'bookmarks'], queryFn: learningApi.bookmarks })
export const useNotes = (q: string) =>
  useQuery({ queryKey: ['me', 'notes', q], queryFn: () => learningApi.notes(q ? { q } : {}) })
export const useLearningProfile = () =>
  useQuery({ queryKey: ['me', 'learning-profile'], queryFn: learningApi.learningProfile })

/** Toggles a course or lesson bookmark and refreshes everything that shows bookmark state. */
export function useBookmarkToggle() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({
      on,
      ...target
    }: {
      on: boolean
      course_id?: string
      lesson_id?: string
      position_seconds?: number
    }): Promise<void> => {
      if (on) await learningApi.addBookmark(target)
      else await learningApi.removeBookmark(target)
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['me', 'bookmarks'] })
      void qc.invalidateQueries({ queryKey: ['courses', 'detail'] })
      void qc.invalidateQueries({ queryKey: ['learn', 'lesson'] })
    },
  })
}

/** Saves a certificate PDF through the API (session cookie), then triggers a download. */
export async function downloadCertificate(c: Certificate) {
  const res = await http.get<Blob>(`/me/certificates/${c.id}/pdf`, { responseType: 'blob' })
  const href = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = href
  a.download = `certificate-${c.verification_code}.pdf`
  a.click()
  URL.revokeObjectURL(href)
}
