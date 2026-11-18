import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { deviceId, http } from '@/lib/api-client'

const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as const

/**
 * Any page opened with ?ref=CODE (affiliate) or ?invite=CODE (friend) records the visit; the API sets a
 * signed 60-day cookie that's read when the visitor signs up. Each code is sent once per tab session.
 */
export function ReferralCapture() {
  const { search, pathname } = useLocation()

  useEffect(() => {
    const params = new URLSearchParams(search)
    const ref = params.get('ref')
    const invite = params.get('invite')
    if (!ref && !invite) return
    const key = `lms_tracked_${ref ?? ''}_${invite ?? ''}`
    try {
      if (sessionStorage.getItem(key)) return
      sessionStorage.setItem(key, '1')
    } catch {
      /* private mode: track anyway */
    }
    const utm = Object.fromEntries(UTM.flatMap((k) => (params.get(k) ? [[k, params.get(k)!]] : [])))
    void http
      .post('/referrals/track', {
        ref: ref ?? undefined,
        invite: invite ?? undefined,
        landing_url: pathname + search,
        referrer: document.referrer || undefined,
        utm,
        visitor_id: deviceId() ?? undefined,
      })
      .catch(() => {
        /* tracking must never break the page */
      })
  }, [search, pathname])

  return null
}
