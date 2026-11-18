import {
  Award,
  Bell,
  BookOpen,
  Bookmark,
  CreditCard,
  Gift,
  GraduationCap,
  HandCoins,
  LayoutDashboard,
  NotebookPen,
  PlayCircle,
  Radio,
  ShieldCheck,
  Sparkles,
  UserRound,
} from 'lucide-react'
import { DashboardShell, type NavItem } from './DashboardShell'

const nav: NavItem[] = [
  { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/app/courses', label: 'My learning', icon: BookOpen },
  { to: '/app/live', label: 'Live classes', icon: Radio },
  { to: '/app/replays', label: 'Replays', icon: PlayCircle },
  { to: '/app/certificates', label: 'Certificates', icon: Award },
  { to: '/app/bookmarks', label: 'Bookmarks', icon: Bookmark },
  { to: '/app/notes', label: 'Notes', icon: NotebookPen },
  { to: '/app/learning-profile', label: 'Learning profile', icon: Sparkles },
  { to: '/app/invite', label: 'Invite friends', icon: Gift },
  { to: '/app/affiliate', label: 'Affiliate', icon: HandCoins },
  { to: '/app/become-instructor', label: 'Teach with us', icon: GraduationCap },
  { to: '/app/billing', label: 'Billing', icon: CreditCard },
  { to: '/app/notification-settings', label: 'Notifications', icon: Bell },
  { to: '/app/profile', label: 'Account', icon: UserRound },
  { to: '/app/security', label: 'Security', icon: ShieldCheck },
]

export const StudentLayout = () => <DashboardShell nav={nav} area="Student" homePath="/app" />
