import {
  BookOpenCheck,
  ClipboardCheck,
  IdCard,
  LayoutDashboard,
  Megaphone,
  MessageCircleQuestion,
  ShieldCheck,
  UserRound,
  Users,
  Video,
  Wallet,
} from 'lucide-react'
import { DashboardShell, type NavItem } from './DashboardShell'

const nav: NavItem[] = [
  { to: '/instructor', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/instructor/courses', label: 'My courses', icon: BookOpenCheck },
  { to: '/instructor/live', label: 'Live sessions', icon: Video },
  { to: '/instructor/grading', label: 'Grading', icon: ClipboardCheck },
  { to: '/instructor/students', label: 'Students', icon: Users },
  { to: '/instructor/questions', label: 'Q&A inbox', icon: MessageCircleQuestion },
  { to: '/instructor/announcements', label: 'Announcements', icon: Megaphone },
  { to: '/instructor/earnings', label: 'Earnings', icon: Wallet },
  { to: '/instructor/teaching-profile', label: 'Teaching profile', icon: IdCard },
  { to: '/instructor/profile', label: 'Account', icon: UserRound },
  { to: '/instructor/security', label: 'Security', icon: ShieldCheck },
]

export const InstructorLayout = () => <DashboardShell nav={nav} area="Instructor" homePath="/instructor" />
