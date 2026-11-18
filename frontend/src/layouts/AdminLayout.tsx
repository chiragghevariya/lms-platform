import {
  BarChart3,
  BookOpen,
  CreditCard,
  FileText,
  FolderTree,
  GraduationCap,
  Handshake,
  History,
  Home,
  LayoutDashboard,
  Megaphone,
  MessageSquareQuote,
  Newspaper,
  Percent,
  Radio,
  Receipt,
  Repeat,
  Scale,
  Send,
  Settings,
  ShieldCheck,
  Star,
  CircleHelp,
  Flag,
  UserRound,
  Users,
  Wallet,
} from 'lucide-react'
import { can, type Permission } from '@/lib/permissions'
import { useAuthStore } from '@/stores/auth-store'
import { DashboardShell, type NavItem } from './DashboardShell'

type AdminNavItem = NavItem & { permission?: Permission }

/** Grouped by job; each entry is hidden unless the signed-in admin has its permission. */
const ADMIN_NAV: AdminNavItem[] = [
  { group: 'Overview', to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true },
  {
    group: 'Overview',
    to: '/admin/analytics',
    label: 'Analytics',
    icon: BarChart3,
    permission: 'analytics.view',
  },

  { group: 'People', to: '/admin/users', label: 'Users', icon: Users, permission: 'users.view' },
  {
    group: 'People',
    to: '/admin/subscriptions',
    label: 'Subscriptions',
    icon: Repeat,
    permission: 'subscriptions.manage',
  },
  {
    group: 'People',
    to: '/admin/instructor-applications',
    label: 'Instructor applications',
    icon: GraduationCap,
    permission: 'instructors.manage',
  },
  {
    group: 'People',
    to: '/admin/affiliates',
    label: 'Affiliates',
    icon: Handshake,
    permission: 'affiliates.manage',
  },

  {
    group: 'Money',
    to: '/admin/payments',
    label: 'Payments & refunds',
    icon: Receipt,
    permission: 'payments.view',
  },
  {
    group: 'Money',
    to: '/admin/plans',
    label: 'Plans & prices',
    icon: CreditCard,
    permission: 'plans.manage',
  },
  { group: 'Money', to: '/admin/coupons', label: 'Coupons', icon: Percent, permission: 'plans.manage' },
  {
    group: 'Money',
    to: '/admin/payouts',
    label: 'Instructor payouts',
    icon: Wallet,
    permission: 'instructors.manage',
  },

  {
    group: 'Catalog',
    to: '/admin/courses',
    label: 'Courses',
    icon: BookOpen,
    permission: 'courses.manage-any',
  },
  {
    group: 'Catalog',
    to: '/admin/categories',
    label: 'Categories',
    icon: FolderTree,
    permission: 'courses.manage-any',
  },
  { group: 'Catalog', to: '/admin/reviews', label: 'Reviews', icon: Star, permission: 'reviews.moderate' },
  { group: 'Catalog', to: '/admin/live', label: 'Live sessions', icon: Radio, permission: 'live.manage-any' },

  {
    group: 'Messaging',
    to: '/admin/campaigns',
    label: 'Campaigns',
    icon: Megaphone,
    permission: 'notifications.manage',
  },
  {
    group: 'Messaging',
    to: '/admin/notification-templates',
    label: 'Templates',
    icon: FileText,
    permission: 'notifications.manage',
  },
  {
    group: 'Messaging',
    to: '/admin/delivery',
    label: 'Delivery',
    icon: Send,
    permission: 'notifications.manage',
  },

  { group: 'Website', to: '/admin/cms/homepage', label: 'Homepage', icon: Home, permission: 'cms.manage' },
  { group: 'Website', to: '/admin/cms/faqs', label: 'FAQs', icon: CircleHelp, permission: 'cms.manage' },
  {
    group: 'Website',
    to: '/admin/cms/testimonials',
    label: 'Testimonials',
    icon: MessageSquareQuote,
    permission: 'cms.manage',
  },
  { group: 'Website', to: '/admin/cms/banners', label: 'Banners', icon: Flag, permission: 'cms.manage' },
  { group: 'Website', to: '/admin/cms/pages', label: 'Legal pages', icon: Scale, permission: 'cms.manage' },
  { group: 'Website', to: '/admin/blog', label: 'Blog', icon: Newspaper, permission: 'cms.manage' },

  {
    group: 'System',
    to: '/admin/settings',
    label: 'Settings',
    icon: Settings,
    permission: 'settings.manage',
  },
  { group: 'System', to: '/admin/audit-log', label: 'Audit log', icon: History, permission: 'audit.view' },

  { group: 'Account', to: '/admin/profile', label: 'Profile', icon: UserRound },
  { group: 'Account', to: '/admin/security', label: 'Security', icon: ShieldCheck },
]

export function AdminLayout() {
  const user = useAuthStore((s) => s.user)
  const nav = ADMIN_NAV.filter((i) => !i.permission || can(user, i.permission))
  return <DashboardShell nav={nav} area="Admin" homePath="/admin" />
}
