import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import { makeUser, member } from '@/test/fixtures'
import type { User } from '@/types/api'
import { GuestOnly, ProtectedRoute as RequireAuth, RoleRoute as RequireRole, RequireTier } from './guards'

function renderAt(path: string, user: User | null) {
  useAuthStore.setState({ user, status: user ? 'authenticated' : 'guest' })
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/login" element={<p>login page</p>} />
        <Route path="/app" element={<p>student home</p>} />
        <Route path="/admin" element={<p>admin home</p>} />
        <Route
          path="/secret"
          element={
            <RequireAuth>
              <p>secret</p>
            </RequireAuth>
          }
        />
        <Route
          path="/admin-only"
          element={
            <RequireRole roles={['admin']}>
              <p>admin area</p>
            </RequireRole>
          }
        />
        <Route
          path="/pro"
          element={
            <RequireTier tier="pro">
              <p>pro lesson</p>
            </RequireTier>
          }
        />
        <Route
          path="/guest"
          element={
            <GuestOnly>
              <p>guest form</p>
            </GuestOnly>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('route guards', () => {
  it('redirects guests to login', () => {
    renderAt('/secret', null)
    expect(screen.getByText('login page')).toBeInTheDocument()
  })

  it('sends non-admins to their home', () => {
    renderAt('/admin-only', makeUser())
    expect(screen.getByText('student home')).toBeInTheDocument()
  })

  it('lets super admins through role guards', () => {
    renderAt('/admin-only', makeUser({ roles: ['super_admin'], is_staff: true }))
    expect(screen.getByText('admin area')).toBeInTheDocument()
  })

  it('shows an upgrade prompt below the required tier', () => {
    renderAt('/pro', member('starter'))
    expect(screen.getByText(/pro membership required/i)).toBeInTheDocument()
  })

  it('renders tier content at or above the tier', () => {
    renderAt('/pro', member('elite'))
    expect(screen.getByText('pro lesson')).toBeInTheDocument()
  })

  it('bounces signed-in users away from guest pages', () => {
    renderAt('/guest', makeUser({ roles: ['admin'], is_staff: true }))
    expect(screen.getByText('admin home')).toBeInTheDocument()
  })
})
