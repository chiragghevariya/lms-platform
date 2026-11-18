import { makeUser, member } from '@/test/fixtures'
import { canAccessTier, homePathFor } from './membership'

describe('canAccessTier', () => {
  const pro = member('pro')

  it('lets higher tiers inherit lower-tier access', () => {
    expect(canAccessTier(pro, 'starter')).toBe(true)
    expect(canAccessTier(pro, 'pro')).toBe(true)
    expect(canAccessTier(pro, 'elite')).toBe(false)
  })

  it('denies guests and free users, allows staff', () => {
    expect(canAccessTier(null, 'starter')).toBe(false)
    expect(canAccessTier(makeUser(), 'starter')).toBe(false)
    expect(canAccessTier(makeUser({ is_staff: true, roles: ['admin'] }), 'elite')).toBe(true)
  })

  it('treats ungated content as open', () => {
    expect(canAccessTier(null, null)).toBe(true)
  })
})

describe('homePathFor', () => {
  it('routes by role', () => {
    expect(homePathFor(makeUser({ roles: ['super_admin'] }))).toBe('/admin')
    expect(homePathFor(makeUser({ roles: ['instructor'] }))).toBe('/instructor')
    expect(homePathFor(makeUser())).toBe('/app')
  })
})
