import { env } from '@/lib/env'
import { LegalPage } from '../components/LegalPage'

export default function TermsPage() {
  return (
    <LegalPage title="Terms of Service" path="/terms" slug="terms" updated="12 November 2026">
      <section>
        <h2>1. Agreement</h2>
        <p>By creating an account or using {env.appName} you agree to these terms.</p>
      </section>
      <section>
        <h2>2. Accounts</h2>
        <p>You are responsible for your account and for keeping your password and two-factor codes secure.</p>
      </section>
      <section>
        <h2>3. Membership & billing</h2>
        <ul>
          <li>Memberships renew automatically at the end of each billing period unless cancelled.</li>
          <li>Cancelling stops future renewals; access continues until the end of the paid period.</li>
          <li>
            Each course, lesson and live class requires a minimum tier; higher tiers include lower-tier
            content.
          </li>
        </ul>
      </section>
      <section>
        <h2>4. Acceptable use</h2>
        <p>Do not share your account, redistribute course content, or misuse the affiliate program.</p>
      </section>
      <section>
        <h2>5. Affiliate program</h2>
        <p>
          Commissions are paid only on genuine, completed referrals and may be withheld for fraudulent
          activity.
        </p>
      </section>
      <section>
        <h2>6. Contact</h2>
        <p>Questions about these terms? Reach us through the contact page.</p>
      </section>
    </LegalPage>
  )
}
