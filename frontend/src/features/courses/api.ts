import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type { CheckoutResult, Paginated } from '@/types/api'
import type { Category, CourseCard, CourseDetail, Playback, Review } from '@/types/courses'

export interface CatalogFilters {
  q?: string
  category?: string
  level?: string[]
  tier?: string[]
  duration?: string[]
  rating?: string
  sort?: string
  page?: number
  per_page?: number
  featured?: boolean
}

export const coursesApi = {
  categories: () => getData<Category[]>('/categories'),
  catalog: async (f: CatalogFilters) =>
    (
      await http.get<Paginated<CourseCard>>('/courses', {
        params: { ...f, featured: f.featured ? 1 : undefined },
      })
    ).data, // Laravel's boolean rule rejects "true"
  detail: (slug: string) => getData<CourseDetail>(`/courses/${slug}`),
  reviews: async (slug: string, page = 1) =>
    (await http.get<Paginated<Review>>(`/courses/${slug}/reviews`, { params: { page } })).data,
  playback: (lessonId: string) => getData<Playback>(`/lessons/${lessonId}/playback`),
  promo: (slug: string) => getData<Playback>(`/courses/${slug}/promo`),
  purchase: async (slug: string) =>
    (await http.post<{ data: CheckoutResult }>(`/courses/${slug}/purchase`)).data.data,
  review: async (slug: string, body: { rating: number; comment?: string | null }) =>
    (await http.post<{ data: Review }>(`/courses/${slug}/reviews`, body)).data.data,
}

export const useCategories = () =>
  useQuery({ queryKey: ['categories'], queryFn: coursesApi.categories, staleTime: 10 * 60_000 })

export const useCatalog = (f: CatalogFilters) =>
  useQuery({
    queryKey: ['courses', 'catalog', f],
    queryFn: () => coursesApi.catalog(f),
    placeholderData: keepPreviousData,
  })

export const useCourse = (slug: string) =>
  useQuery({ queryKey: ['courses', 'detail', slug], queryFn: () => coursesApi.detail(slug) })

/**
 * Hover/focus intent on a course link: warm the page chunk and its data so the click feels
 * instant. Each runs once per slug (TanStack dedupes; the import is cached by the bundler).
 */
export function prefetchCourse(qc: QueryClient, slug: string) {
  void import('@/features/courses/pages/CourseDetailPage')
  void qc.prefetchQuery({
    queryKey: ['courses', 'detail', slug],
    queryFn: () => coursesApi.detail(slug),
    staleTime: 60_000,
  })
}

export const useReviews = (slug: string) =>
  useQuery({ queryKey: ['courses', 'reviews', slug], queryFn: () => coursesApi.reviews(slug) })

export function useSubmitReview(slug: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: { rating: number; comment?: string | null }) => coursesApi.review(slug, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['courses'] }),
  })
}
