import { create } from 'zustand'

/** Cookie consent for non-essential cookies (GDPR / DPDP). Essential cookies (session, CSRF) need none. */
export interface Consent {
  analytics: boolean // GA4
  marketing: boolean // Meta Pixel
  decidedAt: string
}

const KEY = 'lms_consent_v1'

function load(): Consent | null {
  try {
    const raw = localStorage.getItem(KEY)
    return raw ? (JSON.parse(raw) as Consent) : null
  } catch {
    return null
  }
}

interface ConsentState {
  consent: Consent | null
  /** Re-opens the banner (footer "Cookie settings"). */
  open: boolean
  decide: (c: Omit<Consent, 'decidedAt'>) => void
  reopen: () => void
}

export const useConsentStore = create<ConsentState>((set) => ({
  consent: load(),
  open: false,
  decide: (c) => {
    const consent = { ...c, decidedAt: new Date().toISOString() }
    try {
      localStorage.setItem(KEY, JSON.stringify(consent))
    } catch {
      /* private mode: remember for this tab only */
    }
    set({ consent, open: false })
  },
  reopen: () => set({ open: true }),
}))
