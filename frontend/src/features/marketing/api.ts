import { useMutation, useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type { Instructor } from '@/types/api'

export interface ContactInput {
  name: string
  email: string
  phone?: string
  subject: string
  message: string
  website?: string // honeypot
}

export const marketingApi = {
  instructors: (featured = true) =>
    getData<Instructor[]>('/instructors', { featured: featured ? 1 : undefined, limit: 6 }),
  contact: async (input: ContactInput) => (await http.post<{ message: string }>('/contact', input)).data,
}

export const useFeaturedInstructors = () =>
  useQuery({
    queryKey: ['instructors', 'featured'],
    queryFn: () => marketingApi.instructors(true),
    staleTime: 10 * 60_000,
  })

export const useContact = () => useMutation({ mutationFn: marketingApi.contact })
