import {
  ArrowRight,
  BadgeIndianRupee,
  BookOpen,
  GraduationCap,
  Quote,
  Sparkles,
  Star,
  Users,
  Video,
} from 'lucide-react'
import { forwardRef, type AnchorHTMLAttributes, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useCatalog } from '@/features/courses/api'
import { CourseCard } from '@/features/courses/components/CourseCard'
import { useHome } from '@/features/cms/api'
import type { CmsSection, Cta, Faq as FaqItem, Testimonial } from '@/features/cms/types'
import { cn } from '@/lib/utils'
import { Faq } from '../components/Faq'
import { InstructorHighlights } from '../components/InstructorHighlights'
import { PricingTeaser } from '../components/PricingTeaser'
import { Section } from '../components/Section'
import { DEFAULT_FAQS, DEFAULT_SECTIONS } from '../data/defaultHome'

const HERO_ICONS = [BookOpen, Video, Users, BadgeIndianRupee]
const VALUE_ICONS = [BadgeIndianRupee, Video, GraduationCap, Sparkles]

/** Internal paths use the router; absolute URLs open normally. */
/** Internal paths use the router; absolute URLs a plain link. Forwards Button(asChild) props and ref. */
const CtaLink = forwardRef<
  HTMLAnchorElement,
  { cta: Cta; children: ReactNode } & AnchorHTMLAttributes<HTMLAnchorElement>
>(({ cta, children, ...rest }, ref) =>
  cta.url.startsWith('/') ? (
    <Link ref={ref} to={cta.url} {...rest}>
      {children}
    </Link>
  ) : (
    <a ref={ref} href={cta.url} rel="noopener noreferrer" {...rest}>
      {children}
    </a>
  ),
)
CtaLink.displayName = 'CtaLink'

function Hero({ c }: { c: CmsSection['content'] }) {
  return (
    <section className="relative overflow-hidden">
      <div
        aria-hidden
        className="from-primary/15 to-accent/10 absolute inset-0 -z-10 bg-gradient-to-br via-transparent"
      />
      <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:py-28 lg:grid-cols-2">
        <div>
          {c.badge && (
            <p className="bg-secondary text-secondary-foreground mb-6 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium">
              <Sparkles className="size-4" aria-hidden /> {c.badge}
            </p>
          )}
          <h1 className="text-4xl font-extrabold tracking-tight text-balance sm:text-6xl">
            {c.title} {c.highlight && <span className="text-accent">{c.highlight}</span>}
          </h1>
          {c.subtitle && <p className="text-muted-foreground mt-6 max-w-xl text-lg">{c.subtitle}</p>}
          <div className="mt-10 flex flex-col gap-3 sm:flex-row">
            {c.primary_cta && (
              <Button size="lg" asChild>
                <CtaLink cta={c.primary_cta}>
                  {c.primary_cta.label} <ArrowRight aria-hidden />
                </CtaLink>
              </Button>
            )}
            {c.secondary_cta && (
              <Button size="lg" variant="outline" asChild>
                <CtaLink cta={c.secondary_cta}>{c.secondary_cta.label}</CtaLink>
              </Button>
            )}
          </div>
        </div>
        {!!c.items?.length && (
          <ul className="grid grid-cols-2 gap-4">
            {c.items.map(({ title, body }, i) => {
              const Icon = HERO_ICONS[i % HERO_ICONS.length] ?? Sparkles
              return (
                <li key={`${title}-${i}`}>
                  <Card className="h-full">
                    <CardContent className="p-6">
                      <Icon className="text-primary mb-3 size-6" aria-hidden />
                      <p className="text-2xl font-bold">{title}</p>
                      {body && <p className="text-muted-foreground text-sm">{body}</p>}
                    </CardContent>
                  </Card>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    </section>
  )
}

function Featured() {
  const featured = useCatalog({ featured: true, per_page: 4 })
  return (
    <>
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        {featured.isLoading &&
          Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-72 rounded-xl" />)}
        {featured.data?.data.map((c) => (
          <CourseCard key={c.id} course={c} headingLevel="h3" />
        ))}
      </div>
      <div className="mt-8 text-center">
        <Button asChild variant="outline">
          <Link to="/courses">
            See the catalogue <ArrowRight aria-hidden />
          </Link>
        </Button>
      </div>
    </>
  )
}

function Testimonials({ items }: { items: Testimonial[] }) {
  return (
    <div className="grid gap-6 md:grid-cols-3">
      {items.map((t) => (
        <Card key={t.id}>
          <CardContent className="grid gap-4 p-6">
            <Quote className="text-primary size-6" aria-hidden />
            <blockquote className="text-muted-foreground italic">{t.quote}</blockquote>
            {t.rating && (
              <p className="flex gap-0.5" aria-label={`${t.rating} out of 5 stars`}>
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    className={cn(
                      'size-4',
                      i < t.rating! ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/40',
                    )}
                    aria-hidden
                  />
                ))}
              </p>
            )}
            <div className="flex items-center gap-3 text-sm">
              {t.avatar_url && (
                <img src={t.avatar_url} alt="" className="size-9 rounded-full object-cover" loading="lazy" />
              )}
              <p>
                <span className="font-semibold">{t.name}</span>
                {t.role && <> · {t.role}</>}
              </p>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

/**
 * Homepage driven by Admin → Website → Homepage (sections, order, visibility), FAQs and
 * testimonials. Falls back to the built-in copy while loading or if the CMS is unreachable.
 */
export default function HomePage() {
  const home = useHome()
  const sections = home.data?.sections ?? DEFAULT_SECTIONS
  // Built-in FAQ until the admin publishes their own (the section can be hidden in the CMS).
  const faqs: FaqItem[] = home.data?.faqs.length ? home.data.faqs : DEFAULT_FAQS
  // Never show placeholder testimonials: only real, published ones.
  const testimonials = home.data?.testimonials ?? []

  let band = 0
  const shaded = () => band++ % 2 === 1

  return (
    <>
      <Seo path="/" />
      {sections.map(({ key, content: c }) => {
        if (key === 'hero') return <Hero key={key} c={c} />
        if (key === 'cta')
          return (
            <section key={key} aria-labelledby="cta-heading" className="mx-auto max-w-6xl px-4 py-20">
              <div className="bg-primary text-primary-foreground rounded-2xl px-6 py-14 text-center sm:px-12">
                <h2 id="cta-heading" className="text-3xl font-bold tracking-tight text-balance sm:text-4xl">
                  {c.title}
                </h2>
                {c.subtitle && <p className="mx-auto mt-3 max-w-xl opacity-90">{c.subtitle}</p>}
                {c.primary_cta && (
                  <Button size="lg" variant="secondary" className="mt-8" asChild>
                    <CtaLink cta={c.primary_cta}>
                      {c.primary_cta.label} <ArrowRight aria-hidden />
                    </CtaLink>
                  </Button>
                )}
              </div>
            </section>
          )

        let body: ReactNode = null
        if (key === 'why')
          body = (
            <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
              {(c.items ?? []).map(({ title, body: text }, i) => {
                const Icon = VALUE_ICONS[i % VALUE_ICONS.length] ?? Sparkles
                return (
                  <div key={`${title}-${i}`}>
                    <span className="bg-primary/10 text-primary grid size-11 place-items-center rounded-lg">
                      <Icon aria-hidden />
                    </span>
                    <h3 className="mt-4 text-lg font-semibold">{title}</h3>
                    {text && <p className="text-muted-foreground mt-1">{text}</p>}
                  </div>
                )
              })}
            </div>
          )
        else if (key === 'featured') body = <Featured />
        else if (key === 'pricing') body = <PricingTeaser />
        else if (key === 'instructors') body = <InstructorHighlights />
        else if (key === 'testimonials') {
          if (testimonials.length === 0) return null
          body = <Testimonials items={testimonials} />
        } else if (key === 'faq') body = <Faq items={faqs} />
        if (!body) return null

        const id = key === 'why' ? 'why' : key === 'featured' ? 'courses' : key
        const section = (
          <Section
            id={id}
            eyebrow={c.eyebrow ?? undefined}
            title={c.title ?? ''}
            intro={c.intro ?? undefined}
          >
            {body}
          </Section>
        )
        return shaded() ? (
          <div key={key} className="bg-muted/40 border-y">
            {section}
          </div>
        ) : (
          <div key={key}>{section}</div>
        )
      })}
    </>
  )
}
