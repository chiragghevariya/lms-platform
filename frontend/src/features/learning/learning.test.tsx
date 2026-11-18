import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ApiError } from '@/lib/api-client'
import type { QuizAttempt, QuizSummary } from '@/types/learning'
import { learningApi } from './api'
import { AssignmentPanel } from './components/AssignmentPanel'
import { QuizPlayer } from './components/QuizPlayer'
import VerifyCertificatePage from './pages/VerifyCertificatePage'

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

const wrap = (node: React.ReactNode, path = '/') =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const summary = (o: Partial<QuizSummary> = {}): QuizSummary => ({
  id: 'qz',
  question_count: 2,
  pass_mark: 50,
  time_limit_seconds: 120,
  attempts_allowed: 2,
  attempts_used: 0,
  best_percent: null,
  passed: false,
  open_attempt_id: null,
  attempts: [],
  ...o,
})

const attempt = (o: Partial<QuizAttempt> = {}): QuizAttempt => ({
  id: 'att',
  status: 'in_progress',
  started_at: '2026-11-12T10:00:00Z',
  expires_at: '2026-11-12T10:02:00Z',
  seconds_left: 120,
  score: null,
  max_score: 3,
  percent: null,
  passed: null,
  pass_mark: 50,
  questions: [
    {
      id: 'q1',
      type: 'mcq_single',
      prompt: 'Capital of India?',
      points: 2,
      options: [
        { id: 'o1', text: 'Mumbai' },
        { id: 'o2', text: 'New Delhi' },
      ],
      answer: { option_ids: [], text: null },
      is_correct: null,
      points_awarded: null,
      explanation: null,
      accepted_answers: null,
    },
    {
      id: 'q2',
      type: 'short_text',
      prompt: 'HTML stands for HyperText ___ Language',
      points: 1,
      options: [],
      answer: { option_ids: [], text: null },
      is_correct: null,
      points_awarded: null,
      explanation: null,
      accepted_answers: null,
    },
  ],
  ...o,
})

describe('quiz player', () => {
  it('runs an attempt and shows the graded review with explanations', async () => {
    vi.spyOn(learningApi, 'quiz').mockResolvedValue(summary())
    vi.spyOn(learningApi, 'startQuiz').mockResolvedValue(attempt())
    const submit = vi.spyOn(learningApi, 'submitQuiz').mockResolvedValue(
      attempt({
        status: 'submitted',
        seconds_left: null,
        score: 2,
        percent: 67,
        passed: true,
        questions: attempt().questions.map((q, i) => ({
          ...q,
          is_correct: i === 0,
          points_awarded: i === 0 ? 2 : 0,
          explanation: i === 0 ? 'New Delhi is the capital.' : null,
          options: q.options.map((o) => ({ ...o, is_correct: o.id === 'o2' })),
          accepted_answers: i === 1 ? ['Markup'] : null,
          answer: i === 0 ? { option_ids: ['o2'], text: null } : { option_ids: [], text: 'Machine' },
        })),
      }),
    )
    const onPassed = vi.fn()
    wrap(<QuizPlayer lessonId="l1" onPassed={onPassed} />)

    expect(
      await screen.findByText(/2 questions · pass mark 50% · 2 min time limit · 2 attempts left/),
    ).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Start quiz' }))

    expect(await screen.findByRole('timer')).toHaveTextContent('2:00')
    fireEvent.click(screen.getByLabelText('New Delhi'))
    fireEvent.change(screen.getByLabelText('Answer to question 2'), { target: { value: 'Machine' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit quiz' }))

    expect(await screen.findByText('67%')).toBeInTheDocument()
    expect(submit).toHaveBeenCalledWith('att', { q1: { option_ids: ['o2'] }, q2: { text: 'Machine' } })
    expect(screen.getByText('New Delhi is the capital.')).toBeInTheDocument()
    expect(screen.getByText('Accepted: Markup')).toBeInTheDocument()
    expect(onPassed).toHaveBeenCalled()
  })

  it('asks for confirmation before submitting with unanswered questions', async () => {
    vi.spyOn(learningApi, 'quiz').mockResolvedValue(summary())
    vi.spyOn(learningApi, 'startQuiz').mockResolvedValue(attempt())
    const submit = vi.spyOn(learningApi, 'submitQuiz')
    wrap(<QuizPlayer lessonId="l1" />)

    fireEvent.click(await screen.findByRole('button', { name: 'Start quiz' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Submit quiz' }))

    expect(screen.getByText(/2 unanswered question/)).toBeInTheDocument()
    expect(submit).not.toHaveBeenCalled()
  })

  it('auto-submits when the timer reaches zero', async () => {
    vi.spyOn(learningApi, 'quiz').mockResolvedValue(summary())
    vi.spyOn(learningApi, 'startQuiz').mockResolvedValue(attempt({ seconds_left: 2 }))
    const submit = vi
      .spyOn(learningApi, 'submitQuiz')
      .mockResolvedValue(
        attempt({ status: 'expired', score: 0, percent: 0, passed: false, seconds_left: null }),
      )
    wrap(<QuizPlayer lessonId="l1" />)

    fireEvent.click(await screen.findByRole('button', { name: 'Start quiz' }))
    await screen.findByRole('timer')
    await waitFor(() => expect(submit).toHaveBeenCalledWith('att', expect.any(Object)), { timeout: 4000 })
  })

  it('offers no new attempt once attempts are used up', async () => {
    vi.spyOn(learningApi, 'quiz').mockResolvedValue(summary({ attempts_used: 2 }))
    wrap(<QuizPlayer lessonId="l1" />)

    expect(await screen.findByText(/used all attempts/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /start|retake/i })).not.toBeInTheDocument()
  })
})

describe('assignment panel', () => {
  const detail = {
    id: 'as',
    instructions: '<p>Build a landing page</p>',
    due_at: '2099-01-01T00:00:00Z',
    max_score: 100,
    pass_score: 60,
    file_types: ['pdf'],
    max_file_mb: 1,
    allow_text: true,
    submission: null,
  }

  it('submits text and shows server validation errors', async () => {
    vi.spyOn(learningApi, 'assignment').mockResolvedValue(detail)
    vi.spyOn(learningApi, 'submitAssignment').mockRejectedValueOnce(
      new ApiError(422, {
        message: 'Invalid',
        errors: { submission: ['This assignment has already been graded.'] },
      }),
    )
    wrap(<AssignmentPanel lessonId="l1" />)

    expect(await screen.findByText('Build a landing page')).toBeInTheDocument()
    const submitBtn = screen.getByRole('button', { name: 'Submit' })
    expect(submitBtn).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/answer \/ notes/i), { target: { value: 'my work' } })
    fireEvent.click(submitBtn)

    expect(await screen.findByText('This assignment has already been graded.')).toBeInTheDocument()
  })

  it('shows the grade and feedback, and hides the form once graded', async () => {
    vi.spyOn(learningApi, 'assignment').mockResolvedValue({
      ...detail,
      submission: {
        id: 's1',
        status: 'graded',
        text: 'my work',
        files: [],
        score: 80,
        feedback: 'Nice work',
        graded_at: '2026-11-12T10:00:00Z',
        submitted_at: '2026-11-11T10:00:00Z',
        revision: 1,
      },
    })
    wrap(<AssignmentPanel lessonId="l1" />)

    expect(await screen.findByText('Score: 80 / 100')).toBeInTheDocument()
    expect(screen.getByText('Nice work')).toBeInTheDocument()
    expect(screen.getByText('Graded')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /submit/i })).not.toBeInTheDocument()
  })

  it('rejects files over the size limit on the client', async () => {
    vi.spyOn(learningApi, 'assignment').mockResolvedValue(detail)
    wrap(<AssignmentPanel lessonId="l1" />)
    const input = (await screen.findByText(/attach files/i)).querySelector('input')!
    const big = new File([new Uint8Array(2 * 1024 * 1024)], 'big.pdf', { type: 'application/pdf' })
    const ok = new File(['x'], 'ok.pdf', { type: 'application/pdf' })
    act(() => {
      fireEvent.change(input, { target: { files: [big, ok] } })
    })

    expect(screen.getByText('ok.pdf')).toBeInTheDocument()
    expect(screen.queryByText('big.pdf')).not.toBeInTheDocument()
  })
})

describe('certificate verification page', () => {
  const route = <Route path="/verify/:code" element={<VerifyCertificatePage />} />

  it('shows a verified certificate', async () => {
    vi.spyOn(learningApi, 'verify').mockResolvedValue({
      valid: true,
      verification_code: 'ABCDE23456',
      holder_name: 'Asha Learner',
      course_title: 'Meta Ads Mastery',
      course_slug: 'meta-ads',
      instructor_name: 'Meera',
      issuer: 'Brand',
      issued_at: '2026-11-12T10:00:00Z',
      verify_url: 'http://x/verify/ABCDE23456',
    })
    wrap(<Routes>{route}</Routes>, '/verify/ABCDE23456')

    expect(await screen.findByText('Verified certificate')).toBeInTheDocument()
    expect(screen.getByText('Asha Learner')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Meta Ads Mastery' })).toHaveAttribute(
      'href',
      '/courses/meta-ads',
    )
  })

  it('reports unknown codes', async () => {
    vi.spyOn(learningApi, 'verify').mockRejectedValue(new ApiError(404, { message: 'Not found' }))
    wrap(<Routes>{route}</Routes>, '/verify/nope')

    expect(await screen.findByText('No certificate found')).toBeInTheDocument()
  })
})
