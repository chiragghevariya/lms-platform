import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  AdminPayment,
  AdminPaymentDetail,
  AdminSubscription,
  AdminSubscriptionDetail,
  AdminUser,
  AdminUserDetail,
  AdminUserFilters,
  Coupon,
  CouponInput,
  PageMetaLite,
  PaymentFilters,
  PaymentTotals,
  SubscriptionFilters,
  UserStatus,
} from '@/types/admin'
import type { Paginated, Plan, PlanPrice, Role, Subscription, User } from '@/types/api'

/** Drops empty filter values so URLs (and exports) stay clean. */
export function clean<T extends object>(f: T): Record<string, unknown> {
  return Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== undefined && v !== null))
}

/** @deprecated name kept for the dashboard counters; same as AdminUserFilters. */
export type UserFilters = AdminUserFilters

export const adminApi = {
  // users
  users: async (f: AdminUserFilters) =>
    (await http.get<Paginated<AdminUser>>('/admin/users', { params: clean(f) })).data,
  user: (id: string) => getData<AdminUserDetail>(`/admin/users/${id}`),
  updateRoles: async (id: string, roles: Role[]) =>
    (await http.put<{ data: User }>(`/admin/users/${id}/roles`, { roles })).data.data,
  setStatus: async (id: string, status: UserStatus, reason?: string) =>
    (
      await http.post<{ data: AdminUser }>(`/admin/users/${id}/status`, {
        status,
        reason: reason || undefined,
      })
    ).data.data,
  bulk: async (action: 'suspend' | 'activate', ids: string[], reason?: string) =>
    (
      await http.post<{ data: { updated: number; skipped: number } }>('/admin/users/bulk', {
        action,
        ids,
        reason: reason || undefined,
      })
    ).data.data,
  passwordReset: async (id: string) =>
    (await http.post<{ message: string }>(`/admin/users/${id}/password-reset`)).data,
  impersonate: async (id: string) =>
    (await http.post<{ data: User }>(`/admin/users/${id}/impersonate`)).data.data,
  grantMembership: async (
    id: string,
    input: { plan_id: string; ends_at?: string | null; notes?: string | null },
  ) => (await http.post<{ data: Subscription }>(`/admin/users/${id}/subscriptions`, input)).data.data,
  revokeMembership: async (userId: string, subId: string) =>
    (await http.post<{ data: Subscription }>(`/admin/users/${userId}/subscriptions/${subId}/cancel`)).data
      .data,

  // subscriptions
  subscriptions: async (f: SubscriptionFilters) =>
    (
      await http.get<{ data: AdminSubscription[]; meta: PageMetaLite & { counts: Record<string, number> } }>(
        '/admin/subscriptions',
        { params: clean(f) },
      )
    ).data,
  subscription: (id: string) => getData<AdminSubscriptionDetail>(`/admin/subscriptions/${id}`),

  // payments
  payments: async (f: PaymentFilters) =>
    (
      await http.get<{ data: AdminPayment[]; meta: PageMetaLite & { totals: PaymentTotals[] } }>(
        '/admin/payments',
        {
          params: clean(f),
        },
      )
    ).data,
  payment: (id: string) => getData<AdminPaymentDetail>(`/admin/payments/${id}`),
  refund: async (id: string, input: { amount?: number; reason: string }) =>
    (await http.post<{ data: AdminPayment }>(`/admin/payments/${id}/refund`, input)).data.data,

  // coupons
  coupons: async () => (await http.get<{ data: Coupon[]; meta: PageMetaLite }>('/admin/coupons')).data,
  createCoupon: async (input: CouponInput) =>
    (await http.post<{ data: Coupon }>('/admin/coupons', input)).data.data,
  updateCoupon: async (id: string, input: CouponInput) =>
    (await http.patch<{ data: Coupon }>(`/admin/coupons/${id}`, input)).data.data,
  deleteCoupon: async (id: string) => {
    await http.delete(`/admin/coupons/${id}`)
  },

  // plans
  plans: () => getData<Plan[]>('/admin/plans'),
  updatePrice: async (
    planId: string,
    priceId: string,
    input: Partial<Pick<PlanPrice, 'is_active' | 'trial_days'>>,
  ) => (await http.patch<{ data: PlanPrice }>(`/admin/plans/${planId}/prices/${priceId}`, input)).data.data,
}

export const useAdminUsers = (f: AdminUserFilters) =>
  useQuery({
    queryKey: ['admin', 'users', f],
    queryFn: () => adminApi.users(f),
    placeholderData: keepPreviousData,
  })

export const useAdminUser = (id: string | null) =>
  useQuery({ queryKey: ['admin', 'user', id], queryFn: () => adminApi.user(id!), enabled: !!id })

export const useAdminPlans = () => useQuery({ queryKey: ['admin', 'plans'], queryFn: adminApi.plans })

/** Refreshes every admin people/money list and open drawer after a change. */
export function useInvalidatePeople() {
  const qc = useQueryClient()
  return () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['admin', 'users'] }),
      qc.invalidateQueries({ queryKey: ['admin', 'user'] }),
      qc.invalidateQueries({ queryKey: ['admin', 'subscriptions'] }),
      qc.invalidateQueries({ queryKey: ['admin', 'subscription'] }),
      qc.invalidateQueries({ queryKey: ['admin', 'payments'] }),
      qc.invalidateQueries({ queryKey: ['admin', 'payment'] }),
    ])
}

export function useUpdateRoles() {
  const invalidate = useInvalidatePeople()
  return useMutation({
    mutationFn: ({ id, roles }: { id: string; roles: Role[] }) => adminApi.updateRoles(id, roles),
    onSuccess: () => invalidate(),
  })
}

export function useTogglePrice() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ plan, price }: { plan: Plan; price: PlanPrice }) =>
      adminApi.updatePrice(plan.id, price.id, { is_active: !price.is_active }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'plans'] })
      qc.invalidateQueries({ queryKey: ['plans'] })
    },
  })
}
