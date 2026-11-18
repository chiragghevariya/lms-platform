import { Seo } from '@/components/Seo'
import { PricingPlans } from '../components/PricingPlans'

export default function PricingPage() {
  return (
    <section className="mx-auto max-w-6xl px-4 py-16">
      <Seo
        title="Pricing"
        path="/pricing"
        description="Starter, Pro and Elite memberships — monthly, yearly or lifetime. Higher tiers include everything below."
      />
      <div className="mx-auto mb-10 max-w-2xl text-center">
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Simple membership pricing</h1>
        <p className="text-muted-foreground mt-3">
          One membership unlocks every course, lesson and live class in your tier — and everything below it.
        </p>
      </div>
      <PricingPlans />
    </section>
  )
}
