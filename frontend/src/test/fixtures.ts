import type { User } from '@/types/api'

export const makeUser = (o: Partial<User> = {}): User => ({
  id: '00000000-0000-0000-0000-000000000001',
  name: 'Test User',
  email: 'test@example.com',
  phone: null,
  avatar_url: null,
  country: null,
  timezone: 'UTC',
  bio: null,
  status: 'active',
  email_verified: true,
  phone_verified: false,
  two_factor_enabled: false,
  has_google: false,
  profile: {
    headline: null,
    website: null,
    city: null,
    state: null,
    occupation: null,
    date_of_birth: null,
    social_links: {},
    interests: [],
    expertise: [],
  },
  roles: ['student'],
  is_staff: false,
  affiliate: { active: false, status: null, code: null, activated_at: null },
  membership: { tier: null, tier_rank: 0, ends_at: null, is_lifetime: false, status: null },
  last_login_at: null,
  created_at: null,
  ...o,
})

/** User with an active membership at the given tier. */
export const member = (tier: 'starter' | 'pro' | 'elite', o: Partial<User> = {}): User =>
  makeUser({
    membership: {
      tier,
      tier_rank: { starter: 1, pro: 2, elite: 3 }[tier],
      ends_at: null,
      is_lifetime: false,
      status: 'active',
    },
    ...o,
  })
