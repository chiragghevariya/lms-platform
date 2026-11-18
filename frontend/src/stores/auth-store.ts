import { create } from 'zustand'
import type { User } from '@/types/api'

type Status = 'idle' | 'loading' | 'authenticated' | 'guest'

interface AuthState {
  user: User | null
  status: Status
  setUser: (user: User | null) => void
  setStatus: (status: Status) => void
}

/** Session is cookie-based, so only the hydrated user lives here (not persisted). */
export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  status: 'idle',
  setUser: (user) => set({ user, status: user ? 'authenticated' : 'guest' }),
  setStatus: (status) => set({ status }),
}))
