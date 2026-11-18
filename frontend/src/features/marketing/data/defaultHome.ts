import type { CmsSection, Faq } from '@/features/cms/types'
import { FAQS, VALUE_PROPS } from './content'

/**
 * Built-in homepage (mirrors backend App\Cms\CmsDefaults). Shown while the CMS loads or if it
 * is unreachable, so the homepage never renders empty.
 */
export const DEFAULT_SECTIONS: CmsSection[] = [
  {
    key: 'hero',
    content: {
      badge: 'New live cohorts every month',
      title: 'Learn Skills.',
      highlight: 'Build Income.',
      subtitle:
        'One membership for practical courses, live classes and mentors — designed to turn what you learn into real earnings.',
      primary_cta: { label: 'Start learning', url: '/register' },
      secondary_cta: { label: 'Browse courses', url: '/courses' },
      items: [
        { title: 'Courses', body: 'Practical, project-based courses' },
        { title: 'Live', body: 'Learn with instructors in real time' },
        { title: 'Mentors', body: 'Mentors who earn from their skills' },
        { title: 'Earn', body: 'Refer friends and earn commission' },
      ],
    },
  },
  {
    key: 'why',
    content: { eyebrow: 'Why us', title: 'Built for outcomes, not just certificates', items: VALUE_PROPS },
  },
  {
    key: 'featured',
    content: {
      eyebrow: 'Featured',
      title: 'Courses learners love',
      intro: "A preview of what's inside your membership.",
    },
  },
  {
    key: 'pricing',
    content: {
      eyebrow: 'Membership',
      title: 'One plan, everything in your tier',
      intro: 'Higher tiers include everything below them.',
    },
  },
  { key: 'instructors', content: { eyebrow: 'Mentors', title: 'Learn from people who earn from it' } },
  { key: 'testimonials', content: { eyebrow: 'Stories', title: 'What learners say' } },
  { key: 'faq', content: { eyebrow: 'FAQ', title: 'Questions, answered' } },
  {
    key: 'cta',
    content: {
      title: 'Your next income skill starts today',
      subtitle: "Join free, explore the platform, and upgrade when you're ready.",
      primary_cta: { label: 'Create free account', url: '/register' },
    },
  },
].map((s, i) => ({ ...s, key: s.key as CmsSection['key'], is_visible: true, sort: i, customised: false }))

export const DEFAULT_FAQS: Faq[] = FAQS.map((f, i) => ({
  id: `default-${i}`,
  question: f.q,
  answer: f.a,
  category: 'general',
}))
