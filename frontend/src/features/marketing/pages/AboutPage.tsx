import { Seo } from '@/components/Seo'
import { env } from '@/lib/env'
import { InstructorHighlights } from '../components/InstructorHighlights'
import { Section } from '../components/Section'

export default function AboutPage() {
  return (
    <>
      <Seo
        title="About us"
        path="/about"
        description={`Why we built ${env.appName}: practical skills that lead to real income.`}
      />
      <section className="mx-auto max-w-3xl px-4 py-16 sm:py-20">
        <h1 className="text-4xl font-extrabold tracking-tight">About {env.appName}</h1>
        <div className="text-muted-foreground mt-6 grid gap-4 text-lg">
          <p>
            We believe the fastest way to change your life is to learn a skill someone will pay for — and then
            actually get paid for it.
          </p>
          <p>
            {env.appName} combines practical courses, live classes with working professionals, and an
            affiliate program so learners can earn from day one. One membership, three tiers, no confusion.
          </p>
        </div>
        <dl className="mt-10 grid gap-6 sm:grid-cols-3">
          {[
            ['Mission', 'Make income-ready skills accessible to everyone.'],
            ['Approach', 'Projects over theory, mentors over videos alone.'],
            ['Promise', 'Learn something useful every single week.'],
          ].map(([t, d]) => (
            <div key={t} className="rounded-lg border p-5">
              <dt className="font-semibold">{t}</dt>
              <dd className="text-muted-foreground mt-1 text-sm">{d}</dd>
            </div>
          ))}
        </dl>
      </section>
      <div className="bg-muted/40 border-t">
        <Section id="team" title="Meet our instructors">
          <InstructorHighlights />
        </Section>
      </div>
    </>
  )
}
