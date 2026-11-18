import { z } from 'zod'

const password = z
  .string()
  .min(8, 'At least 8 characters')
  .regex(/[A-Za-z]/, 'Include at least one letter')
  .regex(/[0-9]/, 'Include at least one number')

export const loginSchema = z.object({
  email: z.string().trim().email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean().optional(),
})
export type LoginInput = z.infer<typeof loginSchema>

export const registerSchema = z
  .object({
    name: z.string().trim().min(2, 'Tell us your name').max(120),
    email: z.string().trim().email('Enter a valid email'),
    phone: z
      .string()
      .trim()
      .regex(/^\+?[0-9\s-]{7,20}$/, 'Enter a valid phone number')
      .optional()
      .or(z.literal('')),
    password,
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match',
  })
export type RegisterInput = z.infer<typeof registerSchema>

export const forgotSchema = z.object({ email: z.string().trim().email('Enter a valid email') })
export type ForgotInput = z.infer<typeof forgotSchema>

export const resetSchema = z
  .object({ password, password_confirmation: z.string() })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match',
  })
export type ResetInput = z.infer<typeof resetSchema>
