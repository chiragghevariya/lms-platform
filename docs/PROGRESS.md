# Progress

Brand name is a placeholder (`APP_NAME="Brand"` / `VITE_APP_NAME=Brand`). Change those two env vars to rebrand.

Phases follow the spec you provide for each one. **Phase 10 (hardening, performance, scale) is the latest.** Before the Phase 1 spec arrived, a first pass also built **membership tiers, plans, subscriptions, tier gating and admin user/plan screens**. That work is kept, tested, and will be reused by the payments phase.

## Phase 10 — Harden, optimise, prepare to scale ✅ (2026-11-13)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| No high/critical findings in an automated scan (OWASP ZAP) | ✅ | ZAP 2.17.0 authenticated passive + active scan of the API (32 seeded requests) and the built SPA with production headers: **0 High, 0 Critical**; the API host has no Medium either. Two SPA Mediums: `style-src 'unsafe-inline'` (accepted, documented) and "HTTP only" (local test had no TLS). Report: `docs/security/zap-report.html`; details in `docs/SECURITY.md` |
| k6 targets met | ✅ | **1,000 concurrent users**, mixed (700 browsing / 250 watching / 50 buying) for 3 min: **0.49% errors**; p95 listing 77 ms, course 82 ms, heartbeat 262 ms, checkout 312 ms (incl. 150 ms simulated gateway). Separate 1,000-VU runs: catalogue 0.25% errors (p95 ≤ 486 ms); heartbeat 0% errors (p95 59 ms). Everything ran on one laptop, load generator included. See `docs/PERFORMANCE.md` |
| Lighthouse ≥ 90 on home and course pages | ✅ | Mobile, HTTP/2 + TLS (production-like): **home 97 / 100 / 100 / 100, course 95–96 / 100 / 100 / 100** over 3 runs. Over plain HTTP/1.1 locally: 90–91 / 87–89 (6-connection limit); production serves HTTP/2 (`deploy/nginx/lms.conf`) |

### Done — security
- **Security review + 5 fixes:** a **high-severity Google mobile sign-in token replay** (audience now verified), instructors attaching others' videos as free previews, cross-user device-slot clearing, non-members submitting preview quizzes/assignments, and missing throttles. Each has regression tests.
- `SecurityHeaders` middleware (CSP, HSTS, X-Frame-Options, nosniff, Referrer/Permissions policies, COOP); production nginx template with the SPA CSP (inline theme script allowed by `sha256` hash only).
- **Turnstile** captcha on register, login, contact and forgot-password (free; off until keys are set).
- **2-device limit** for learners (`user_devices`, works with Redis sessions; staff exempt; clear "signed out on another device" message).
- **Moving email watermark** in the player (visible in fullscreen); signed HLS URLs were already in place. Bunny allowed-referrers setup is documented.
- **GDPR/DPDP:** data export, account deletion/anonymisation (invoices retained for tax law, renewals cancelled first), **cookie consent** gating GA4 and the Pixel.
- **Uploads:** `CleanUpload` → `UploadScanner` (ClamAV clamd, fail-closed hook).
- **Encryption at rest** for billing addresses and tax ids (migration re-encrypts existing rows).
- **Rate limits:** configurable API limit, admin and upload limiters.

### Done — performance & scale
- **Indexes** for analytics, admin and catalogue queries. **Guest catalogue, home and site cached** in Redis with version-bump invalidation and `s-maxage` for the CDN. **WebP thumbnail variants** + `srcset`.
- Config for **read replicas** (`DB_READ_HOST`), **trusted proxies**, **Horizon supervisors** (critical / messaging / media), Redis over socket or TLS, persistent connections; settings survive a Redis outage.
- **Frontend:** initial load 188 → **153 KB gzip** (dashboard layouts and Radix menus lazy, video player lazy on the course page), **budget check** (`npm run bundle:report`), course **prefetch on hover** + parallel data on direct load, static **app shell** for fast first paint, CLS fixes, a11y/SEO fixes (robots.txt with absolute sitemap, list semantics, badge contrast, no console 401 for guests via `/auth/session`).
- **Load-test kit:** `loadtest/*.js` (k6), `php artisan loadtest:prepare`, sandbox gateway, ZAP plan.
- **Tests:** 395 Pest tests (22 new: security hardening, Google audience, catalogue cache, thumbnail variants, session probe); 83 Vitest tests (4 new). Lint, TypeScript, PHPStan, Pint and the build are clean.

### Bugs caught during this phase (fixed)
1. **High: Google mobile token replay → account takeover** (found by the security review).
2. **Heartbeats failed on PostgreSQL / strict SQL** (ambiguous column in upserts); SQLite tests had hidden it. Found by the load test.
3. **Too many php-fpm workers inflated latency** (heartbeat p95 646 ms at 64 workers → 59 ms at 16). Sizing rule documented.
4. **Every request failed if Redis was down** (settings boot). Now falls back to the database.
5. **Homepage/settings JSON uncached** (home p95 752 → 111 ms).
6. Lighthouse findings: invalid robots.txt sitemap, CSP-blocked inline script, layout shift from the footer, low-contrast tier badge on the course hero, invalid `<dl>` markup, a 401 console error for every guest.

### Decisions and limitations (please review)
1. **Load tests ran on one laptop** (k6 + nginx + PHP + Postgres + Redis together, so results are pessimistic). Re-run `mixed.js` from a separate machine against staging before launch (Phase 11). Postgres was used locally because the local MySQL needs credentials I don't have. Production stays MySQL; the queries are portable (and a Postgres-only bug was found and fixed).
2. **Cloudflare WAF:** the free plan's protections (Bot Fight Mode, Turnstile, free managed rules) are documented; the full OWASP ruleset needs **Cloudflare Pro (paid)**. Your call.
3. **CDN for images:** WebP variants are generated at upload (free, GD). Bunny Optimizer / CloudFront image resizing are paid alternatives; not added.
4. **Virus scanning** needs `clamd` on the server (free); the hook is off by default locally.
5. **CSP keeps `style-src 'unsafe-inline'`** (React/Radix/Plyr inline styles); scripts are strict.
6. **Octane not enabled**: php-fpm met the targets. Notes on safe adoption are in `docs/PERFORMANCE.md`.
7. Device limit = 2 for every learner (`MAX_DEVICES`); per-tier limits would be a small change.

### Env vars added in Phase 10
`CAPTCHA_DRIVER`, `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`, `MAX_DEVICES`, `UPLOAD_SCAN_DRIVER`, `CLAMAV_SOCKET`, `UPLOAD_SCAN_FAIL_CLOSED`, `API_RATE_LIMIT`, `ADMIN_RATE_LIMIT`, `HSTS_*`, `GOOGLE_MOBILE_CLIENT_IDS`, `TRUSTED_PROXIES`, `CATALOG_CACHE_STORE`, `DB_READ_HOST`, `DB_PERSISTENT`, `REDIS_SCHEME`, `REDIS_PATH`, `REDIS_PERSISTENT`, `HORIZON_*_MAX`, `BILLING_SANDBOX_GATEWAY`, `BILLING_SANDBOX_LATENCY_MS`; frontend `VITE_SITE_URL`.

## Phase 9 — Admin panel, platform management & analytics ✅ (2026-11-13)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Admin can run the business day to day from the panel | ✅ | Every area has a screen gated by a granular permission. **Users:** filters, detail drawer, suspend/ban/reactivate with a reason, bulk actions, password reset, roles, complimentary memberships, "view as user" impersonation. Also subscriptions, **payments and refunds** (Stripe/Razorpay through the gateway), plans and coupons, the course review queue and categories, review moderation, live sessions, instructor applications and payouts, affiliates (applications, terms, fraud review, payouts, exports), campaigns and templates, **CMS** (homepage sections, FAQs, testimonials, banners, legal pages, blog), **settings** (branding, SEO, tax, gateway keys, tracking) and the **audit log**. In the browser: all 17 new pages load real data; impersonation → banner → password change blocked (403) → stop → back on the user drawer; banner, FAQ and hero edits appear on the public homepage |
| Analytics numbers reconcile with the payments table in tests | ✅ | `Admin/AnalyticsTest` builds a known month of payments (multi-currency, partial refund, failed payment, yearly plan, trials, cancellation, comp membership). It asserts gross, refunds, net, tax, membership/course split, series, by-gateway and by-country totals **equal independent SQL sums over `payments`**, and checks MRR, ARR, churn, ARPU, LTV, trial conversion, new members, members by tier, funnel, cohorts, cache invalidation and the nightly snapshot exactly |

### Done — backend
- **Permissions:** 21 granular permissions with a `can:` middleware per admin area. Admins get everything except role assignment and gateway secrets (super admin). Re-run `php artisan db:seed --class=RolesAndPermissionsSeeder` after deploying.
- **Users:** shared filter query (search, role, status, tier, country, verified, joined, sort) for the table and exports. A detail endpoint (subscriptions, payments, stats, lifetime value, logins, activity). Status changes with a reason (sign-out everywhere on suspend), bulk suspend/activate (staff and self skipped), password reset link, session-based **impersonation** (non-staff only, browser only, audited; security, billing and admin writes are blocked while active).
- **Money:** subscriptions list/detail, payments list with per-currency totals, **refunds**: `PaymentGateway::refund()` for Stripe (PaymentIntent) and Razorpay, recorded through the webhook `RefundRecorder` so commissions and earnings react and the later webhook doesn't double count.
- **Catalogue:** categories CRUD (delete blocked while in use), review moderation (hidden reviews leave the public list and the rating), course approve/reject/feature/unpublish now audited.
- **CMS:** homepage sections with built-in defaults and field-level overrides, FAQs and testimonials with ordering, banners (audience + schedule), legal pages, blog (drafts, scheduled posts, SEO fields). HTML is sanitised server-side. Public endpoints `/site`, `/cms/home`, `/cms/pages/{slug}`, `/blog`.
- **Settings:** `SiteSettings` stores branding, SEO, tax rates and seller details, gateway keys and tracking IDs/secrets. They override `.env` config at runtime (workers reload on change); secrets are encrypted and write-only.
- **Analytics:** `AnalyticsService` (MRR, ARR, active members by tier, new vs churned, churn rate, LTV, ARPU, trial conversion, revenue by gateway and country, top courses, completion rates, watch time, live attendance, affiliate revenue share, cohort retention, signup funnel), cached with event-driven invalidation. `analytics:snapshot` runs nightly for MRR history and cache warm-up.
- **Exports:** users, payments and commissions as CSV or Excel (maatwebsite/excel), with the same filters as the tables, audited.
- **Audit log:** spatie/laravel-activitylog. Model diffs for admin-managed records, explicit events for admin actions, and an `AuditAdminWrite` middleware for the older affiliate, payout and instructor routes.
- **Tracking (optional, free):** server-side purchase conversions to GA4 (Measurement Protocol) and Meta (Conversions API, hashed user data) when configured.
- **Tests:** 373 Pest tests (44 new in `tests/Feature/Admin/*`), PHPStan level 6, Pint.

### Done — frontend
- Permission-aware grouped admin sidebar (`ADMIN_NAV` + `can()`), a `RequirePermission` route guard, the impersonation banner on every layout.
- Shared **TanStack Table** `DataTable` (server pagination, row selection + bulk bar, column toggles saved per table, keyboard-openable rows), `ExportButton` (CSV/Excel), side `Sheet` drawer.
- **Dashboard:** KPI cards (MRR, active members, churn, revenue today), net revenue chart, members by tier, signup funnel, top courses; a shareable range and currency in the URL. **Analytics** page adds ARR, ARPU, LTV, trial conversion, MRR trend, revenue split by product, gateway and country, affiliates, a cohort heatmap table, and learning and live metrics.
- Pages: users (+ drawer), subscriptions, payments (+ refund dialog), coupons, categories, reviews, live sessions, affiliates, homepage editor, FAQs, testimonials (drag-to-reorder), banners, legal pages, blog list and editor, settings, audit log.
- Public site reads the CMS with the built-in copy as fallback: homepage sections, FAQ, testimonials (hidden until published), banner, Terms/Privacy overrides, `/p/:slug`, `/blog`. SEO defaults from settings. GA4 and Meta Pixel load only when configured (page_view, sign_up, begin_checkout; purchases server-side only).
- **Tests:** 79 Vitest tests (21 new: people/money, catalog/affiliates, CMS). Lint, TypeScript and the build are clean.

### Bugs caught during this phase (fixed)
1. **Impersonation banner didn't appear until a reload:** the start response was built while the request still resolved the admin.
2. **Homepage hero buttons lost their styling:** the CMS link component didn't forward `Button asChild` props.
3. **Featured courses on the homepage never loaded (422):** axios sent `featured=true`, which Laravel's boolean rule rejects. This had probably been there since Phase 2.
4. **Admins previewing CMS edits saw stale content:** the public endpoints sent browser cache headers. Removed (server caching comes in Phase 10).
5. **Revenue charts labelled the axis in paise.**
6. **Clearing a live post's date silently unpublished it; reordering homepage sections marked them all "Customised"; toggling visibility saved unsaved edits.**
7. **Coupons page showed only the first 50.**
8. **Affiliate and payout admin actions weren't audited.**
9. **Settings "clear" fell back to the boot-time config instead of `.env` in long-running processes:** the baseline is now captured on first override.

### Decisions and limitations (please review)
1. **No FX conversion:** every metric is per currency, with a switcher on the dashboard. Totals across INR and USD would need an exchange-rate source (paid APIs exist; ask first).
2. **MRR** uses each renewing subscription's latest paid amount net of tax (yearly ÷ 12). Trials, lifetime and complimentary memberships are excluded. The MRR trend starts from the first nightly snapshot (there's no backfill).
3. **Redis isn't installed locally:** analytics use the default cache store locally; set `ANALYTICS_CACHE_STORE=redis` in production.
4. **Impersonation is browser-only** and can't target staff. Logging out while impersonating ends the whole session.
5. **Refunds** need a gateway reference (Stripe PaymentIntent / Razorpay `pay_…`). Older checkout-session-only Stripe payments must be refunded in the Stripe dashboard (the webhook still records it).
6. **Role permissions are fixed in code** (`Permission::matrix()`); there's no UI to edit a role's permissions.
7. **Exports are synchronous** (fine for tens of thousands of rows); queue them if the user base gets very large.
8. Laravel Boost (suggested by `backend/CLAUDE.md`) is still not installed.

### Env vars added in Phase 9
`ANALYTICS_CACHE_STORE`, `ANALYTICS_CACHE_TTL` (60), `ANALYTICS_TIMEZONE` (`Asia/Kolkata`), `GA4_MEASUREMENT_ID`, `GA4_API_SECRET`, `META_PIXEL_ID`, `META_CAPI_TOKEN`, `META_TEST_EVENT_CODE`, `META_GRAPH_VERSION` (`v26.0`), `ACTIVITY_LOGGER_ENABLED`.

## Phase 8 — Multi-channel notifications ✅ code complete · ⏳ live providers need keys (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Each event fires on the right channels respecting preferences | ✅ | `Notifications/RoutingTest` walks **every event** in `config/notify.php`: a fully reachable user gets exactly the default channels; turning everything off leaves only the mandatory ones (invoices, payment-failed email, verification/reset email, OTP SMS); unreachable channels are skipped and logged with the reason (no consent, no verified phone, no device, not configured); WhatsApp → SMS fallback for payment failures. `TriggersTest` covers the triggers (welcome, renewal, expiring 7 d / 1 d once each, inactivity 7 / 14 d with a resume link, new course to that tier only and only on first publish, commission earned / paid, live reminders 24 h / 15 m). In the browser: a campaign to Pro members arrived by email (branded, with unsubscribe) and in the bell |
| WhatsApp template sends in sandbox | ✅ tests · ⏳ real sandbox | `DeliveryTest` checks the exact Cloud API request (Graph **v26.0**, `messaging_product`, `to` in digits, template name, language, ordered body parameters, bearer token). A real run needs a Meta app with the WhatsApp **test number** (free) and an approved template — no keys locally |
| Logs show delivery status | ✅ | `notification_logs` row per notification × channel: `skipped / sending / sent / delivered / read / failed`, provider message id, masked recipient, attempts, error. Signed webhooks move it forward (WhatsApp statuses incl. read receipts, Mailgun delivered/opened/failed, Twilio status callbacks) and never backwards. Admin delivery report with per-channel totals, filters and **retry**. In the browser: the campaign's rows show mail *sent* + in-app *delivered* per recipient |

### Done — backend
- **One pipeline for every notification:** `PlatformNotification` (base of all ~30 notifications; the ~20 existing ones were converted) → `NotificationRouter` decides channels from the event config, the user's preferences, mandatory channels and reachability → copy from `Templates` (admin edits over built-in defaults, `{{variables}}`, Markdown-escaped values) → queued per channel (`notifications` / `mail` queues), **rate limited per channel** (mail 10/s, WhatsApp 20/s, SMS 10/s, push 100/s) and logged by `NotificationLogger`.
- **Channels:** mail (Mailgun transport installed; SES works with `aws/aws-sdk-php`), branded Markdown theme (brand header, indigo buttons, footer with settings + unsubscribe), `List-Unsubscribe` + RFC 8058 one-click, signed unsubscribe page; **database** (in-app bell); **WhatsApp** Cloud API templates (incl. authentication/OTP templates with the copy-code button), inbound "STOP" opt-out; **FCM HTTP v1** push (service-account JWT, dead tokens removed); **SMS** adapters MSG91 (DLT flow templates) / Twilio / log, used for OTPs and as a fallback.
- **Preferences & consent:** `notification_preferences` per user × event × channel (only overrides stored); WhatsApp is opt-in with consent and opt-out timestamps and requires a verified phone; Phase 1 phone OTPs now go through the SMS driver (or WhatsApp when `OTP_CHANNEL=whatsapp`) and are logged **without the code**. Laravel's verify-email / reset-password mails are logged too.
- **Events:** welcome, email verify, password reset, OTP, membership started / renewed / payment failed / ending in 7 d & 1 d / ended, invoice, new course in your tier, assignment graded, certificate, question answered, course announcement, inactivity 7 / 14 d, live registered / 24 h / 15 m / cancelled, commission earned / paid, admin announcements, plus staff events.
- **Campaigns:** segment builder (tier incl. free, paid-subscription status, enrolled course, inactive ≥ N days, country, role) with live audience + WhatsApp-reachable counts, email / in-app / WhatsApp / push content, test-send to yourself, send now or schedule (`notify:scheduled` every 5 minutes), cancel, per-channel stats from the logs. Everyone's preferences and consent still apply.
- **Admin API:** template manager (list, edit, reset, preview with sample data, unknown-variable check), campaigns, delivery reports + retry.
- **Tests:** 329 Pest tests (29 new in `tests/Feature/Notifications/*`), PHPStan level 6, Pint.

### Done — frontend
- **Bell** in every dashboard and the public header: unread badge (polls every 60 s and on focus), latest notifications, mark all read, deep links. **Notifications page** (unread filter, pagination) and **Notification settings** (events × channels grid, locked "Always" for mandatory ones, unavailable channels disabled, WhatsApp opt-in with explicit consent and phone-verification prompt).
- **Admin:** Templates (event list, per-channel editor, variable chips, live preview, reset), Campaigns (builder with live audience count, test send, send/schedule, stats), Delivery (per-channel totals, filters, retry).
- **Tests:** 58 Vitest tests (5 new). Lint, TypeScript and the build are clean.

### Bugs caught during this phase (fixed)
1. **Every link in WhatsApp / push / in-app went to the homepage:** default variables overrode each notification's own `url` (PHP `+` keeps the left side). Caught by the WhatsApp payload test.
2. **New users crashed the router** in strict mode (the new consent columns weren't on freshly created models).
3. **WhatsApp campaigns could be saved without a template** (`required_if` can't look inside an array of channels).
4. **In-app campaign text showed raw Markdown** (`**bold**`) — now rendered to plain text.
5. Plain-text emails linked the brand to the API URL instead of the site; the campaign list didn't refresh after sending; unavailable channels looked toggleable.

### Decisions and limitations (please review)
1. **Polling, not websockets** for the bell (60 s). Laravel Reverb is free but needs another long-running process; say if you want it.
2. **Mail provider:** Mailgun is wired end-to-end (transport + signed delivery webhooks). SES works for sending but its delivery events (SNS) aren't consumed yet.
3. **WhatsApp:** business-initiated messages must use templates you create and get approved in WhatsApp Manager. Suggested names/variables are in `config/notify.php` (`welcome`, `membership_started`, `payment_failed`, `membership_ending`, `certificate_issued`, `live_starting`, `otp_code` as an *authentication* template). Meta charges per conversation/message — confirm the cost before turning it on.
4. **SMS in India** needs DLT-registered templates; MSG91 docs disagree on `template_id` vs `flow_id` — I used `template_id` (current docs); verify with your MSG91 account. MSG91 delivery reports aren't consumed yet (Twilio's are).
5. **Transactional mail can't be switched off** (invoices, payment failures, verification, password reset) and has no unsubscribe link; everything else has one.
6. Inactivity nudges go to **members** only, once per inactivity streak; expiring reminders only for memberships that **won't renew** (cancelled at period end, complimentary).
7. Run the worker for all queues: `php artisan queue:work --queue=webhooks,default,notifications,mail` (Phase 4's note said only `default` — updated).

### Setting up providers
- **Mailgun:** `MAIL_MAILER=mailgun`, `MAILGUN_DOMAIN`, `MAILGUN_SECRET` (+ `MAILGUN_ENDPOINT=api.eu.mailgun.net` for EU). Webhooks → `https://<api>/api/v1/webhooks/mailgun` for delivered / opened / failed / complained; copy the **HTTP webhook signing key** to `MAILGUN_WEBHOOK_SIGNING_KEY`.
- **WhatsApp Cloud API:** Meta app → WhatsApp → test number (sandbox) or your number. `WHATSAPP_TOKEN` (system-user token), `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`; webhook URL `https://<api>/api/v1/webhooks/whatsapp` with `WHATSAPP_VERIFY_TOKEN`, subscribe to **messages**.
- **FCM:** Firebase project → service account JSON on the server → `FCM_PROJECT_ID`, `FCM_CREDENTIALS=/path/to/sa.json`. Apps register tokens at `POST /api/v1/me/push-tokens`.
- **SMS:** `SMS_DRIVER=msg91` (+ `MSG91_AUTHKEY`, `MSG91_OTP_TEMPLATE_ID`, `MSG91_ALERT_TEMPLATE_ID`) or `twilio` (+ `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`; status callbacks are sent automatically).

### Env vars added in Phase 8
`MAILGUN_DOMAIN`, `MAILGUN_SECRET`, `MAILGUN_ENDPOINT`, `MAILGUN_WEBHOOK_SIGNING_KEY`, `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_GRAPH_VERSION` (`v26.0`), `WHATSAPP_TEMPLATE_LANGUAGE` (`en`), `SMS_DRIVER` (`log`), `MSG91_AUTHKEY`, `MSG91_OTP_TEMPLATE_ID`, `MSG91_ALERT_TEMPLATE_ID`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`, `FCM_PROJECT_ID`, `FCM_CREDENTIALS`, `OTP_CHANNEL` (`sms`).

## Phase 7 — Affiliates & referrals ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| click → signup → paid subscription → renewal produces correct commissions | ✅ | `Affiliates/AcceptanceTest` through the **real Razorpay webhooks**: click with UTM → signed 60-day cookie → sign-up 3 days later (last-touch, linked to the click) → Pro ₹999 incl. GST → **20 % of net ₹846.61 = ₹169.32**, held 14 days, then approved → renewal → second ₹169.32 → no commission for a renewal after the 12-month window; webhook replays don't duplicate. In the browser: a logged-out visitor opened `/pricing?ref=…&utm_campaign=reels-nov`, registered through the form, the referral was linked to the click (flagged "same IP as affiliate" because the affiliate had logged in from the same machine, then approved in review), the payment produced ₹169.32 and the affiliate dashboard shows it with the funnel and campaign |
| Refund reverses them | ✅ | Full refund → commission reversed (never becomes payable). Partial refund → proportional reduction. Refund **after payout** → negative clawback line netted from the next payout (½ then all). Refund replays are idempotent. Chargeback (Razorpay `payment.dispute.created`) → reversed. Stripe `charge.refunded` is matched to the invoice payment through the PaymentIntent |
| Tests cover attribution edge cases | ✅ | 34 tests in `tests/Feature/Affiliates/*`: last- vs first-touch, cookie expiry (61 days), tampered cookie, unknown/pending/suspended affiliates, suspension after referral, existing members never re-attributed, one attribution per user, mobile `ref` in the body, Google sign-up, level 2, fixed per-currency + tier overrides, first-payment-only mode, self-referral (Gmail dots/+tags), same device (blocked), same IP (review queue → approve/reject), shared card/UPI fingerprint, IP velocity, hashed identifiers |

### Done — backend
- **Tables:** `affiliates`, `referral_clicks`, `referrals`, `commissions`, `affiliate_payouts`, `referral_rewards`; payments gain `gateway_reference`, `payment_fingerprint`, `refunded_amount`, `refunded_at`, `disputed_at`; login activities gain `device_hash`; users gain `invite_code`.
- **Refunds & chargebacks (new in billing):** Stripe `charge.refunded` / `charge.dispute.created` / `charge.succeeded` (card fingerprint) and Razorpay `refund.processed` / `payment.dispute.created` (+ card/UPI fingerprint on charges). `RefundRecorder` stores the gateway's cumulative refunded total (idempotent, partial refunds supported) and fires `PaymentRefunded` / `PaymentDisputed`. **Phase 6 earnings now exclude the refunded share of partly refunded payments.**
- **Tracking & attribution:** `POST /referrals/track` (called by the SPA for any `?ref=` / `?invite=` URL) records the click (keyed-hash IP, user agent, landing page, referrer, UTM, visitor id) and sets an HMAC-signed 60-day cookie; last-touch by default, first-touch configurable. New accounts — form, Google, or mobile (`ref` / `invite` in the body) — are attributed once via the `Registered` event.
- **Commissions:** `CommissionEngine` on `PaymentSucceeded` — subscription payments (and course sales, configurable); net of tax; percent or fixed-per-currency from the affiliate's override or tier (standard 20 %, partner 30 %); renewals within 12 months; optional level 2 (5 % to the recruiting affiliate); 14-day hold → `affiliates:release` (hourly) approves.
- **Fraud guards:** blocks self-referral (same canonical email) and the affiliate's own device; flags same IP as the affiliate, sign-up velocity (per hour / per IP per day) and a shared card/UPI fingerprint (checked at payment and again before release) → commissions held in the **admin review queue** (approve releases, reject reverses). IPs and device ids are stored only as keyed hashes.
- **Student invites:** personal invite link; when the friend's first paid membership clears the hold, the inviter gets 30 free days (complimentary access), or — if already paying — the days are **banked** and applied automatically when the paid plan ends. Refund during the hold cancels it; max 12 rewards a year; affiliate attribution wins over an invite.
- **Payouts:** minimum ₹1,000 / $25, monthly batch on the 5th (`affiliates:payouts`), only for approved balances with payout details; CSV export (decrypted details, formula-injection safe, admin only); mark paid / mark failed (commissions return to the balance); a `PayoutGateway` interface (manual today; Stripe Connect / RazorpayX can implement it).
- **Admin API** (UI in Phase 9): applications/approve/reject/suspend, commission terms per affiliate, review queue, payouts.
- **Tests:** 300 Pest tests (34 new), PHPStan level 6, Pint.

### Done — frontend
- Global `?ref` / `?invite` capture on every page (UTM, referrer, once per session) and an `X-Device-Id` header (random id in localStorage) for fraud checks.
- **Affiliate area** (`/app/affiliate`): application → pending → dashboard with tabs: Overview (link + copy + WhatsApp / LinkedIn / X / native share, terms in plain words, pending / payable / paid balances, clicks → sign-ups → paying funnel with rates, daily charts, top campaigns), UTM link builder, Commissions (hold dates, review holds, reversals), Payouts (encrypted payout details, history), Marketing assets (3 SVG banners, swipe copy).
- **Invite friends** (`/app/invite`): link, reward explanation, stats, invite statuses. The profile page's Phase 1 "activate affiliate mode" button now links to these pages.
- **Tests:** 53 Vitest tests (7 new). Lint, TypeScript and the build are clean.

### Bugs / gaps caught during this phase (fixed)
1. **Refunds weren't handled anywhere** before this phase (no refund or chargeback webhooks) — added for both gateways; affects Phase 6 earnings too.
2. **Stripe refunds couldn't be matched** to subscription payments (stored by invoice id; refunds reference the PaymentIntent) — the invoice's PaymentIntent is now stored as `gateway_reference`.
3. **Google sign-ups skipped the `Registered` event**, so they would never have been attributed.
4. `/me` still reported the Phase 1 one-click "affiliate mode"; it now reflects the affiliate programme (and the one-click endpoint is gone).

### Decisions and limitations (please review)
1. **Commission defaults:** 20 % (partner tier 30 %) of net-of-tax payments for 12 months after the first payment, course sales included, 14-day hold, last-touch, 60-day cookie, level 2 off. All are env-configurable.
2. **Same IP as the affiliate is "review", not "block"** (shared Wi-Fi, families); same device or same inbox is blocked. Student invites are stricter (same IP rejects) because there's no review queue for them.
3. **Chargebacks reverse immediately**; if you later win the dispute, the commission isn't restored automatically (admin can re-approve via a manual adjustment — not built yet).
4. **Credit rewards** (`STUDENT_REFERRAL_REWARD=credit`) are recorded as a balance but **not yet applied at checkout** — free days are the working default.
5. **Payouts are recorded, not sent**; Stripe Connect / RazorpayX would be paid services and only exist as an interface.
6. **No TDS** (194H applies to commission in India above the threshold) — check with your accountant before paying Indian affiliates at scale.
7. **Admin UI** for affiliates is Phase 9 as specified; everything is available through the admin API now.
8. The tracking cookie is first-party, `HttpOnly`, `SameSite=Lax`, and only set for visitors who aren't logged in. Mention it in your cookie notice/privacy policy.

### Env vars added in Phase 7
| Var | Default | Purpose |
|---|---|---|
| `AFFILIATE_COOKIE_DAYS` | `60` | Attribution window |
| `AFFILIATE_ATTRIBUTION` | `last_touch` | or `first_touch` |
| `AFFILIATE_RECURRING_MONTHS` | `12` | Renewals commissioned for this long (0 = first payment only) |
| `AFFILIATE_INCLUDE_COURSE_SALES` | `true` | Commission on one-time course purchases |
| `AFFILIATE_LEVEL2_ENABLED` / `_PERCENT` | `false` / `5` | Second-level commission |
| `AFFILIATE_HOLD_DAYS` | `14` | Before a commission becomes payable |
| `AFFILIATE_MIN_PAYOUT_INR` / `_USD` | `100000` / `2500` | Payout thresholds (minor units) |
| `STUDENT_REFERRALS_ENABLED` / `STUDENT_REFERRAL_REWARD` / `STUDENT_REFERRAL_DAYS` | `true` / `days` / `30` | Invite-a-friend rewards |

## Phase 6 — Instructor dashboard & earnings ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| An instructor runs the whole course lifecycle without admin help except publish approval | ✅ | `Instructors/LifecycleAcceptanceTest`: after the one-time application approval, the instructor alone sets up profile/payouts, creates the course, thumbnail, section, text/quiz/assignment lessons, quiz questions, assignment settings; **the only admin call is publish approval**; then sees students, answers Q&A, grades, announces, edits the live course, schedules a live session, reads analytics and earnings. A second test sweeps every instructor endpoint as another instructor (403 / empty lists). In the browser: dashboard, Q&A inbox answer, earnings |
| Earnings report matches seeded data | ✅ | `EarningsDemoSeeder` (October 2026, figures in the table below) + `Instructors/EarningsTest` asserts every statement to the paisa/cent, plus line items, refunds, failed payments, out-of-period payments, a complimentary member's views, a custom share, finalize → pay, and "finalized statements are never rewritten". Locally: `php artisan db:seed --class=EarningsDemoSeeder && php artisan earnings:generate 2026-10` gives the same numbers in the UI |

**Seeded October 2026 (expected → actual):**

| | INR | USD |
|---|---|---|
| Net membership revenue (refunded & failed ignored) | 3,000.00 | 20.00 |
| Pool (50 %) | 1,500.00 | 10.00 |
| **Meera** pool (6,000 of 9,000 member-seconds) + sales (70 %) | 1,000.00 + 0 = **1,000.00** | 6.66 + 34.30 = **40.96** |
| **Ivan** pool (3,000 / 9,000) + sales (custom 60 % of 500.00) | 500.00 + 300.00 = **800.00** | 3.33 + 0 = **3.33** |

### How earnings work (please confirm, it's money)
- **Membership pool** = net membership revenue of the month (paid amount − GST, succeeded payments only) × `EARNINGS_POOL_PERCENT` (50). Each instructor gets the pool × *their courses' pool watch time / all pool watch time*.
- **Pool watch time** counts only: membership (tier) courses, watched by members with a **paid** subscription through their membership. Server-capped heartbeat seconds (Phase 4), at most 4 h per member per day. Complimentary members, staff and the course's own instructors never count. Rewatching counts (it's real engagement).
- **Course sales** = net sale × the instructor's revenue share (default 70 %, admins can set it per instructor).
- Per currency (INR and USD are separate statements); amounts are rounded down and leftovers stay with the platform. Courses are credited to their primary instructor (co-instructors don't split yet).
- **Statements:** `earnings:generate` runs on the 1st (04:00 UTC) for last month and writes **drafts**. Admins review, **finalize** (locked: later refunds won't change them) and **mark paid** with a transfer reference. The current month shows a live estimate.

### Done — backend
- **Tables:** `instructor_applications`, `instructor_profiles` (revenue share, payout method, encrypted payout details + tax ID), `course_watch_daily` (`seconds` for analytics, `pool_seconds` for earnings), `course_questions` / `course_answers`, `course_announcements`, `payout_statements` / `payout_statement_lines` (with % snapshots for audit). Plus `config/earnings.php`.
- **Application flow:** apply (validated, one pending at a time; admins emailed) → approve (instructor role, headline/expertise copied to the profile, email) or reject with notes (email; can re-apply).
- **Instructor profile:** headline, bio, expertise, website, social links; payout method bank (account + IFSC validated) / UPI / PayPal and PAN, **encrypted at rest and returned masked**. Only the admin "pay" view decrypts them.
- **Teaching tools:** students per course (progress, completion, quiz average, last activity, search/sort), Q&A (learners with access ask per lesson, anyone in the course can reply, an instructor reply marks it answered and emails the asker; authors delete their own posts, instructors moderate), announcements (sanitised HTML, emailed to enrolled students, shown on the course page).
- **Analytics:** students, new enrolments, completion rate, average quiz score, watch time, rating, live attendance, open questions, submissions to grade; daily series; 7-month earnings trend; per-course table; recent-activity feed.
- **Admin:** applications queue, payouts (compute / finalize / mark paid / per-statement payout details), per-instructor revenue share.
- Existing instructor endpoints (courses, curriculum, quizzes, assignments, grading, live) were already scoped by policy; the new cross-instructor test proves it.
- **Tests:** 266 Pest tests (15 new in `tests/Feature/Instructors/*`), PHPStan level 6, Pint.

### Done — frontend
- **Instructor area:** dashboard (8 KPI tiles, 7/30/90-day range, Recharts: daily enrolments, daily watch time, monthly earnings per currency with "this month so far", each chart with a **table view**; per-course table; recent activity), Students, Q&A inbox, Announcements, Earnings & payouts (formula explained, statements, line-item details), Teaching profile (public profile + payout details). Navigation lists all of them; existing My courses, Live sessions and Grading are linked.
- **Learners:** "Teach with us" application page (status, rejection notes, re-apply); a real **Q&A tab** in the lesson player (this lesson / whole course, ask, reply, delete own; `?tab=qa` deep link from emails); announcements on the course page.
- **Admin:** Applications and Payouts pages.
- **Charts** follow the dataviz rules: single series per chart (no dual axes), ≤ 24 px bars with 4 px rounded ends, 2 px line with a 10 % wash, hairline grid, hover tooltip, table view. The mark colour is validated (light: brand indigo; dark: a separate `--chart-1` at L 0.65, because the brand's dark-mode indigo fell outside the readable lightness band).
- **Tests:** 46 Vitest tests (5 new). Lint, TypeScript and the build are clean.

### Bugs caught during this phase (fixed)
1. **Demo seeder created a stray, active pricing plan** (a model factory made its own plan). Caught by the existing seeder test; the seeder now reuses a real plan, and I removed the stray plan from the local database.
2. **Rating KPI disagreed with the course table** (review rows vs cached course ratings); both now use the same source.
3. Earnings charts in a second currency only showed months with data; month labels like "Oct 26" read as dates.
4. The payout form cleared typed details before the save succeeded.
5. Paying an instructor with no payout details on file is now flagged in the pay dialog.

### Decisions and limitations (please review)
1. **The earnings formula above** (pool %, what counts as watch time, daily cap, rounding, refunds after finalising) — please confirm before real payouts.
2. **Co-instructors** don't share earnings yet; the primary instructor receives the course's earnings.
3. **Payouts are recorded, not sent** — admins pay by bank/UPI/PayPal outside the platform and enter the reference. Automating transfers (e.g. RazorpayX Payouts) would be a paid service; I haven't added one.
4. **No TDS/withholding** is calculated on statements. Indian instructors may need TDS under section 194J/194-O — check with your accountant.
5. **Instructors see learners' emails** in the student list (as in the Phase 4 grading queue). Say if you'd rather hide them.
6. Q&A answers are plain text; no voting or accepted answers yet.

### Env vars added in Phase 6
| Var | Default | Purpose |
|---|---|---|
| `EARNINGS_POOL_PERCENT` | `50` | Share of net membership revenue that forms the instructor pool |
| `EARNINGS_DEFAULT_SHARE_PERCENT` | `70` | Default instructor share of course sales |
| `EARNINGS_MAX_SECONDS_PER_USER_DAY` | `14400` | Anti-gaming cap on one member's daily pool watch time |

## Phase 5 — Live classes & webinars ✅ code complete · ⏳ real Zoom run needs keys (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Instructor schedules a session | ✅ | `Live/LiveAcceptanceTest` + `LiveSchedulingTest`: a Zoom meeting is created through the Server-to-Server OAuth API (request body checked: scheduled type, timezone, duration, cloud auto-recording). Weekly series keep the same local time across a DST change; a failure part-way rolls back meetings already created. In the browser: the instructor scheduled a one-off session and a 4-week series from the UI (external link, because Zoom has no keys locally) |
| Pro members register and join from the browser | ✅ tests · ⏳ real Zoom | Pro registers (email with an .ics invite); Starter gets 402 "upgrade". Join opens 10 min before the start and returns a Meeting SDK JWT (claims and HMAC verified in the test). In the browser: register → "Join now" turned on → the external link opened and attendance was recorded. **Embedded Zoom:** the real SDK loaded in the browser and sent our signed join request to Zoom, which answered "meeting number not found" (a made-up meeting, as expected). This shows the round trip reaches Zoom but does not prove the signature is accepted. A full embedded join needs your Zoom keys |
| Attendance and recording sync back automatically | ✅ tests · ⏳ real Zoom | Signed `meeting.ended` webhook → participant report (matched by the SDK `customer_key`, then email; re-joins summed) → attended with minutes and points. `recording.completed` → Bunny pulls the MP4 using Zoom's download token → replay lesson added to the course (optional, so completed courses stay at 100 %) → Replays library with tier gating. In the browser: recording import through the demo video driver → the Replays page lists it and it plays |

### Done — backend
- **Tables:** `live_series` (weekly rule: weekdays, local time, timezone, occurrences), `live_sessions` (everything in the spec, plus `series_id`, `timezone`, encrypted `join_url`/`host_url`/`meeting_password`, `recording_video_id`, `replay_lesson_id`, `started_at`/`ended_at`, `attendance_synced_at`, `cancel_reason`), `live_session_registrations` (`attended`, `joined_at`, `left_at`, `attended_seconds`, reminder timestamps). `lessons.is_optional` (replays don't count toward course completion).
- **Providers:** `LiveProvider` interface; `ZoomProvider` (S2S OAuth token cached, create/update/delete meetings, fresh host link, Meeting SDK JWT, participant report with paging, webhook signature + URL validation + 5-minute replay window); `ExternalLinkProvider` (Google Meet etc.; attendance = who clicked Join). `agora` / `100ms` are valid values that return "not set up yet".
- **Scheduling:** one-off or weekly series in the instructor's timezone (DST-safe); edits are pushed to Zoom and re-arm reminders; cancel one or "this and following", which deletes the Zoom meetings and emails registrants. Course sessions inherit the course tier; 1:1 mentorship is forced to one seat.
- **Registration/join:** tier check (re-checked at join time), capacity with a row lock, join window (10 min before → scheduled end, or while live), instructors/admins always allowed.
- **Calendar:** RFC 5545 `.ics` per session and "all my sessions" (UTC times, 15-min alarm, correct line folding), Google Calendar link with `ctz`. Neither contains the meeting link or passcode; both point at the in-app room.
- **Reminders:** `live:maintain` (every minute) fires `LiveSessionReminderDue` at 24 h and 15 min (once each; people who register inside the 24-hour window skip that one). Today's listener sends an email; **Phase 8 adds channels as more listeners**. The same command marks external sessions live, ends sessions whose slot is over (with or without a webhook) and queues the attendance sync.
- **Recordings:** `ImportLiveRecording` (encrypted on the queue because it carries Zoom's download token) → Bunny "fetch from URL" → the video is found by a marker in its title (Bunny doesn't return an id) → synced → replay lesson. Instructors can also attach a recording manually (e.g. for Google Meet sessions).
- Points for attending (15). The dashboard "upcoming live classes" slot now shows your registrations.
- **Tests:** 251 Pest tests (24 new in `tests/Feature/Live/*`), PHPStan level 6, Pint.

### Done — frontend
- **Live classes** (`/app/live`): list and month calendar (in your timezone; registered sessions highlighted), filters for tier, instructor and "only mine", register, countdown, "Join now" that switches on by itself 10 min before, Google Calendar / .ics, "My sessions (.ics)".
- **Live room** (`/live/:id`): waiting room with countdown → embedded Zoom (Meeting SDK component view, lazy-loaded, passes the attendance key) with an "Open in Zoom" fallback in the header and on any SDK error; external links open in a new tab.
- **Replays** (`/app/replays`, `/app/replays/:id`): library with tier locks, secure player; course replays open the course lesson.
- **Instructor** (`/instructor/live`): schedule dialog (type, tier, course, date/time/timezone, duration, capacity, weekly repeat, provider), start as host (fresh link), registrants/attendance with "sync now", cancel (incl. following sessions).
- **Tests:** 41 Vitest tests (7 new: join enables at T-10 with fake timers, register / upgrade, replay link, embedded join passes the customer key, external link opens a tab, countdown before opening, calendar placement). Lint, TypeScript and the build are clean. The Zoom SDK is a separate ~3.3 MB chunk loaded only when someone joins.

### Bugs caught during this phase (fixed)
1. **Scheduling failed for Indian browsers:** Chrome reports the timezone as `Asia/Calcutta` (a legacy alias), which my validation rejected, and the dialog didn't show that error. The rule now accepts aliases (as the Phase 1 forms already did), and the dialog lists every server error. Covered by a test.
2. **Calendar files:** folded lines were 76 bytes (the leading space counts toward RFC 5545's 75-byte limit).
3. **Registering for a cancelled session** said "upgrade" instead of "cancelled".
4. **Replays would have reopened finished courses** (an extra lesson drops the percent). Replays are now optional lessons.
5. **Dev only:** the first visit to the live room made Vite re-optimise its dependencies mid-session and blanked the page. The Zoom SDK is now pre-bundled at dev-server start.
6. Calendar chips overflowed into the next day.

### Decisions and limitations (please review)
1. **Zoom plan:** cloud recording and the participant-report API need a **paid Zoom plan (Pro or higher)**. Zoom is named in the spec, but I'm flagging the cost as the brief asks. Without it: meetings and embedded join still work; attendance falls back to live join events, and recordings must be attached manually.
2. **One Zoom host account** (`ZOOM_HOST_USER`, default the account owner): Zoom allows one meeting per host at a time, so overlapping sessions need extra licensed users. Per-instructor Zoom users are a follow-up.
3. **Embedded join and OBF:** meetings are created in your own Zoom account, so the Meeting SDK doesn't need Zoom's new "On Behalf Of" token (that applies only to meetings hosted by other accounts). The Meeting SDK app must be in the **same Zoom account** as the S2S app.
4. **Attendance rule:** attended = at least 5 minutes in the meeting (or half the session if it's shorter than 10 minutes), summed across re-joins. Unregistered participants (host, guests) are ignored.
5. **Each weekly occurrence is its own Zoom meeting** (separate rosters, recordings and replays). Editing one occurrence doesn't change the others.
6. **Agora / 100ms** are accepted values without adapters; adding one means implementing `LiveProvider`.
7. **Reminders are email-only** until Phase 8.
8. Production needs `php artisan schedule:work` (or cron) for `live:maintain`, and the queue worker for `webhooks,default,mail`.

### Setting up Zoom
1. Zoom Marketplace → **Server-to-Server OAuth** app. Scopes: create/update/delete/read meetings (`meeting:write:meeting:admin`, `meeting:update:meeting:admin`, `meeting:delete:meeting:admin`, `meeting:read:meeting:admin`) and the participant report (`report:read:list_meeting_participants:admin`, confirmed in Zoom's API reference). *The meeting scope names are from memory; pick the matching ones in Zoom's scope picker.* No recording scope is needed, because the webhook carries a download token. Copy the Account ID, Client ID and Client Secret.
2. In that app, add an **Event Subscription** to `https://<api>/api/v1/webhooks/zoom` with: `meeting.started`, `meeting.ended`, `meeting.participant_joined`, `meeting.participant_left`, `recording.completed`. Copy the **Secret Token** → `ZOOM_WEBHOOK_SECRET` (the endpoint answers Zoom's URL validation automatically).
3. A **General app with Meeting SDK** enabled, in the same account → Client ID/Secret as `ZOOM_SDK_KEY` / `ZOOM_SDK_SECRET`.
4. Turn on cloud recording in the account settings.

### Env vars added in Phase 5
| Var | Default | Purpose |
|---|---|---|
| `ZOOM_ACCOUNT_ID` / `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET` | — | Server-to-Server OAuth (meetings, reports) |
| `ZOOM_HOST_USER` | `me` | Zoom user that hosts created meetings |
| `ZOOM_SDK_KEY` / `ZOOM_SDK_SECRET` | — | Meeting SDK (embedded join). Without them, learners get the Zoom link instead |
| `ZOOM_WEBHOOK_SECRET` | — | Event subscription secret token |
| `ZOOM_AUTO_RECORDING` | `cloud` | `cloud` \| `local` \| `none` |

## Phase 4 — Learning experience: progress, quizzes, assignments, certificates, gamification ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Progress survives refresh / device switch | ✅ | `Learning/ProgressTest`: heartbeats sent with a "phone" token are read back by a "laptop" token (same position, watch time and last lesson). In the browser: 20 s of playback credited 19 s through heartbeats, then after a reload the player resumed at 0:19; seeking to 5:00 moved the resume point but credited no watch time |
| Quiz auto-grades | ✅ | `Learning/QuizTest`: all four question types, no partial credit on multi-select, case/space-insensitive short text, pass mark, attempt limit, server-side timer (late answers discarded), answers hidden until submit. In the browser: timed attempt with autosave → 80 % passed, review with correct answers and explanations, lesson marked complete |
| A completed course issues a verifiable certificate | ✅ | `Learning/CertificateTest`: completing the last lesson queues recalculation → certificate (PDF stored, learner notified, issued once), public verification by code, revoked = invalid, owner-only PDF, LinkedIn prefill URL. In the browser: the real queue worker ran `RecalculateCourseProgress` → `IssueCertificate`; Certificates page (PDF, Add to LinkedIn, verify link) and the public `/verify/ACABQSAEPA` page while logged out |

### Done — backend
- **Tables:** `lesson_progress.watched_seconds/last_heartbeat_at`, `course_progress`, `bookmarks`, `quizzes`, `quiz_questions`, `quiz_options`, `quiz_attempts`, `quiz_answers`, `assignments`, `assignment_submissions`, `certificates` (uuid, unique verification code, name snapshots, `revoked_at`), `points_ledger`, `badges`, `user_badges`, `learning_days`; `user_profiles.skills/learning_goals/is_public/public_handle`. The course-level `last_lesson_id`/`completed_at` moved from `enrollments` to `course_progress`.
- **Progress:** 15 s heartbeat with server-capped credit (see ARCHITECTURE.md). Complete at 90 % watched. Course percent recalculated by a queued, unique job. Text/PDF lessons can be marked complete manually; video/quiz/assignment can't.
- **Quizzes:** single choice, multiple choice, true/false, short answer. Time limit enforced on the server, autosave, resume, attempt limit, per-attempt shuffle, optional reveal of answers and explanations. The instructor builder edits in place and refuses to delete answered questions.
- **Assignments:** instructions, due date relative to enrolment, file types/size, optional text; resubmission until graded; instructor grading queue with grade / return-for-changes; learner notified.
- **Certificates:** PDF on completion, public `/certificates/verify/{code}`, LinkedIn "Add to profile" URL, notification email.
- **Gamification:** points (lesson 10, quiz pass 20, perfect quiz +10, assignment submitted 15, course 100), idempotent ledger; daily streaks in the learner's timezone; badges: First steps, Course finisher, Quiz ace, On a roll (3-day streak), Week warrior (7-day streak), High achiever (500 points).
- **Learner area:** dashboard, achievements, bookmarks, notes across courses, certificates, learning profile + opt-in public profile (`/learners/{handle}`).
- Course readiness now requires quiz lessons to have questions and assignment lessons to have settings.
- Demo data: `LearningDemoSeeder` adds a quiz and an assignment to every seeded course (idempotent; runs as part of `db:seed`).
- **Tests:** 227 Pest tests (34 new across `tests/Feature/Learning/*`), PHPStan level 6, Pint, all passing.

### Done — frontend
- **Lesson player:** 15 s heartbeats with real watch-time accounting (seeks excluded; flushed on pause/leave), completion hint per lesson type, bookmark toggle, quiz player (timer with assertive warning in the last minute, autosave, unanswered-question confirm, auto-submit at 0, review screen with explanations, retake), assignment panel (instructions, due/overdue, upload with client-side type/size checks, status, score and feedback, resubmit).
- **Student area:** new dashboard (streak, points, progress rings, continue learning, pending assignments, live-classes slot, recent badges, recommended courses in your tier), My learning (in progress / completed), Certificates (PDF, Add to LinkedIn, copy/verify link), Bookmarks, Notes (search, grouped by course), Learning profile (skills, goals, public handle/toggle, achievements grid).
- **Public:** `/verify/:code` and `/u/:handle`.
- **Instructor:** quiz builder and assignment settings inside the lesson editor; Grading page (`/instructor/grading`).
- Course page: progress bar, "Review course" / "View your certificate", "Save for later".
- **Tests:** 34 Vitest tests (9 new: quiz flow and review, confirm on unanswered, timer auto-submit, attempts exhausted, assignment errors/graded view/file-size check, verification page). ESLint, TypeScript and the build are clean.

### Bugs caught during this phase (fixed)
1. **Quiz edits were blocked:** `Eloquent\Collection::except()` filters by primary key, not by the collection key, so the builder treated every existing question as "removed". It now filters by uuid; a test covers editing a quiz after learners have answered it.
2. **My first quiz-builder design** deleted and recreated questions on save, which would have cascade-deleted learners' answers. Rewritten to update in place.
3. **Assignments:** empty submissions were accepted; a text-only resubmission deleted earlier files from disk while keeping them on the submission (download → 500). Text-only revisions now keep the files.
4. **Bookmarks** could be added for lessons the learner couldn't open.
5. **Learning profile:** the first save of a new profile returned an empty payload (stale cached relation).
6. **Certificate PDF footer** rendered outside the frame and off the page (found by rendering the PDF). dompdf ignores `bottom` on nested absolute boxes; the footer now uses normal flow.

### Decisions and limitations (please review)
1. **Videos complete only by watching 90 %.** There's no manual "mark complete" for video, quiz or assignment lessons. Quizzes complete on a pass; assignments complete when graded at or above the pass score (or at any grade if no pass score is set).
2. **Certificates need every lesson,** including drip-scheduled ones, so a course with drip can't be finished before its last section unlocks.
3. **Multiple-choice questions are all-or-nothing** (no partial credit).
4. **Certificate revocation** is supported by the data model and the verify page, but there is no admin button for it yet.
5. **LinkedIn:** the prefill sends the organisation *name*. Linking to a LinkedIn company page needs its `organizationId`; send me the ID if you want it.
6. **Live classes:** the dashboard slot is ready; the list stays empty until the live-classes phase. *(Filled in Phase 5.)*
7. **Local queue:** progress percent and certificates are queued. Run `php artisan queue:work --queue=webhooks,default,notifications,mail` locally (or set `QUEUE_CONNECTION=sync`).
8. Certificate PDFs use the same private disk as invoices (`INVOICE_DISK`). No new env vars this phase.

## Phase 3 — Courses, curriculum builder, secure video, catalogue ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Instructor uploads a course | ✅ | `Courses/AcceptanceTest`: create, thumbnail, section, **Bunny TUS ticket (signature checked)**, signed Bunny webhook marks the video ready, lesson attached. In the browser: the real upload component (progress 0 → 100%, "Video ready", attached) |
| Admin publishes it | ✅ | Submit runs readiness checks, admin approves, course appears in the catalogue; reject-with-notes path also tested |
| A Pro member can watch | ✅ | Playback 200 with a Bunny token-signed HLS URL (`Cache-Control: no-store`). In the browser: the Plyr + hls.js player plays, speed menu, resume at 02:00 after reload |
| A Starter member sees the lock | ✅ | API: course page lessons `unlocked:false, lock_reason: membership_required`; playback **402** `required_tier_slug: pro`. In the browser: lock icons + "Upgrade to Pro" |
| Signed URLs expire | ✅ (signing) · ⏳ (CDN enforcement) | The expiry is part of the HMAC: changing it changes the token, so a URL can't be extended. TTL = video length + 15 min, clamped to 15 min–4 h. The signer matches Bunny's reference algorithm (`url_signing.php`, re-implemented in the test). **Actually rejecting an expired URL is done by Bunny's CDN, which needs a real pull zone to verify** |

### Done — backend
- **Tables:**
  - from the spec: `categories`, `courses` (incl. required_tier, status draft/review/published, price for one-time purchase, cached rating/duration/lesson counts), `course_sections`, `lessons` (video/text/pdf/quiz/assignment/live, is_preview, drip_days, sort), `lesson_resources`, `course_instructors` (co-instructors), `tags`/`course_tag`, `course_reviews`
  - additions: `videos` (the video library), `enrollments` (drip clock, continue-learning), `lesson_progress` (resume position, completion), `lesson_notes`, `course_purchases`; `payments.course_id`
- **Video:**
  - a `VideoProvider` adapter with `BunnyStreamProvider` and a `DemoVideoProvider` for local dev; Mux or Vimeo can be added as more drivers
  - **direct browser upload over TUS** with a server-signed ticket, so no API key reaches the browser
  - **signed webhook** (`X-BunnyStream-Signature`, HMAC-SHA256 of the raw body with the read-only key, deduplicated by `EventId`) → queued `SyncVideo` job stores duration + thumbnail and updates lesson and course totals
  - **playback only after `CourseAccessService` passes** (which uses `MembershipService::canAccess`), using a token in the path so HLS segments inherit it
- **Download protection:** no MP4 URLs are ever exposed, the player has no download control, context menu is disabled, and URLs expire. This deters downloads but can't fully prevent them: anyone who can play a stream can record it. The Bunny library's "MP4 fallback" setting must be turned off.
- **Access:**
  - **automatic by tier** (no manual enrol step; enrolment is recorded on first access to start the drip clock)
  - **optional one-time purchase** of a single course (Stripe Checkout with inline price, or a Razorpay order), completed by the existing payment webhooks
  - previews are open to guests; drip unlocks a lesson `drip_days` after the learner started
  - staff and the course's instructors bypass tier and drip
- **Workflow:**
  - draft → submit (readiness checks: description, category, thumbnail, at least one lesson, videos processed, PDFs attached) → review (editing paused for instructors) → admin approves (published) or rejects with notes (back to draft)
  - emails go both ways; admins can also feature or unpublish courses
- **Catalogue API:**
  - filters: category, level, tier (incl. free), duration buckets, minimum rating, featured
  - sorts: popular, newest, rating, duration, relevance
  - **Scout full-text search** (Meilisearch in production with index settings; the `collection` engine locally and in tests), pagination
  - course detail with the curriculum outline and per-lesson lock state for the viewer, instructor cards, reviews
- **Learning API:** lesson content, previous/next, resume position, completion (explicit or at 90% watched, plus course completion), timestamped notes, resource downloads and inline PDF lessons streamed from a private disk.
- **Reviews:** one per learner (an update replaces it); only learners who started the course can review; rating average and count kept up to date.
- **Rich text:** instructor HTML is sanitised on the server (HTMLPurifier allow-list: no scripts, inline handlers, `javascript:` links or images) and again with DOMPurify in the SPA.
- **Quality:** **193 Pest tests / 858 assertions**, PHPStan level 6 clean, Pint clean.

### Done — frontend
- **Catalogue:** search (debounced), category chips, filters for tier/level/duration/rating, sorting, pagination; all state lives in the URL. Course cards show lock badges and an "Upgrade to …" button.
- **Course detail:** curriculum accordion with lock, drip and completion states; preview lessons play in a dialog; instructor cards; reviews with a rating form; tier badge; a context-aware call to action (Start/Continue learning · Upgrade to X · Buy this course · Join).
- **Lesson player (`/learn/:slug/:lesson`):**
  - Plyr + hls.js with a speed menu, **resume position**, and progress saved every 10 s and on pause
  - automatic re-signing: when a URL expires (403), it fetches a new one and resumes at the same position
  - curriculum sidebar with completion ticks and drip dates
  - previous/next, mark complete
  - tabs: Overview, Notes (timestamped), Resources (download), Q&A (placeholder)
  - inline PDF viewer
- **Instructor studio:**
  - course list and "new course"
  - editor with a readiness checklist, review notes, submit for review
  - **dnd-kit curriculum builder**: sections and lessons, lessons can move between sections, keyboard-accessible, with screen-reader announcements by title
  - lesson editor: **Tiptap**, type, preview, drip, **direct video upload with a progress bar** (TUS, resumable), PDF and resource upload
  - details form: Tiptap description, category, tier, level, language, price, tags, thumbnail
- **Admin:** course review queue (approve, or request changes with notes), feature toggle.
- **Student:** "My courses" with progress bars. The home page's featured courses now come from the catalogue API.
- **Quality:** 25 Vitest tests, ESLint (incl. jsx-a11y) and strict TypeScript clean, build OK.

### Bugs caught during this phase (fixed)
1. **The player didn't start (found in the browser):** `Plyr.destroy()` swaps the `<video>` element for a clone. After React's dev-time double mount, or on any lesson change, Plyr ended up attached to a detached element. The component now creates and owns its `<video>` element.
2. **Missing enrolments:** a member who opened a *preview* lesson first, or only posted progress, was never enrolled, which broke resume, drip and course completion. Anyone with course access is now enrolled.
3. **Expiry used `time()` instead of the app clock**, so expiry didn't follow the framework's (or tests') time.
4. **Screen-reader output:** drag-and-drop announcements read raw UUIDs aloud; they now use section and lesson titles.
5. Smaller issues: a config edit that broke the Scout file, a factory that overwrote explicit publish dates.

### Decisions and limitations (please review)
1. **Real Bunny test needed.** Everything up to the HTTP boundary is tested, and the token signer matches Bunny's reference code. A live run needs a Bunny Stream library and its keys (below), especially to confirm expired URLs are rejected at the CDN.
2. **No captions yet.** Bunny supports VTT caption tracks; wiring them into the player is a follow-up. This is an accessibility gap for hearing-impaired learners.
3. **Quiz, assignment and live lesson types** can be created and ordered. *(Quizzes and assignments were completed in Phase 4; live classes are still to come.)*
4. **URL lifetime** = video length + 15 minutes (minimum 15 min, maximum 4 h), because HLS fetches segments throughout playback. The player re-signs automatically when a URL expires.
5. **Search:** the local `collection` engine is fine for development only. Production needs Meilisearch (Sail service) and `php artisan scout:sync-index-settings && php artisan scout:import "App\Models\Course"`.
6. **Demo video driver** (`VIDEO_DRIVER=demo`, the local default): uploads are accepted and discarded, and playback uses a public test stream with **no protection**. Never use it in production.
7. A course **in review is read-only for instructors**; published courses stay editable (changes go live immediately, and the editor says so).

### Setting up Bunny Stream
1. Create a Stream library. Copy the **Library ID**, the **API key**, and the **Read-Only API key** (it signs webhooks).
2. On the library's pull zone, enable **Token Authentication** and copy the **token security key**. Turn **MP4 fallback off**.
3. Set the webhook URL to `https://<api>/api/v1/webhooks/video`.
4. `.env`: `VIDEO_DRIVER=bunny`, `BUNNY_STREAM_LIBRARY_ID`, `BUNNY_STREAM_API_KEY`, `BUNNY_STREAM_READ_ONLY_KEY`, `BUNNY_STREAM_CDN_HOSTNAME` (e.g. `vz-xxxx.b-cdn.net`), `BUNNY_STREAM_TOKEN_KEY`.

### Env vars added in Phase 3
| Var | Default | Purpose |
|---|---|---|
| `VIDEO_DRIVER` | `demo` (local) / `bunny` | Video provider |
| `BUNNY_STREAM_LIBRARY_ID` / `_API_KEY` / `_READ_ONLY_KEY` / `_CDN_HOSTNAME` / `_TOKEN_KEY` | — | Bunny Stream library + CDN token auth |
| `VIDEO_PLAYBACK_TTL_MIN` / `_MAX` | `900` / `14400` | Signed URL lifetime bounds (seconds) |
| `VIDEO_DEMO_HLS_URL` | Mux public test stream | Demo driver only |
| `COURSE_FILES_DISK` | `local` | Private disk for PDFs/resources |
| `COURSE_THUMBNAILS_DISK` | `public` | Course thumbnails |
| `SCOUT_DRIVER` / `SCOUT_QUEUE` | `collection` locally / `meilisearch`, `true` | Search engine |

## Phase 2 — Memberships, subscriptions, payment gateways, access control ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| Subscribe → renew → fail → downgrade, **Stripe** | ✅ with fixtures · ⏳ real test mode | `StripeLifecycleTest`: signed fixture webhooks for subscription created, invoice paid, renewal paid, `invoice.payment_failed` (past_due, dunning email), 3-day grace, `billing:maintain` (expired, access lost, email) |
| Same cycle, **Razorpay** | ✅ with fixtures · ⏳ real test mode | `RazorpayLifecycleTest`: `subscription.activated`, `.charged`, renewal `.charged`, `.pending`, `.halted`, grace, expired. Also run **live on the dev server**: signed webhooks → queue worker → GST invoice PDF |
| Webhook tests with fixture payloads pass | ✅ | `tests/Fixtures/{stripe,razorpay}/*.json`, real signature schemes, duplicate delivery tested |

**Not yet done:** running against real Stripe/Razorpay **test mode**. It needs your test keys (see "Running in test mode" below). Everything up to the HTTP boundary is tested; Stripe through the real stripe-php SDK with a fake transport, Razorpay with `Http::fake`.

### Done — backend
- **Schema per spec:**
  - `plans` (tier_level 1–3, features json), `plan_prices` (month | year | **lifetime**, amount, currency, stripe_price_id, razorpay_plan_id, trial_days)
  - `subscriptions` (gateway, gateway_subscription_id, status active | trialing | past_due | cancelled | expired (+ incomplete), periods, cancel_at_period_end, plus past_due_since, scheduled change, lifetime and complimentary fields)
  - `payments` (doubles as invoices: amount, subtotal, discount, tax amount/rate/name, gateway ids, invoice_number, pdf_path, billing snapshot)
  - `coupons` (percent | fixed, max_uses, expires_at, plan scope through `coupon_plan`), `coupon_redemptions` (one use per user), `webhook_events` (idempotency), `invoice_counters`
  - Cashier customer columns and GST/VAT billing details on `users`
- **`PaymentGateway` interface:**
  - `StripeGateway`: Cashier Checkout Sessions (subscription or payment mode), Billing Portal, coupons mirrored to Stripe
  - `RazorpayGateway`: Subscriptions API, plus the Orders API for lifetime
  - `GatewayResolver` picks INR → Razorpay, everything else → Stripe; display currency comes from the user's country
- **Webhooks:**
  - `POST /api/v1/webhooks/{stripe,razorpay}`, signature-verified (Stripe `t=,v1=` HMAC with tolerance; Razorpay HMAC-SHA256 of the body)
  - stored once per `(gateway, event_id)` and processed by the queued `ProcessWebhookEvent` job (5 tries, backoff)
  - one `SubscriptionSync` service handles both gateways and accepts both the classic and the 2025+ Stripe payload shapes
- **Renewals and dunning:**
  - a failed renewal sets `past_due` and sends a dunning email; gateways retry on their own (Stripe Smart Retries, Razorpay pending → halted)
  - access is kept for `BILLING_GRACE_DAYS` (3), then `billing:maintain` (hourly) expires the membership and emails the user; a successful retry restores it
- **Plan changes:**
  - **upgrades** take effect immediately; Stripe uses `always_invoice` proration and Razorpay `schedule_change_at=now`, where Razorpay prorates itself
  - **downgrades** wait for period end (Stripe price change with no proration; Razorpay `cycle_end`); access stays at the higher tier until then, and a pending downgrade can be withdrawn
  - proration preview endpoint
- **Cancel and resume:** cancel at period end and resume work on both gateways. Razorpay can't undo a cancellation, so it's only sent 24 hours before renewal; until then, resume is a local change.
- **Lifetime:** one-time purchase on both gateways (Stripe payment mode, Razorpay Orders).
- **Tax and invoices:**
  - prices include tax; GST 18% for India is split out on each invoice
  - gap-free invoice numbers per Indian financial year (`INV-2026-27-000001`)
  - dompdf invoice PDF (font-subset, about 24 KB) on a private disk, downloaded through an authenticated endpoint
- **Access control:**
  - `MembershipService::canAccess(user, GatedContent|tier)`
  - the `GatedContent` contract and `HasRequiredTier` trait (content declares `required_tier`)
  - `tier:2` / `tier:pro` middleware (HTTP 402)
  - the `access-content` gate
  - staff bypass; grace, lifetime and complimentary memberships respected
- **Admin:** plans and prices CRUD (one active price per interval and currency), coupons CRUD, complimentary grant/revoke (open-ended or dated), per-user subscriptions and payments.
- **Commands:** `billing:maintain` (hourly, via the scheduler) and `billing:sync-gateway-plans` (creates missing Stripe prices and Razorpay plans in test mode).
- **Quality:** **155 Pest tests / 664 assertions** across both phases, PHPStan level 6 clean, Pint clean.

### Done — frontend
- **Pricing:**
  - monthly / yearly / lifetime toggle and an INR/USD switch (guests are guessed from their timezone)
  - plan cards with trial labels and a tier **comparison table**
  - checkout dialog with a **coupon field** (live validation, discounted total)
- **Checkout:**
  - Stripe sends the user to Checkout and back to `/billing/success`, which syncs the session and polls until the membership is active
  - Razorpay opens its modal, the server verifies the signature, then the success page shows; `/billing/failed` handles failures and dismissals
- **Billing page (`/app/billing`):**
  - current plan, status badges, renewal or access-until date, and a dunning banner with the grace deadline
  - change plan with a proration preview, scheduled-downgrade notice with "Keep current plan"
  - cancel (confirm dialog) and resume, update card (Stripe Customer Portal)
  - invoices list with PDF download, GST/address details form
- **Locked content:** `LockBadge` and the "Upgrade to Pro" CTA on gated course cards; `TierGate` and `RequireTier` show the same CTA.
- **Admin:** plans page shows prices per gateway, flags missing gateway ids, and lets you activate or deactivate each price.
- **Quality:** 22 Vitest tests, ESLint (incl. jsx-a11y) and strict TypeScript clean, build OK.

### Bugs caught during this phase (fixed and covered by tests)
1. **Possible Razorpay double-charge, avoided:** I was about to add our own proration charge to Razorpay upgrades. I checked the docs first, and Razorpay already prorates immediate updates.
2. The "membership ended" email was skipped when the grace period expired: "was it live?" was judged by time instead of status.
3. The coupon validation response returned the full price as the "discount" (PHP's `+` on arrays keeps the left-hand keys).
4. The admin user list still used the removed `currentSubscriptions` relation (found by PHPStan).
5. Billing actions could act on a subscription relation cached earlier in the same request.
6. Checkout errors appeared twice (toast and inline); the invoice-details form didn't prefill.

### Deviations and decisions (please confirm)
1. **Cashier is used for customers, Checkout and the Portal, not for its own subscription tables.** The spec's `subscriptions` table is the single source of truth for both gateways, so Cashier's subscription tables aren't installed and `User::subscriptions()` intentionally overrides Cashier's (documented in the code).
2. **Downgrades wait until period end** on both gateways, so nobody loses paid-for access or needs a refund. Upgrades are immediate and prorated.
3. **Razorpay limits:** coupons on Razorpay *subscriptions* need an Offer created in the Razorpay dashboard (store its `offer_…` id on the coupon); lifetime orders apply the discount directly. Plan changes only work for card-authorised subscriptions (UPI/eMandate return a clear error). Razorpay has no hosted card-update page.
4. **Tax-inclusive pricing only** (typical for GST). It's not a full GST engine: there's no CGST+SGST vs IGST split by state, and no reverse charge. Stripe Tax (paid) isn't enabled.
5. **Coupons apply to the first payment only** (Stripe `duration: once`).
6. Billing is a **separate page** (`/app/billing`); `/app/membership` redirects to it.
7. Not built in the UI (API exists): admin coupon management screen and admin manual-grant screen.

### Running in test mode (to finish acceptance against real gateways)
1. Put test keys in `backend/.env`: `STRIPE_KEY`, `STRIPE_SECRET`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`.
2. `php artisan billing:sync-gateway-plans` creates Stripe prices (USD) and Razorpay plans (INR) and stores their ids.
3. Webhooks: `stripe listen --forward-to localhost:8010/api/v1/webhooks/stripe` (copy the `whsec_…` into `STRIPE_WEBHOOK_SECRET`). For Razorpay, point a dashboard test webhook at a public tunnel to `/api/v1/webhooks/razorpay` and set `RAZORPAY_WEBHOOK_SECRET`.
4. Run `php artisan queue:work --queue=webhooks,default,mail,notifications` and `php artisan schedule:work`.
5. Subscribe with the test card `4242 4242 4242 4242` or Razorpay test card/UPI, renew with Stripe test clocks, fail with `4000 0000 0000 0341`, then advance past grace.

### Env vars added in Phase 2
| Var | Default | Purpose |
|---|---|---|
| `STRIPE_KEY` / `STRIPE_SECRET` | — | Stripe API (Cashier) |
| `STRIPE_WEBHOOK_SECRET` | — | Verifies Stripe webhooks |
| `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` | — | Razorpay API + checkout signature |
| `RAZORPAY_WEBHOOK_SECRET` | — | Verifies Razorpay webhooks |
| `BILLING_DEFAULT_CURRENCY` | `INR` | Currency for guests without a country |
| `BILLING_GRACE_DAYS` | `3` | Days of access after a failed renewal |
| `BILLING_SELLER_NAME` / `_ADDRESS` / `_TAX_ID` / `_EMAIL` | app name / — | Seller block on invoices (GSTIN) |
| `INVOICE_DISK` / `INVOICE_PREFIX` | `local` / `INV` | Private storage + numbering |

## Phase 1 — Foundation, auth, roles, public site ✅ (2026-11-12)

### Acceptance criteria
| Criterion | Status | Evidence |
|---|---|---|
| User can register | ✅ | `RegisterTest`; registered in a real browser |
| Verify email | ✅ | `EmailVerificationTest`; clicked the real emailed link in the browser → "Email verified" |
| Log in with email | ✅ | `LoginLogoutTest`; browser |
| Log in with Google | ✅ (needs credentials) | `GoogleLoginTest` (Socialite mocked). Needs `GOOGLE_CLIENT_ID/SECRET` to run against Google for real |
| Land on the correct dashboard by role | ✅ | `LoginPage.test.tsx` (admin → `/admin`, instructor → `/instructor`, student → `/app`); browser |
| All auth endpoints covered by Pest tests | ✅ | **114 Pest tests / 398 assertions** |

### Done — backend
- **Stack:** Laravel 13 (see deviations), Sanctum, spatie/permission, Horizon, Telescope (registered only when `APP_ENV=local`, gated to super_admin), Socialite, Pest, Pint, Larastan level 6.
- **Docker:** `backend/compose.yaml` (Sail, PHP 8.3) with MySQL 8.4, Redis, Mailpit and Meilisearch. `.env.example` targets these Sail services.
- **Tables:**
  - `users`: uuid, name, email, phone, country, timezone, avatar_path, bio, status, email/phone_verified_at, last_login_at, lockout and 2FA columns, google_id, soft deletes
  - `user_profiles`, `login_activities`, `phone_otps`, `contact_messages`
  - spatie roles/permissions, Sanctum tokens, sessions
- **Auth endpoints:**
  - register, login, logout, me, forgot/reset password, email verification
  - **phone OTP** (6 digits, bcrypt-hashed, 5-minute expiry, 5 guesses, single use, 2/min and 5/hour throttle, encrypted on the queue)
  - **Google login**: a web redirect/callback for the SPA, plus a token exchange for mobile
  - change password, profile update, **avatar upload** (`AVATAR_DISK`: `public` locally, `s3` in production)
- **Security:**
  - rate limiting on all auth routes
  - **account lockout** after 5 consecutive failures (15 minutes, HTTP 423 `account_locked`; a password reset clears it)
  - suspended/banned accounts are refused at login and kicked from existing sessions and tokens
  - **device/session list** (browser sessions + app tokens; session ids are HMAC-masked) with revoke-one and **log out other devices** (password-confirmed)
  - **TOTP 2FA for admins** with QR code, confirmation step, 8 recovery codes, and a login challenge (5-minute single-use token, 5 attempts)
  - login activity log with `GET /me/login-activity`
- **Public:** `GET /instructors` (featured filter, no contact details), `POST /contact` (honeypot, throttled, emails the team), `GET /sitemap.xml`.
- **Seeds:**
  - super admin (credentials from env, password required in production)
  - 1 admin
  - **3 instructors** with featured profiles
  - **20 students** (4 named demo accounts, one per tier plus free, and 16 random)
  - 6 plans

### Done — frontend
- **Public pages:**
  - Home: hero, value proposition, featured courses, live pricing teaser, instructor highlights from the API, testimonials placeholder, FAQ, CTA
  - About, Courses catalogue (placeholder), Pricing, Contact (live form), Terms, Privacy
- **Auth pages:**
  - Register, Login (with Google button and OAuth error messages), Forgot/Reset password
  - Verify Email (+ "check your inbox" page), Phone OTP, 2FA challenge (code or recovery code), OAuth callback
  - Role-based redirect after login
- **Guards:** `ProtectedRoute`, `RoleRoute`, `RequireTier`, `GuestOnly`.
- **Layouts:** Public (with mobile menu), Student, Instructor and Admin, all with a responsive sidebar or drawer, top bar and dark-mode toggle. Profile and Security pages live inside each area (`/app|/instructor|/admin/{profile,security}`).
- **Security page:** phone verification status, 2FA setup (QR code and recovery codes), session list with revoke and log-out-others, sign-in history.
- **Profile:** avatar upload/remove, country, bio, headline.
- **SEO:**
  - `react-helmet-async` `<Seo>` on every page: title, description, canonical, Open Graph, Twitter
  - default tags per layout, `noindex` on private areas
  - `robots.txt` and an OG image
  - backend `sitemap.xml`, proxied in dev
- **Quality:** ESLint (incl. jsx-a11y), strict TypeScript, **18 Vitest tests**, production build OK.

### Bugs found by testing in a real browser (all fixed and covered by tests)
1. **Registration failed for every Chrome user in India.** Chrome reports the legacy timezone `Asia/Calcutta`, which Laravel's `timezone:all` rule rejects, and the error was invisible because `timezone` isn't a form field.
   - Fix: accept legacy timezone names, fall back to UTC instead of failing, and show server errors for non-form fields.
2. Guest API calls returned 500: the redirect to a missing `login` route. They now return a JSON 401.
3. Pages without their own `<Seo>` kept the previous page's title. Layouts now set default titles.
4. The contact form didn't prefill for signed-in users after a full reload.

### Deviations from the spec (please confirm)
1. **Laravel 13, not 11.** Composer blocks every Laravel 11 release because of security advisories (11 is end-of-life). Version 13 has the same structure.
2. **Docker isn't installed on this machine**, so `compose.yaml` was written but never started. Local dev runs natively on SQLite, with the `database` queue, cache and sessions. Native MySQL 9.4 needs a root password I don't have.
3. **Sessions use the `database` driver**, not Redis. The "your devices" list needs to query sessions by user, which Redis sessions can't do. Cache and queues still use Redis.
4. **No SMS provider yet.** OTPs go through an `OtpSender` interface; the `log` driver writes codes to `storage/logs/laravel.log`. Pick a provider (MSG91, Twilio, Gupshup WhatsApp…) and I'll add its driver; it's a paid service, so it needs your approval.
5. **2FA is offered to admins and super admins only**, as the spec says. It's one line to widen (`User::canUseTwoFactor`).
6. **Placeholders:** featured-course cards and testimonials use labelled placeholder content until the course catalogue and real stories exist. Terms and Privacy are templates marked for legal review.
7. Dev ports: **8010** (API) and **5180** (web), because another local project uses 8000/5173.

### Env vars added in Phase 1
Backend:
| Var | Default | Purpose |
|---|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | — | Google OAuth credentials |
| `GOOGLE_REDIRECT_URI` | `${APP_URL}/auth/google/callback` | Must be registered in the Google console |
| `AVATAR_DISK` | `public` | `s3` in production (uses `AWS_*`) |
| `OTP_DRIVER` | `log` | OTP delivery driver |
| `OTP_TTL_MINUTES` | `5` | OTP lifetime |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | `5` / `15` | Account lockout |
| `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` | `superadmin@example.com` / — | Super admin seed (password required in production, min 12 chars) |
| `CONTACT_EMAIL` | `MAIL_FROM_ADDRESS` | Receives contact-form messages |
| `TELESCOPE_ENABLED` | `true` | Local only |
| `SCOUT_DRIVER` / `MEILISEARCH_HOST` | `meilisearch` / `http://meilisearch:7700` | Search (Sail) |
| `APP_PORT` | `8010` | Sail HTTP port |

Also from the first pass: `APP_TAGLINE`, `APP_CURRENCY`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `VERIFICATION_EXPIRE_MINUTES`, `REDIS_CLIENT=predis`.

Frontend: `VITE_APP_NAME`, `VITE_APP_TAGLINE`, `VITE_API_URL`, `VITE_DEV_BACKEND_URL`.

### Demo accounts (password `password`)
`superadmin@example.com`, `admin@example.com`, instructors `instructor@`, `meera@`, `arjun@example.com`, students `starter@`, `pro@`, `elite@`, `student@example.com`.

### Pending / needs your input
- Google OAuth client id/secret (free, from Google Cloud Console).
- SMS/WhatsApp OTP provider choice (paid).
- Payment gateway choice (paid) for the payments phase.
- Next phase spec.
