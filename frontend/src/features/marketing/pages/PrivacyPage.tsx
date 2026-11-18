import { env } from '@/lib/env'
import { LegalPage } from '../components/LegalPage'

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy Policy" path="/privacy" slug="privacy" updated="12 November 2026">
      <section>
        <h2>What we collect</h2>
        <ul>
          <li>Account details: name, email, phone, country, timezone and profile information you add.</li>
          <li>Sign-in and security data: IP address, device/browser and login history.</li>
          <li>Learning activity and membership/billing records.</li>
        </ul>
      </section>
      <section>
        <h2>How we use it</h2>
        <p>
          To run {env.appName}, secure your account (e.g. lockouts, two-factor, device lists), send service
          emails and verification codes, and improve our courses.
        </p>
      </section>
      <section>
        <h2>Sharing</h2>
        <p>
          We share data only with processors needed to run the service (hosting, email, payments) and never
          sell it.
        </p>
      </section>
      <section>
        <h2>Your choices</h2>
        <p>
          You can update your profile, sign out other devices, and request deletion of your account at any
          time.
        </p>
      </section>
      <section>
        <h2>Cookies</h2>
        <p>
          We use essential cookies for sign-in and security, and remember your theme preference in local
          storage.
        </p>
      </section>
    </LegalPage>
  )
}
