import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { makeUser } from '@/test/fixtures'
import type { AuthPayload } from '@/types/api'
import { authApi } from '../api'
import LoginPage from './LoginPage'

vi.mock('../api', () => ({
  authApi: { login: vi.fn(), googleRedirectUrl: () => '/auth/google/redirect' },
}))

function renderLogin(initial = '/login') {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
  return render(
    <HelmetProvider>
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[initial]}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/admin" element={<p>admin dashboard</p>} />
            <Route path="/instructor" element={<p>instructor dashboard</p>} />
            <Route path="/app" element={<p>student dashboard</p>} />
            <Route path="/two-factor" element={<p>two factor page</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )
}

async function submit() {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Email'), 'x@example.com')
  await user.type(screen.getByLabelText('Password'), 'password1')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
}

const loggedIn = (roles: ('admin' | 'instructor' | 'student')[]): AuthPayload => ({
  two_factor_required: false,
  challenge_token: null,
  user: makeUser({ roles, is_staff: roles.includes('admin') }),
  token: null,
  token_type: null,
})

describe('LoginPage', () => {
  it.each([
    [['admin'] as const, 'admin dashboard'],
    [['instructor'] as const, 'instructor dashboard'],
    [['student'] as const, 'student dashboard'],
  ])('redirects %s to the right dashboard', async (roles, landing) => {
    vi.mocked(authApi.login).mockResolvedValueOnce(loggedIn([...roles]))
    renderLogin()
    await submit()
    await waitFor(() => expect(screen.getByText(landing)).toBeInTheDocument())
  })

  it('hands off to the 2FA challenge when required', async () => {
    vi.mocked(authApi.login).mockResolvedValueOnce({
      two_factor_required: true,
      challenge_token: 'c'.repeat(64),
      user: null,
      token: null,
      token_type: null,
    })
    renderLogin()
    await submit()
    await waitFor(() => expect(screen.getByText('two factor page')).toBeInTheDocument())
  })

  it('shows Google OAuth errors from the redirect', () => {
    renderLogin('/login?error=google_email_unverified')
    expect(screen.getByRole('alert')).toHaveTextContent(/google account email is not verified/i)
    expect(screen.getByRole('link', { name: /continue with google/i })).toHaveAttribute(
      'href',
      '/auth/google/redirect',
    )
  })
})
