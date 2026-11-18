import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError } from '@/lib/api-client'

/**
 * Maps Laravel 422 errors onto react-hook-form fields and returns a top-level message for
 * anything that can't be shown on a field: non-validation errors, or errors for fields not
 * listed in `knownFields` (hidden/derived values such as `timezone`).
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  knownFields?: readonly string[],
): string | null {
  if (error instanceof ApiError && error.isValidation) {
    const unmapped: string[] = []
    for (const [field, messages] of Object.entries(error.errors)) {
      const root = field.split('.')[0] ?? field
      if (knownFields && !knownFields.includes(root)) {
        unmapped.push(messages[0] ?? error.message)
        continue
      }
      setError(field as Path<T>, { type: 'server', message: messages[0] })
    }
    if (unmapped.length) return unmapped.join(' ')
    return Object.keys(error.errors).length ? null : error.message
  }
  if (error instanceof ApiError && error.status === 429)
    return 'Too many attempts. Please wait a minute and try again.'
  return error instanceof Error ? error.message : 'Something went wrong.'
}
