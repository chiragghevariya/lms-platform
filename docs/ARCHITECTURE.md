# Architecture

```
lms-platform/
├── backend/    Laravel 13 API (PHP 8.3) — /api/v1, Sanctum, spatie/permission, Horizon
├── frontend/   React 18 + Vite + TS SPA — Tailwind v4, shadcn-style UI, TanStack Query
└── docs/       PROGRESS.md · API.md · ARCHITECTURE.md
```

## Backend

**Request flow:** route → middleware (`auth:sanctum`, `role:`, `tier:`, throttle) → thin controller → `FormRequest` (validation + `authorize()` via Policy) → Action/Service (business logic) → Eloquent model → API Resource.

| Layer | Location | Examples |
|---|---|---|
| Controllers | `app/Http/Controllers/Api/V1/**` | `Auth\LoginController`, `Admin\UserController` |
| Validation | `app/Http/Requests/**` | `RegisterRequest`, `StorePlanRequest` |
| Business logic | `app/Actions/**`, `app/Services/**` | `RegisterUser`, `ActivateAffiliate`, `MembershipService`, `AuthSessionService` |
| Authorization | `app/Policies/**` + `Gate::before` | `UserPolicy::updateRoles` |
| Output | `app/Http/Resources/**` | `UserResource` (public `id` = uuid) |
| Domain enums | `app/Enums/**` | `Role`, `MembershipTier`, `BillingInterval`, `SubscriptionStatus`, `Permission` |
| Side effects | `app/Events`, `app/Listeners`, `app/Notifications` | `SubscriptionActivated` → queued mail |

**Conventions**
- Internal `id` (bigint PK) plus a public `uuid` (`HasPublicUuid` trait: ordered UUID, route key). Never expose `id`.
- Soft deletes on core models. `Model::shouldBeStrict()` outside production (lazy loading and missing attributes throw).
- Money: `unsignedBigInteger *_amount` + `char(3) currency`, wrapped by `App\Support\Money`.
- Timestamps stored in UTC. The user's `timezone` is used for display only.
- Slow work goes on queues: `mail`, `notifications`, `media`, `webhooks`, `default` (Horizon config). Tests use `sync`.

**Sign-in pipeline:** `AuthenticateUser` (lockout, status, password) → `BeginSession` (2FA challenge, or `AuthSessionService::start`). Google (`ResolveGoogleUser`) and `TwoFactorChallengeController` feed into the same `BeginSession`, so every path gets the same 2FA, activity logging and token/session behaviour. `EnsureAccountIsActive` (`active` middleware) re-checks status on every authenticated request.

**Security services:** `TwoFactorService` (google2fa TOTP, encrypted secret and recovery codes, cache-backed login challenge), `PhoneOtpService` + `OtpSender` driver (log now; SMS/WhatsApp later), `DeviceSessionService` (database sessions + Sanctum tokens), `LoginActivityRecorder`, `AvatarService` (configurable disk).

**Auth model:** one set of endpoints serves both clients. `AuthSessionService::start()` issues a personal access token when `device_name` is present (mobile), and otherwise logs into the `web` guard (Sanctum stateful SPA). Logout deletes the bearer token if there is one, and otherwise invalidates the session.

**Roles & permissions:** roles come from `Role` enum values. The permission matrix lives in `Permission::matrix()` and is synced by `RolesAndPermissionsSeeder` (idempotent; run it after changing the matrix). `super_admin` bypasses every check through `Gate::before`. Affiliate isn't a role: it's the `users.is_affiliate` flag plus `affiliate_code`.

**Membership:** `MembershipTier` is an int-backed enum (1 Starter, 2 Pro, 3 Elite) and `includes()` gives inheritance. See *Billing* below for how access is decided.

### Data model
```
plans (uuid, name, slug, tier_level 1-3, description, features json, is_active, sort)
plan_prices (uuid, plan_id, interval month|year|lifetime, amount, currency, stripe_price_id, razorpay_plan_id, trial_days, is_active)
subscriptions (uuid, user_id, plan_id, plan_price_id, gateway, gateway_subscription_id, gateway_customer_id, status,
               is_lifetime, current_period_start/end, trial_ends_at, cancel_at_period_end, cancelled_at, ended_at,
               past_due_since, scheduled_plan_price_id, scheduled_change_at, gateway_cancel_requested_at, coupon_id, granted_by, notes)
payments (uuid, user_id, subscription_id, plan_price_id, coupon_id, gateway, gateway_payment_id, gateway_invoice_id, status,
          currency, subtotal, discount_amount, tax_amount, tax_rate, tax_name, amount, invoice_number, billing_* snapshot,
          period_start/end, paid_at, failure_reason, pdf_path)
coupons (uuid, code, type, value, currency, max_uses, times_used, expires_at, is_active, stripe_coupon_id, razorpay_offer_id)
categories · tags · course_tag · course_instructors (co-instructors)
videos (uuid, provider, provider_video_id, title, status uploading|processing|ready|failed, duration_seconds, thumbnail_url, error)
courses (uuid, instructor_id, category_id, title, slug, subtitle, description*, level, language, thumbnail_path, promo_video_id,
         required_tier 1-3|null, status draft|review|published, is_featured, duration_minutes, lessons_count, price_amount, currency,
         rating_avg, rating_count, enrollments_count, review_notes, submitted_at, published_at, soft deletes)   * sanitized HTML
course_sections (uuid, course_id, title, sort) · lessons (uuid, course_id, section_id, title, type, content*, video_id,
         attachment_path, duration_seconds, is_preview, sort, drip_days) · lesson_resources (uuid, lesson_id, title, disk, path, mime, size)
course_reviews (uuid, course_id+user_id unique, rating, comment) · course_purchases (user_id+course_id unique, payment_id)
enrollments (user_id+course_id unique, source, started_at, last_lesson_id, last_accessed_at, completed_at)
lesson_progress (user_id+lesson_id unique, course_id, position_seconds, completed_at) · lesson_notes (uuid, user_id, lesson_id, position_seconds, body)
coupon_plan · coupon_redemptions (coupon_id+user_id unique) · webhook_events (gateway+event_id unique) · invoice_counters
users (id, uuid, name, email, phone, country, timezone, avatar_path, bio, status,
       email_verified_at, phone_verified_at, password, google_id, failed_login_attempts,
       locked_until, two_factor_secret*, two_factor_recovery_codes*, two_factor_confirmed_at,
       is_affiliate, affiliate_code, affiliate_activated_at, last_login_at, soft deletes)   * encrypted
user_profiles (user_id unique, headline, website, city, state, occupation, date_of_birth,
               social_links json, interests json, expertise json, is_featured)
login_activities (user_id?, email, event, channel, ip_address, user_agent, created_at)
phone_otps (user_id, phone, purpose, code_hash, attempts, expires_at, consumed_at)
contact_messages (uuid, user_id?, name, email, phone, subject, message, ip_address, status, soft deletes)
roles / permissions / model_has_* / role_has_permissions  (spatie)
personal_access_tokens (sanctum) · sessions · jobs · cache · password_reset_tokens
```

## Billing (Phase 2)

```
            ┌──────────── BillingService (user actions) ────────────┐
 Controller ┤ checkout · change · cancel · resume · portal          ├─► GatewayResolver ─► PaymentGateway
            └────────────────────────────────────────────────────────┘        INR → RazorpayGateway (Subscriptions + Orders API, Http client)
                                                                              else → StripeGateway  (Cashier Checkout/Portal + StripeClient)
 Gateway ──webhook──► WebhookController (verify signature, insertOrIgnore webhook_events) ──queue:webhooks──► ProcessWebhookEvent
                                                          ├─ StripeEventHandler ─┐
                                                          └─ RazorpayEventHandler┴─► SubscriptionSync (idempotent upsert of
 Success page ──"sync now"──► CheckoutSyncController ─────────────────────────────►   subscriptions + payments, events)
 Scheduler (hourly) ──► billing:maintain: expire after grace · end lapsed · apply due downgrades · forward Razorpay cancels
 Events: SubscriptionActivated / PaymentFailed / SubscriptionEnded → emails;  PaymentSucceeded → IssueInvoice (PDF + receipt)
```

- **Source of truth:** our `subscriptions` table, which is gateway-agnostic. Gateways only *perform* remote actions; local state changes come from webhooks (or the success-page sync, which reuses the same handlers). Every sync is an idempotent "set state", so retries, duplicates and out-of-order events are safe.
- **Access:** `Subscription::grantsAccess()` = active/trialing within the period, or past_due within `grace_days`. Lifetime and open-ended complimentary have no end. `User::currentTier()` takes the highest tier among those. `MembershipService::canAccess()` is the single decision point (middleware, gate, policies).
- **Downgrades** are stored as `scheduled_plan_price_id/scheduled_change_at`. Sync ignores the gateway's new (lower) price until that moment.
- **Razorpay cancellations** are irreversible remotely, so cancel is recorded locally (`cancel_at_period_end`) and forwarded by the scheduler 24h before renewal (`gateway_cancel_requested_at`). Until then, resume is local.
- **Cashier** provides the Stripe customer (`stripe_id`), Checkout Sessions and the Billing Portal. Its subscription tables and webhook routes are intentionally not used (`Cashier::ignoreRoutes()`).
- **Invoices:** a `payments` row becomes the invoice once succeeded. Numbering uses a row-locked `invoice_counters` series per Indian FY. The PDF (dompdf, Blade template — the one non-email Blade view) is stored on a private disk.

## Courses & video (Phase 3)

- **Access decision:** `CourseAccessService` is the one place that decides access. `accessReason()` returns `manage` (staff/instructors), `tier` (`MembershipService::canAccess($user, $course)`; courses implement `GatedContent` via `HasRequiredTier`), or `purchase` (`course_purchases`). `lessonState()` adds previews (open), drip (`enrollment.started_at + drip_days`) and unpublished rules. Playback, lesson data, notes, progress and resources all go through it.
- **Video:** a `VideoProvider` interface (`createUpload`, `fetchDetails`, `playback`, `parseWebhook`, `delete`) with `BunnyStreamProvider` and `DemoVideoProvider`, bound by `VIDEO_DRIVER`. `VideoService` handles uploads, syncing (duration → lessons → course totals) and the TTL policy.
  - **Upload:** the browser asks `POST /instructor/videos`, the server creates the video at Bunny and signs a TUS ticket, the browser uploads straight to Bunny with tus-js-client (resumable), then the signed webhook queues `SyncVideo`.
  - **Playback:** `GET /lessons/{id}/playback` runs the access checks, then `BunnyTokenSigner` (HMAC-SHA256 directory token in the URL path, so HLS segments inherit it) produces an expiring URL; hls.js plays it and re-requests a fresh URL on 403.
- **Workflow:** `CourseWorkflow` (submit with readiness checks → approve/reject/unpublish, notifications). `CoursePolicy` freezes instructor edits while a course is in review.
- **Catalogue:** `CatalogQuery` gets ids from Scout (Meilisearch / collection), then filters, sorts and paginates in SQL (relevance order is kept with `CASE`).
- **Content safety:** `RichText::clean()` (HTMLPurifier `rich_text` profile) on write; DOMPurify on render.
- **Purchases:** `CoursePurchaseService` reuses the Phase 2 gateways (`checkoutCourse`) and `SubscriptionSync::recordPayment` (`payments.course_id`). Webhook handlers route by `metadata/notes.course_uuid`.

## Learning experience (Phase 4)

- **Progress is server-side only**, so it survives refreshes and device switches. `lesson_progress` stores the resume position and `watched_seconds`; `course_progress` stores the per-course percent, counts, last lesson and `completed_at`.
- **Heartbeats** (`ProgressService::heartbeat`): the player counts only continuous forward playback (seeks don't count) and posts every 15 s. The server credits `min(claimed, ceil(elapsed × rate) + 2, 30 × rate)` under a row lock, so a client can't fake watch time faster than real time. At 90 % of the duration the lesson completes.
- **Completion pipeline:** `ProgressService::complete` (idempotent) → points + first-lesson badge + daily activity → queued `RecalculateCourseProgress` (`ShouldBeUnique` per user+course). The first time a course reaches 100 % it awards course points and the badge, then queues `IssueCertificate`.
- **Certificates:** `CertificateService::issue` is idempotent (unique user+course, row lock). It snapshots the holder/course/instructor names, creates a 10-character code from an unambiguous alphabet, renders an A4-landscape dompdf PDF to the private disk and notifies the learner. Public verification is by code; revoked certificates verify as invalid. The LinkedIn link uses the official `profile/add?startTask=CERTIFICATION_NAME` prefill.
- **Quizzes:** `QuizService` keeps the timer on the server (`expires_at`, 10 s grace) and saves answers per question (`quiz_answers`). `present()` is the only serialiser and hides correctness until the attempt is closed and `show_answers` is on. Instructors edit questions in place, so graded attempts are never orphaned.
- **Assignments:** `AssignmentService` stores files on the private disk under `submissions/{assignment}/{user}`. Resubmission is allowed until graded; `returned` reopens it. Grading at or above the pass score completes the lesson.
- **Gamification:** `GamificationService`. `points_ledger` is unique per `(user, source_key)`, so retries and toggles can't award points twice. `learning_days` holds one row per user per local day (the user's timezone) and drives streaks. Badges are data (`badges`, seeded) granted by key.

## Live classes (Phase 5)

- **Provider adapter:** `LiveProvider` (`create`, `update`, `delete`, `hostUrl`, `joinPayload`, `participants`, `parseWebhook`), resolved per session by `LiveProviders`. `ZoomProvider` uses `ZoomClient` (S2S OAuth `account_credentials`, token cached 50 min) and `ZoomSdkSignature` (HS256 JWT: `appKey/sdkKey, mn, role, iat-30s, exp = iat + 2 h, tokenExp`).
- **Secrets stay server-side:** `join_url`, `host_url` and the passcode are encrypted at rest and hidden from serialisation. They leave only through `/join` (registrant, tier, time window) and `/start` (host). `LivePresenter` is the single serialiser.
- **Time:** instructors enter wall-clock time plus an IANA timezone; we store UTC. Series occurrences are rebuilt from date + local time, so DST keeps the same local hour. Clients render in the viewer's timezone; `.ics` uses UTC.
- **Attendance:** the join payload carries `customerKey` = the registration uuid without dashes (Zoom's limit is 35 characters). Zoom echoes it as `customer_key` in reports and webhooks, so attendance doesn't depend on learners' Zoom accounts or emails. `SyncLiveAttendance` retries for about 2 hours while the report isn't ready.
- **Recordings:** `recording.completed` → `ImportLiveRecording` (encrypted job) → `VideoProvider::importFromUrl` (Bunny fetch with Zoom's download token in headers) → `findImported` (title marker) → `VideoService::sync` → `LiveRecordingService::attachReplay` (course "Live replays" section, `is_optional` lesson). Standalone sessions are served by the Replays library through the same signed-HLS playback.
- **Scheduling loop:** `live:maintain` (every minute) handles status by time, the reminder event (`LiveSessionReminderDue`, Phase 8 adds listeners) and the attendance-sync fallback.
- **Frontend:** the Zoom Meeting SDK component view (`@zoom/meetingsdk/embedded`, pinned 6.10.0) is lazy-loaded only in the live room and pre-bundled for the dev server.

## Instructors & earnings (Phase 6)

- **Watch-time ledger:** `ProgressService::heartbeat` → `WatchTimeRecorder` adds the server-capped credit to `course_watch_daily` (UTC day). `seconds` feeds analytics; `pool_seconds` only counts paid members using membership access, capped per member per day, never staff or the course's instructors.
- **`EarningsService`** is a pure function of the month's succeeded payments + pool seconds (`compute()`), with `generate()` persisting drafts (finalized/paid statements are skipped), `finalize()`, `markPaid()`. Statements snapshot the pool % and share % so later config changes don't rewrite history. The current month uses `compute()` live.
- **`InstructorAnalytics`** aggregates with SQL over the instructor's courses (owner or co-instructor).
- **Q&A / announcements** reuse `CourseAccessService` (only people with access participate) and `RichText` sanitising; notifications are queued on `mail`.
- **Applications** grant roles through spatie (`assignRole`); payout details use Laravel's `encrypted:array` cast and are only decrypted in the admin pay view.
- **Charts:** `components/charts/TrendChart` (Recharts 3.10.1, pinned): one series, table view, theme tokens `--chart-1` / `--chart-grid` validated for light and dark.

## Affiliates & referrals (Phase 7)

- **Events in, side effects out:** `AffiliateEvents` subscribes to `Registered` (attribution), `PaymentSucceeded` (commissions + invite rewards), `PaymentRefunded` / `PaymentDisputed` (reversal / clawback) and `SubscriptionEnded` (banked free days). Billing doesn't know affiliates exist.
- **Attribution cookie:** `ReferralCookie` — base64 JSON + truncated HMAC with the app key; excluded from Laravel cookie encryption so SPA (stateful) and API requests read it the same way. The SPA posts every `?ref` / `?invite` landing to `/referrals/track`.
- **Identity hashing:** `Identity::hash()` = HMAC-SHA256 with the app key for IPs and device ids (`X-Device-Id`); raw IPs stay only in `login_activities` as before. Canonical email (Gmail dots/+tags) catches self-referrals.
- **Money states:** commission `pending` (hold) → `approved` (payable balance) → in a payout (`payout_id`) → `paid`; `reversed` for unpaid refunds; negative `clawback` rows (kind) for refunds after payment, netted in the next payout. Balances = Σ(amount − reversed_amount) of approved, unbatched rows.
- **`RefundRecorder`** owns refund/dispute bookkeeping for both gateways (cumulative totals → idempotent).
- **Payout gateways:** `PayoutGateway` interface bound to `ManualPayoutGateway`; a Stripe Connect / RazorpayX implementation would `send()` and return the transfer reference.

## Notifications (Phase 8)

- **Flow:** `$user->notify(new XNotification)` → `PlatformNotification::via()` → `NotificationRouter` (event config in `config/notify.php` → user overrides in `notification_preferences` → mandatory channels → reachability: email, verified phone + WhatsApp consent, push tokens, configured providers) → one queued job per channel (`viaQueues`, `RateLimited` middleware per channel) → channel class → `NotificationLogger` (listens to `NotificationSending` / `NotificationSent`; `failed()` after retries; `skipped` rows from the router).
- **Copy:** `Templates` = admin rows (`notification_templates`) over `DefaultTemplates`; `{{var}}` rendering escapes Markdown/HTML in values. Classes with rich mail (attachments, tables) keep it via `defaultMail()` until an admin customises the mail template; campaigns always use their own content.
- **Channels:** `mail` / `database` (Laravel), `WhatsAppChannel` (`WhatsAppClient`, template messages; permanent failure → SMS fallback where the event allows), `FcmChannel` (`FcmClient`, HTTP v1 with a self-signed service-account JWT), `SmsChannel` (`SmsSender`: `Msg91Sender` / `TwilioSender` / `LogSmsSender`). Transient provider errors throw → queue retries; permanent ones return `failed` and are logged.
- **Tracking:** provider ids (WhatsApp `wamid`, FCM message name, Twilio SID, mail Message-ID + `notification_id` in `X-Mailgun-Variables`) let webhooks update the same log row; statuses only move forward.
- **Campaigns:** `Segment::query()` builds the audience; `SendCampaign` chunks it into `CampaignNotification`s (event `admin_announcement`, channels limited to the campaign's); `notify:scheduled` (every 5 min) also sends expiring-membership and inactivity notifications once each (`notification_markers`).

## Admin panel, CMS & analytics (Phase 9)

- **Permissions:** `App\Enums\Permission` holds the granular permissions (users.view/manage/impersonate, roles.assign, plans.manage, subscriptions.manage, payments.view/refund, courses.manage-any, reviews.moderate, live.manage-any, instructors.manage, affiliates.manage, notifications.manage, cms.manage, settings.manage, settings.secrets, analytics.view, audit.view). Each admin route group has a `can:` middleware. `admin` has everything except `roles.assign` and `settings.secrets`; `super_admin` passes all gates. After deploying, re-run `php artisan db:seed --class=RolesAndPermissionsSeeder` (idempotent) to create the new permissions. The SPA hides menu items and pages with `can()` (`src/lib/permissions.ts`); the API is the authority.
- **Audit log:** spatie/laravel-activitylog. Admin-managed models (`Plan`, `PlanPrice`, `Coupon`, `Category`, CMS models) use the `Audited` trait, which logs dirty fillable fields before and after. Admin actions log explicit events (`user.status`, `user.roles`, `impersonation.*`, `payment.refunded`, `settings.updated` (keys only, never values), `course.*`, `review.*`, `export.*`). Records older than 365 days are pruned by `activitylog:clean` if scheduled.
- **Impersonation:** session-based (`Admin\Impersonation`): the web guard logs in as the user and the admin id is kept in the session. `GuardImpersonation` (api group) blocks writes to security, billing and admin routes while it is active. It works only from the browser panel, never on staff accounts.
- **Settings:** `App\Settings\SiteSettings` stores admin settings in the `settings` table (secrets encrypted with APP_KEY). They are applied over `config()` on every boot and before each queued job when the version stamp changes. Clearing a value falls back to `.env`. Secrets are never returned, only `set` and `last4`.
- **Refunds:** `Billing\AdminRefunds` calls `PaymentGateway::refund()` (Stripe/Razorpay) under a lock, then records the result through the webhook `RefundRecorder` with the cumulative total, so the later webhook doesn't double count.
- **Analytics:** `Analytics\AnalyticsService` computes every money figure from `payments` (minor units, per currency, no FX conversion; definitions are in the class docblock). Results are cached in `ANALYTICS_CACHE_STORE` (use Redis in production) with a generation counter that payment, refund and subscription events bump. `analytics:snapshot` (nightly at 01:30 in the reporting timezone) writes `metric_snapshots` (MRR and paying members per currency, members per tier) for the MRR trend and pre-warms common ranges.
- **Exports:** maatwebsite/excel `FromQuery` exports (`app/Exports`) share their filters with the tables (`Admin\UserQuery`, `Admin\PaymentQuery`).
- **CMS:** homepage sections are fixed keys with built-in defaults (`Cms\CmsDefaults`). Admin edits override them field by field. FAQs, testimonials, banners (audience + schedule), legal pages and blog posts are stored as rows, with HTML sanitised by the `cms` purifier profile. Public reads go through `SiteController`. The SPA keeps its built-in copy as a fallback.
- **Tracking (optional):** the SPA loads GA4 and the Meta Pixel only when IDs are configured, and sends page views, sign_up and begin_checkout. `SendServerConversion` sends purchases server-side (GA4 Measurement Protocol plus Meta Conversions API with SHA-256 hashed user data) using event_id = payment uuid. The browser never sends Purchase.

## Security & scale (Phase 10)

See `docs/SECURITY.md` (controls, review findings, ZAP results) and `docs/PERFORMANCE.md` (caching layers, scaling diagram, load-test results and sizing). In short: stateless web servers (Redis sessions/cache/queue, S3 files) behind a load balancer and CDN; read replicas via `DB_READ_HOST`; Horizon on separate workers; guest catalogue JSON cached with version-bump invalidation; `SecurityHeaders` + nginx CSP; Turnstile; a 2-device limit (`user_devices`); signed HLS + watermark; GDPR export and deletion.

## Frontend

```
src/
├── app/            providers (Query, theme, session bootstrap, toaster), router, query client
├── components/     ui/ (shadcn-style primitives) + shared (Logo, ThemeToggle, UserMenu, ...)
├── features/<f>/   api.ts · hooks.ts · schemas.ts · components/ · pages/
├── layouts/        PublicLayout, StudentLayout, InstructorLayout, AdminLayout (DashboardShell)
├── lib/            api-client (axios + ApiError), membership helpers, form-errors, env, utils
├── routes/         guards (RequireAuth, RequireRole, RequireTier, GuestOnly)
├── stores/         zustand: auth (hydrated user), theme (persisted)
└── types/api.ts    mirrors API Resources
```

- **Server state** lives in TanStack Query (`['me']` is the session). Zustand keeps only the hydrated user for synchronous guard checks, plus the theme.
- **Sessions:** on boot `GET /me` resolves to a user or `null` (guest). A 401/419 at any later point drops the user to guest and the guards redirect.
- **Forms:** RHF + Zod on the client. `applyServerErrors()` maps Laravel 422 errors onto fields.
- **Theming:** brand colours are CSS variables in `src/index.css` (`--primary`, `--accent`, `--tier-*`, ...) exposed to Tailwind via `@theme inline`. Dark mode is `.dark` on `<html>`, set before first paint.
- **SEO:** `react-helmet-async` with a `<Seo>` component per page. Layouts set default tags, private areas are `noindex`, and the backend serves `sitemap.xml`.
- **Dev:** Vite proxies `/api`, `/sanctum`, `/auth/google` and `/sitemap.xml` to the backend, so cookies are same-origin. For production either serve the SPA on a subdomain of the API's cookie domain (set `VITE_API_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN=.example.com`) or reverse-proxy `/api` under one domain.

## Quality gates
| | Command |
|---|---|
| Backend tests | `cd backend && ./vendor/bin/pest` |
| Static analysis | `./vendor/bin/phpstan analyse` (level 6) |
| Style | `./vendor/bin/pint` |
| Frontend lint / types / tests / build | `npm run lint && npm run typecheck && npm test && npm run build` |
| Format | `npm run format` |
