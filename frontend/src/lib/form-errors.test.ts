import { ApiError } from './api-client'
import { applyServerErrors } from './form-errors'

const validation = (errors: Record<string, string[]>) => new ApiError(422, { message: 'Invalid', errors })

describe('applyServerErrors', () => {
  it('maps known fields and returns null', () => {
    const setError = vi.fn()
    expect(applyServerErrors(validation({ email: ['Taken'] }), setError, ['email'])).toBeNull()
    expect(setError).toHaveBeenCalledWith('email', { type: 'server', message: 'Taken' })
  })

  it('surfaces errors for fields that are not on the form', () => {
    const setError = vi.fn()
    expect(applyServerErrors(validation({ timezone: ['Bad timezone'] }), setError, ['email'])).toBe(
      'Bad timezone',
    )
    expect(setError).not.toHaveBeenCalled()
  })

  it('returns a friendly message for rate limiting and other errors', () => {
    expect(applyServerErrors(new ApiError(429, { message: 'x' }), vi.fn())).toMatch(/too many attempts/i)
    expect(applyServerErrors(new ApiError(423, { message: 'Locked for 15 minutes' }), vi.fn())).toBe(
      'Locked for 15 minutes',
    )
  })
})
