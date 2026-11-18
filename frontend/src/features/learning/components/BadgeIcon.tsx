import { Award, BookOpenCheck, Flame, GraduationCap, Sparkles, Trophy, type LucideIcon } from 'lucide-react'

const ICONS: Record<string, LucideIcon> = {
  first_lesson: BookOpenCheck,
  first_course: GraduationCap,
  quiz_ace: Sparkles,
  streak_3: Flame,
  streak_7: Flame,
  points_500: Trophy,
}

export const BadgeIcon = ({ badgeKey, className }: { badgeKey: string; className?: string }) => {
  const Icon = ICONS[badgeKey] ?? Award
  return <Icon className={className} aria-hidden />
}
