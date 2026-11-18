import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  AdminPage,
  Banner,
  CmsPageContent,
  CmsSection,
  Faq,
  HomeContent,
  PageMeta,
  Post,
  SectionContent,
  SectionKey,
  SettingsGroup,
  SettingsResponse,
  SettingsValues,
  SiteInfo,
  Testimonial,
} from './types'

export type ItemType = 'faqs' | 'testimonials' | 'banners'
type ItemOf<T extends ItemType> = T extends 'faqs' ? Faq : T extends 'testimonials' ? Testimonial : Banner

export const cmsApi = {
  // public
  site: () => getData<SiteInfo>('/site'),
  home: () => getData<HomeContent>('/cms/home'),
  page: (slug: string) => getData<CmsPageContent>(`/cms/pages/${encodeURIComponent(slug)}`),
  posts: async (page: number) =>
    (await http.get<{ data: Post[]; meta: PageMeta }>('/blog', { params: { page } })).data,
  post: (slug: string) => getData<Post>(`/blog/${encodeURIComponent(slug)}`),

  // admin
  sections: () => getData<CmsSection[]>('/admin/cms/sections'),
  updateSection: async (
    key: SectionKey,
    body: { content?: SectionContent; is_visible?: boolean; sort?: number },
  ) => (await http.put<{ data: CmsSection }>(`/admin/cms/sections/${key}`, body)).data.data,

  items: <T extends ItemType>(type: T) => getData<ItemOf<T>[]>(`/admin/cms/${type}`),
  createItem: async <T extends ItemType>(type: T, body: Partial<ItemOf<T>>) =>
    (await http.post<{ data: ItemOf<T> }>(`/admin/cms/${type}`, body)).data.data,
  updateItem: async <T extends ItemType>(type: T, id: string, body: Partial<ItemOf<T>>) =>
    (await http.patch<{ data: ItemOf<T> }>(`/admin/cms/${type}/${id}`, body)).data.data,
  deleteItem: async (type: ItemType, id: string) => {
    await http.delete(`/admin/cms/${type}/${id}`)
  },
  reorder: async <T extends 'faqs' | 'testimonials'>(type: T, ids: string[]) =>
    (await http.put<{ data: ItemOf<T>[] }>(`/admin/cms/${type}/order`, { ids })).data.data,

  pages: () => getData<AdminPage[]>('/admin/cms/pages'),
  savePage: async (slug: string, body: { title: string; body: string; is_published: boolean }) =>
    (await http.put<{ data: AdminPage }>(`/admin/cms/pages/${slug}`, body)).data.data,

  adminPosts: async (params: { status?: string; search?: string; page?: number }) =>
    (await http.get<{ data: Post[]; meta: PageMeta }>('/admin/cms/posts', { params })).data,
  adminPost: (id: string) => getData<Post>(`/admin/cms/posts/${id}`),
  createPost: async (body: Partial<Post>) =>
    (await http.post<{ data: Post }>('/admin/cms/posts', body)).data.data,
  updatePost: async (id: string, body: Partial<Post>) =>
    (await http.patch<{ data: Post }>(`/admin/cms/posts/${id}`, body)).data.data,
  deletePost: async (id: string) => {
    await http.delete(`/admin/cms/posts/${id}`)
  },

  settings: () => getData<SettingsResponse>('/admin/settings'),
  saveSettings: async <G extends SettingsGroup>(group: G, body: Record<string, unknown>) =>
    (
      await http.put<{ data: SettingsValues[G]; meta: { changed: string[] } }>(
        `/admin/settings/${group}`,
        body,
      )
    ).data,
}

/** Public site settings + banner; cached for the session, refetched on login changes by key. */
export const useSite = () =>
  useQuery({ queryKey: ['site'], queryFn: cmsApi.site, staleTime: 5 * 60_000, retry: false })

export const useHome = () =>
  useQuery({ queryKey: ['cms', 'home'], queryFn: cmsApi.home, staleTime: 60_000, retry: false })

export const useCmsPage = (slug: string) =>
  useQuery({
    queryKey: ['cms', 'page', slug],
    queryFn: () => cmsApi.page(slug),
    enabled: slug !== '',
    staleTime: 5 * 60_000,
    retry: false,
  })

export const usePosts = (page: number) =>
  useQuery({ queryKey: ['blog', page], queryFn: () => cmsApi.posts(page), placeholderData: keepPreviousData })
