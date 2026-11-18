import { useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import { homePathFor } from '@/lib/membership'
import { useRefreshSession } from '../hooks'

/** Landing route after Google OAuth: the backend already started the session. */
export default function AuthCallbackPage() {
  const refresh = useRefreshSession()
  const navigate = useNavigate()
  const ran = useRef(false)

  useEffect(() => {
    if (ran.current) return
    ran.current = true
    void refresh().then((user) =>
      navigate(user ? homePathFor(user) : '/login?error=google_failed', { replace: true }),
    )
  }, [refresh, navigate])

  return <FullPageSpinner label="Signing you in" />
}
