# API v1

Base URL: `/api/v1`. JSON only, so send `Accept: application/json`. Public ids are UUIDs. Money is `{ amount (minor units), currency, formatted }`. Timestamps are ISO-8601 UTC.

## Authentication
- **Web SPA (cookie):** `GET /sanctum/csrf-cookie`, then call auth endpoints with the `X-XSRF-TOKEN` header from a stateful origin (`SANCTUM_STATEFUL_DOMAINS`). Omit `device_name`; the response `token` is `null`.
- **Mobile (token):** send `device_name` to `register`/`login`, then use `Authorization: Bearer <token>`.

## Errors
| Status | Body |
|---|---|
| 401 | `{ "message": "Unauthenticated." }` (never a redirect) |
| 402 | `{ "message", "code": "membership_required", "required_tier" }` from `tier:` middleware |
| 403 | `{ "message": "This action is unauthorized." }` or `{ "message", "code": "account_suspended" }` |
| 410 | `{ "message", "code": "two_factor_expired" }` — restart login |
| 423 | `{ "message", "code": "account_locked", "retry_after": seconds }` — 5 failed logins → 15 min lock |
| 404 | `{ "message" }` |
| 422 | `{ "message", "errors": { field: [msg] } }` |
| 429 | rate limited (`auth` 10/min/IP + 5/min per email+IP; `otp` 2/min + 5/h per user; `two-factor` 10/min/IP; `contact` 3/min + 20/day per IP; `api` 120/min) |

Other machine-readable `code`s (HTTP 422): `two_factor_invalid`, `otp_invalid`, `otp_expired`, `otp_exhausted`, `phone_missing`, `google_email_unverified`.

## Resources
**User** — `id, name, email, phone, avatar_url, country, timezone, bio, status, email_verified, phone_verified, two_factor_enabled, has_google, profile{headline, website, city, state, occupation, date_of_birth, social_links{}, interests[], expertise[]}, roles[], permissions[] (own record only), is_staff, affiliate{active, code, activated_at}, membership{tier, tier_rank, ends_at}, last_login_at, created_at`

**AuthPayload** (login, register, 2FA challenge, Google token) — `{ two_factor_required, challenge_token, user, token, token_type }`. When `two_factor_required` is `true`, `user`/`token` are null: send the `challenge_token` to `/auth/two-factor-challenge`.

**Plan** — `id, name, slug, tier_level (1-3), tier (starter|pro|elite), description, features[{key,label,value}], is_active, sort, prices[]`

**PlanPrice** — `id, interval (month|year|lifetime), price, currency, trial_days, is_active, gateway (stripe|razorpay)` (+ `stripe_price_id`, `razorpay_plan_id` for staff)

**Subscription** — `id, status (incomplete|trialing|active|past_due|cancelled|expired), gateway (stripe|razorpay|manual), is_active, is_lifetime, is_complimentary, tier_level, tier, plan, price, current_period_start/end, trial_ends_at, cancel_at_period_end, can_resume, cancelled_at, ended_at, past_due, grace_ends_at, scheduled_change{price, plan, effective_at}|null`

**Payment / invoice** — `id, invoice_number, status (pending|succeeded|failed|refunded), gateway, description, amount, subtotal, discount, tax{name, rate, amount}, period_start/end, paid_at, failure_reason, has_pdf`

**CheckoutResult** — `{gateway, action: redirect|razorpay_modal, redirect_url, razorpay{key, name, description, prefill, notes, subscription_id | order_id+amount+currency}}`

**Instructor** (public) — `id, name, avatar_url, headline, bio, expertise[], social_links{}`

**DeviceSession** — `id ("session_<hmac>" | "token_<id>"), type (browser|app), name, ip_address, user_agent, last_active_at, is_current`

**MembershipPlan** — `id, tier, tier_rank, interval, name, slug, description, price, features[], is_active, sort_order`

**Subscription** — `id, tier, status, is_active, price, starts_at, ends_at, canceled_at, plan?`

## Endpoints

### Public
| Method | Path | Notes |
|---|---|---|
| GET | `/ping` | Health: `{data:{status:"ok"}}` |
| GET | `/plans?currency=INR\|USD` | Active plans with active prices in one currency. Default: user country (IN → INR) or `BILLING_DEFAULT_CURRENCY`. `meta.currency` echoes it |
| GET | `/plans/{slug-or-uuid}` | All active prices |
| POST | `/webhooks/stripe` | `Stripe-Signature` verified; handles `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_failed`. Returns `{status: queued\|duplicate}`; 400 on bad signature |
| POST | `/webhooks/razorpay` | `X-Razorpay-Signature` verified, deduped by `X-Razorpay-Event-Id`; handles `subscription.*` (authenticated, activated, charged, pending, halted, updated, cancelled, completed, paused) and `order.paid` |
| GET | `/instructors?featured=1&limit=12` | Active instructors (no email/phone) |
| POST | `/contact` | `name, email, phone?, subject, message` (+ `website` honeypot must be empty) → 201 |
| GET | `/sitemap.xml` *(web route, not under /api)* | XML sitemap of public SPA pages |

### Auth (throttle `auth`)
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/auth/register` | `name, email, password, password_confirmation, phone?, timezone?, device_name?` | 201 `{data:{user, token, token_type}}`; sends verification email; role `student` |
| POST | `/auth/login` | `email, password, remember?, device_name?` | 200 AuthPayload. 423 when locked, 403 when suspended |
| POST | `/auth/two-factor-challenge` | `challenge_token, code` or `recovery_code` | 200 AuthPayload; 422 `two_factor_invalid`; 410 after 5 failures/expiry |
| POST | `/auth/google/token` | `access_token` (from native Google Sign-In), `device_name` | 200 AuthPayload (mobile) |
| POST | `/auth/forgot-password` | `email` | 200 always (doesn't reveal whether the email exists) |
| POST | `/auth/reset-password` | `token, email, password, password_confirmation` | 200; revokes all tokens, clears lockout |

**Google login (web SPA):** full-page navigation to `GET /auth/google/redirect` (web route). The callback `GET /auth/google/callback` starts the session and redirects to `{FRONTEND_URL}/auth/callback`, or `/two-factor?challenge=…` for 2FA users, or `/login?error=<code>`. New Google users become verified students; an existing account with the same (Google-verified) email is linked.

Email links point to the SPA: `{FRONTEND_URL}/reset-password?token=&email=` and `{FRONTEND_URL}/verify-email?url=<signed relative API path>`.

### Authenticated (`auth:sanctum`)
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/logout` | 204. Deletes the bearer token, or ends the session |
| POST | `/auth/email/verification-notification` | 202 sent / 200 already verified (6/min) |
| GET | `/auth/email/verify/{uuid}/{hash}?expires&signature` | Signed (relative). Must be the same user. Returns `{data: User}` |
| GET | `/me` | Current user |
| PATCH | `/me` | `name?, phone?, country? (ISO-2), timezone?, bio?, profile{headline, website, city, state, occupation, date_of_birth, social_links{linkedin,twitter,instagram,youtube,github}, interests[], expertise[]}?`. Changing `phone` resets `phone_verified` |
| POST | `/me/avatar` | multipart `avatar` (jpg/png/webp ≤ 2 MB, 64–4000 px) → User. Replaces and deletes the old file |
| DELETE | `/me/avatar` | → User |
| POST | `/me/phone/otp` | 202 `{data:{expires_at}}` (throttle `otp`) |
| POST | `/me/phone/verify` | `code` (6 digits) → User with `phone_verified: true` |
| GET | `/me/sessions` | `{data: DeviceSession[]}` |
| DELETE | `/me/sessions/{id}` | 204; 404 if not yours |
| POST | `/me/sessions/logout-others` | `password` → `{data:{revoked}}`; keeps the current session/token, rotates remember token |
| GET | `/me/login-activity` | Paginated `{event, channel, ip_address, user_agent, created_at}` |
| GET | `/me/two-factor` | `{available, enabled, pending_confirmation, recovery_codes_left}` |
| POST | `/me/two-factor` | `password` → 201 `{qr_svg, otpauth_url, secret, recovery_codes[]}` (staff only, else 403) |
| POST | `/me/two-factor/confirm` | `code` → enables 2FA |
| POST | `/me/two-factor/recovery-codes` | `password` → new codes |
| DELETE | `/me/two-factor` | `password` → 204 |
| PUT | `/me/password` | `current_password, password, password_confirmation`. Revokes other tokens |
| GET | `/me/subscription` | Alias of `/billing/subscription` |
| GET | `/me/access?tier=pro` | `{data:{required_tier, current_tier, has_access}}` |
| POST | `/me/affiliate/activate` | Idempotent; generates an 8-char `affiliate.code` |

### Courses & catalogue (public; auth optional — adds access flags)
| Method | Path | Notes |
|---|---|---|
| GET | `/categories` | With published `courses_count` |
| GET | `/courses` | `q` (Scout full-text), `category` (slug), `level[]`, `tier[]` (free\|1\|2\|3), `duration[]` (short <1h \| medium 1–5h \| long >5h), `rating` (min), `featured`, `sort` (popular\|newest\|rating\|duration_asc\|duration_desc\|relevance), `page`, `per_page` ≤ 48. Cards include `has_access` when signed in |
| GET | `/courses/{slug}` | Detail + `curriculum[{lessons[{unlocked, lock_reason: login_required\|membership_required\|drip_locked, available_at, completed}]}]`, `instructors[]`, `access{has_access, reason: manage\|tier\|purchase, required_tier, can_purchase, enrolled, progress_percent, next_lesson_id}`, `my_review`. Drafts: 404 except for its instructors/staff |
| GET | `/courses/{slug}/reviews` | Paginated reviews with comments |
| GET | `/courses/{slug}/promo` | Signed promo video URL |
| GET | `/lessons/{uuid}/playback` | `{url, poster, expires_at, ttl_seconds}` (`Cache-Control: no-store`). 401 `login_required`, 402 `membership_required` (+`required_tier`, `required_tier_slug`), 403 `drip_locked` (+`available_at`), 409 `video_not_ready`. Previews: open to guests. Auto-enrols members |

### Learning (authenticated)
| Method | Path | Notes |
|---|---|---|
| POST | `/courses/{slug}/purchase` | One-time purchase → CheckoutResult (INR → Razorpay order, else Stripe Checkout). 409 `already_has_access`; 422 `not_for_sale`. Completed by the payment webhooks / success-page sync |
| POST | `/courses/{slug}/reviews` | `rating 1-5, comment?` — 201 new / 200 updated; 403 unless enrolled with access |
| GET | `/learn/enrollments` | My learning: union of enrolments and course progress → `course, progress_percent, completed_lessons, total_lessons, last_lesson_id, last_accessed_at, completed_at` (most recent first) |
| GET | `/learn/lessons/{uuid}` | Content, `resources[]`, `progress{position_seconds, watched_seconds, completed, heartbeat_seconds (15), can_mark_complete}`, `bookmarked`, `notes[]`, `prev_id`, `next_id`, `course{}`; 402/403 when locked. Records the course's last lesson |
| POST | `/learn/lessons/{uuid}/heartbeat` | `position_seconds, watched_seconds (≤ 120), playback_rate? (0.25–2)` → `{position_seconds, watched_seconds, completed}`. Sent every 15 s of playback. **Credit is capped server-side** at `min(claimed, ceil(elapsed × rate) + 2, 30 × rate)` where `elapsed` = seconds since the last heartbeat. The lesson completes when credited watch time reaches 90 % of its duration |
| PUT | `/learn/lessons/{uuid}/progress` | `position_seconds?` (resume point only, never counts as watching), `completed?` (text/PDF lessons only; 422 for video/quiz/assignment). Completion awards points and queues the course-progress recalculation |
| GET | `/learn/lessons/{uuid}/attachment` | PDF lesson, inline, `no-store` |
| POST | `/learn/lessons/{uuid}/notes` · DELETE `/learn/notes/{uuid}` | Timestamped private notes |
| GET | `/learn/resources/{uuid}` | Resource download (access-checked) |
| GET | `/learn/lessons/{uuid}/quiz` | Summary: `question_count, pass_mark, time_limit_seconds, attempts_allowed, attempts_used, best_percent, passed, open_attempt_id, attempts[]` |
| POST | `/learn/lessons/{uuid}/quiz/attempts` | Start, or resume the open attempt (201). Expired open attempts are auto-submitted first. 422 `quiz` when attempts are used up. Questions/options shuffled per attempt when enabled. **Correct answers, explanations and scores are never sent while in progress** |
| GET | `/learn/quiz-attempts/{uuid}` | Attempt (own only, else 404). `seconds_left` while open; after submit: `score, max_score, percent, passed`, per question `is_correct, points_awarded`, and when the quiz has `show_answers`: `options[].is_correct`, `explanation`, `accepted_answers` |
| PUT | `/learn/quiz-attempts/{uuid}/answers` | Autosave `answers: {questionId: {option_ids?: [], text?: ""}}`. Ignored after the deadline |
| POST | `/learn/quiz-attempts/{uuid}/submit` | Optional final `answers`, then auto-grade. MCQ is all-or-nothing; short text matches accepted answers case/whitespace-insensitively. Past `expires_at` + 10 s grace → status `expired`, late answers discarded. Passing completes the lesson |
| GET | `/learn/lessons/{uuid}/assignment` | `instructions, due_at (enrolment + due_days), max_score, pass_score, file_types, max_file_mb, allow_text, submission` |
| POST | `/learn/lessons/{uuid}/assignment/submission` | multipart `text?`, `files[]?` (≤ 5, type + size checked). 201. Resubmitting replaces text (and files, if new ones are sent) until graded; 422 `submission` when empty or already graded |
| GET | `/learn/submissions/{uuid}/files/{index}` | Download (the learner or the course's instructors/admins; others 404) |

### Learner area (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET | `/me/dashboard` | `continue_learning[] {course, percent, completed_lessons, total_lessons, next_lesson_id}`, `pending_assignments[]` (not started or returned), `upcoming_live_classes[]` (empty until the live-classes phase), `stats {points, streak{current, longest, active_today}, courses_completed, certificates}`, `recent_badges[]`, `recommended[]` (in your tier, not started) |
| GET | `/me/achievements` | `points, streak, badges[] {key, name, description, earned, awarded_at}, recent_points[]` |
| GET/POST/DELETE | `/me/bookmarks` | List / add `{course_id}` or `{lesson_id, position_seconds?, label?}` (idempotent; 201 new, 200 existing; lessons need access) / remove by the same target |
| DELETE | `/me/bookmarks/{uuid}` | Remove one (own only) |
| GET | `/me/notes?q=&course=` | All my notes with lesson + course |
| GET | `/me/certificates` | `id, verification_code, holder_name, course_title, course_slug, instructor_name, issuer, issued_at, verify_url, linkedin_url` |
| GET | `/me/certificates/{uuid}/pdf` | PDF (owner only, else 404; regenerated if missing) |
| GET/PATCH | `/me/learning-profile` | `skills[] (≤ 30), learning_goals, is_public, public_handle ([a-z0-9_-], 3–40, unique)`. Going public requires a handle (422 `public_handle`) |

### Live classes (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET | `/live-sessions?from=&to=&tier=starter\|pro\|elite\|open&instructor=&type=&mine=1` | Without `from`: upcoming (incl. in progress), 60 days. With `from`/`to` (≤ 3 months): calendar range incl. past. Session: `id, title, description, type, type_label, status, starts_at, ends_at, duration, timezone, required_tier, provider, capacity, registered_count, seats_left, is_series, instructor, course, has_replay, replay_lesson_id, calendar{google, ics}, viewer{eligible, registered, can_register, joinable, join_opens_at, attended, can_manage}`. **Never includes join/host links or passcodes.** `meta.instructors`, `meta.join_early_minutes` |
| GET | `/live-sessions/{uuid}` | One session |
| POST · DELETE | `/live-sessions/{uuid}/registration` | Register (201; 200 if already; emails an .ics invite) · leave (before start). 402 `membership_required`; 422 `session` when full, ended or cancelled |
| POST | `/live-sessions/{uuid}/join` | Registered + eligible + inside the window (10 min before → end). Zoom: `{mode: "zoom_sdk", sdk_key, signature (Meeting SDK JWT, 2 h), meeting_number, password, user_name, user_email, customer_key, fallback_url}`; others / no SDK keys: `{mode: "external", url}`. 403 `join_not_open` + `opens_at`. `Cache-Control: no-store` |
| GET | `/live-sessions/mine.ics` | Calendar file of my upcoming registrations |
| GET | `/live-replays?q=` | Ended sessions with a ready recording; `locked`, `replay_duration_seconds` |
| GET | `/live-replays/{uuid}/playback` | Signed HLS URL (402 below the tier) |

`GET /live-sessions/{uuid}/calendar.ics` is public (no secrets inside).

### Course Q&A, announcements, becoming an instructor (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET · POST | `/learn/lessons/{uuid}/questions?scope=lesson\|course` | Learners with course access (else 404). POST `title (5-200), body (10-5000)` → 201 |
| GET · DELETE | `/questions/{uuid}` | Thread with `answers[] {is_instructor, is_mine}`; delete: author or course instructors/admins |
| POST | `/questions/{uuid}/answers` | `body`. A course instructor's answer sets `instructor_answered`; the asker is emailed |
| DELETE | `/answers/{uuid}` | Author or course instructors; removing the last instructor answer reopens the question |
| GET | `/courses/{slug}/announcements` | Learners with access |
| GET · POST | `/me/instructor-application` | Status (`is_instructor`, latest `application`) · apply: `headline (10-160), expertise[] (1-10), bio (50-3000), motivation (50-3000), links[]? (https)`; 422 `application` if pending or already an instructor |

### Affiliates & invites
| Method | Path | Notes |
|---|---|---|
| POST | `/referrals/track` *(public)* | `ref?` / `invite?`, `landing_url?`, `referrer?`, `utm{utm_source…}?`, `visitor_id?` → 204 + signed `lms_ref` / `lms_invite` cookie (60 days). Ignored for logged-in users and unknown/inactive codes |
| GET | `/affiliate-banners/{728x90\|300x250\|1080x1080}/{code}.svg` *(public)* | Banner for approved affiliates |
| GET · POST | `/me/affiliate` | Account (`code, status, link, terms, payout{method, summary, minimum}, balances{CUR: {pending, approved, paid, in_payout}}`) or `null` · apply `promotion_plan (30-3000), website? (https), accept_terms` |
| PUT | `/me/affiliate/payout` | `payout_method bank\|upi\|paypal` + `payout_details` (encrypted, returned masked) |
| GET | `/me/affiliate/stats?days=7\|30\|90\|365` | `funnel {clicks, unique_visitors, signups, conversions, signup_rate, conversion_rate}`, `daily[]`, `campaigns[]` |
| GET | `/me/affiliate/commissions` · `/payouts` · `/assets` | Commission history (customer first name only), payouts, banners + swipe copy |
| GET | `/me/invite` | Invite code/link, reward terms, stats, rewards |

Registration accepts `ref` and `invite` in the body for clients without cookies (mobile).

### Privacy & devices (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET | `/auth/session` (public) | Boot probe: `{data: user}` or `{data: null}`, always 200 |
| GET | `/me/export` | All personal data as a JSON download (3 per hour) |
| GET | `/me/deletion-check` | `{blockers[], requires_password}` |
| DELETE | `/me` | `confirm: "DELETE"`, `password` (unless Google-only). Cancels renewals, anonymises the account, keeps invoices, signs out (5 per hour). Blocked while impersonating |
| — | any request | `401 {code: "device_signed_out"}` when this browser was pushed out by a newer sign-in (limit `MAX_DEVICES`) |

Captcha: when Turnstile is enabled (`GET /site` → `captcha.site_key`), `POST /auth/register`, `/auth/login`, `/auth/forgot-password` and `/contact` need `captcha_token` (body) or `X-Captcha-Token` (header). Otherwise they return `422 {code: "captcha_failed"}`.

### Notifications (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET | `/me/notifications?unread=1&page=` | In-app notifications `{id, event, title, body, url, read_at, created_at}`; `meta.unread` |
| GET | `/me/notifications/unread-count` | `{unread}` (the bell polls this) |
| POST | `/me/notifications/{id}/read` · `/me/notifications/read-all` | 204 |
| GET · PUT | `/me/notification-preferences` | Matrix `categories[].events[].channels{mail,database,whatsapp,push,sms: {enabled, locked}}`, `channels` availability, `whatsapp {opted_in, opted_in_at, phone, phone_verified}`, `push_devices` · PUT `changes[] {event, channel, enabled}` (mandatory channels ignored) |
| PUT | `/me/whatsapp` | `opt_in`, `consent: true` when opting in (verified phone required) — timestamps consent / opt-out |
| POST · DELETE | `/me/push-tokens` | `token`, `platform android\|ios\|web` (FCM) |

Unsubscribe: signed web route `GET|POST /unsubscribe/{user}/{event}` (POST = RFC 8058 one-click from mail clients).

### Site content (public)
| Method | Path | Notes |
|---|---|---|
| GET | `/site` | Branding, SEO defaults, public tracking IDs (GA4, Meta Pixel), the live banner for this visitor (guests / free / members / all) |
| GET | `/cms/home` | Visible homepage sections in order, published FAQs and testimonials |
| GET | `/cms/pages/{slug}` | Published CMS page (404 → the SPA shows its built-in page) |
| GET | `/blog` · `/blog/{slug}` | Published posts (paginated, no body) · one post with body |

### Certificates & public profiles (public)
| Method | Path | Notes |
|---|---|---|
| GET | `/certificates/verify/{code}` | Case-insensitive. `{valid, holder_name, course_title, course_slug, instructor_name, issuer, issued_at, verification_code, verify_url}`; `valid: false` when revoked; 404 `{valid:false}` when unknown. No private fields |
| GET | `/learners/{handle}` | Only when the learner opted in: name, avatar, headline, bio, skills, goals, points, badges, certificates. No email |

### Instructor studio (`role:instructor|admin|super_admin`; CoursePolicy: owner, co-instructors, admins)
| Method | Path | Notes |
|---|---|---|
| GET/POST | `/instructor/courses` | List mine (admins `?all=1`) / create draft: `title`, `subtitle?`, `description?` (HTML, sanitised), `category_id?`, `level?`, `language?`, `required_tier? 1-3`, `price_amount?`+`currency?`, `promo_video_id?`, `tags[]?` |
| GET/PATCH/DELETE | `/instructor/courses/{uuid}` | Full editor payload incl. `sections[].lessons[]`, `readiness[]`, `review_notes`. PATCH forbidden while in review. DELETE: owner's drafts without enrolments (admins any) |
| POST | `/instructor/courses/{uuid}/thumbnail` | image ≥ 640×360, ≤ 4 MB |
| POST | `/instructor/courses/{uuid}/submit` | 422 `course: [readiness problems]` |
| PUT | `/instructor/courses/{uuid}/curriculum` | `sections: [{id, lessons: [lessonId…]}]` — full layout; lessons may move between sections |
| POST | `/instructor/courses/{uuid}/sections` · PATCH/DELETE `/instructor/sections/{uuid}` | |
| POST | `/instructor/sections/{uuid}/lessons` · PATCH/DELETE `/instructor/lessons/{uuid}` | `title, type, content? (HTML), video_id?, is_preview?, drip_days?` |
| POST | `/instructor/lessons/{uuid}/attachment` | PDF ≤ 50 MB (pdf lessons) |
| POST | `/instructor/lessons/{uuid}/resources` · DELETE `/instructor/resources/{uuid}` | Downloadable files ≤ 100 MB |
| POST | `/instructor/videos` | `title` → `{video, upload{protocol: tus\|http, endpoint, headers, expires_at}}`. Bunny: TUS headers `AuthorizationSignature` (sha256(library+key+expires+videoId)), `AuthorizationExpire`, `VideoId`, `LibraryId` |
| GET | `/instructor/videos/{uuid}` | Status poll (re-syncs from provider when stuck processing) |
| POST | `/instructor/videos/{uuid}/uploaded` | Browser finished the TUS upload → processing |
| POST | `/instructor/videos/{uuid}/demo-upload` | Demo driver only (multipart `file`) |
| GET | `/instructor/analytics?days=7\|30\|90\|365` | `kpis {students, new_enrollments, completion_rate, avg_quiz_score, watch_minutes, rating_avg, rating_count, live_attendance_rate, open_questions, pending_submissions}`, `series.daily[] {date, enrollments, watch_minutes}`, `series.earnings[] {period, currency, amount, estimate}`, `courses[]` |
| GET | `/instructor/activity` | Latest enrolments, reviews, questions, submissions |
| GET | `/instructor/courses/{uuid}/students?q=&sort=recent\|progress\|name&page=` | Paginated: `name, email, progress_percent, completed_at, avg_quiz_score, enrolled_at, last_activity_at` |
| GET | `/instructor/questions?status=unanswered\|answered\|all&course=` | Q&A inbox across my courses (oldest unanswered first) |
| GET · POST | `/instructor/courses/{uuid}/announcements` | List · send `title, body (HTML, sanitised)` → emailed to enrolled students; `recipients_count` |
| GET · PUT | `/instructor/profile` | `headline, bio, expertise[], website, social_links{}`, `payout_method bank\|upi\|paypal` + `payout_details` (`account_name, account_number, ifsc` \| `upi_id` \| `paypal_email`), `tax_id`. Response masks payout data |
| GET | `/instructor/earnings` | `revenue_share_percent, pool_percent, current {period, estimate[]}, statements[]` |
| GET | `/instructor/earnings/{uuid}` | My statement with `lines[]` (404 for others') |
| GET | `/instructor/live-sessions/options` | `providers` (configured ones), `types`, `courses` (mine), `sdk_embedded` |
| GET | `/instructor/live-sessions?scope=upcoming\|past&all=1` | Mine (admins: `all=1`), with `attended_count`, `recording_status` |
| POST | `/instructor/live-sessions` | `title, description?, type, required_tier? (1-3; course sessions default to the course tier), course_id?, starts_at (local "YYYY-MM-DDTHH:mm"), timezone (IANA, aliases ok), duration (15-480), capacity?, provider zoom\|external_link, join_url (https, external only), repeat?{weekdays [1-7], occurrences 2-52}` → 201 list of created sessions. 422 `provider` when the provider isn't configured or Zoom fails (nothing is left behind) |
| PATCH | `/instructor/live-sessions/{uuid}` | Same fields (no provider/repeat); pushed to Zoom; rescheduling re-arms reminders |
| POST | `/instructor/live-sessions/{uuid}/cancel` | `reason?, following?` → `{cancelled: n}`; deletes Zoom meetings, emails registrants |
| POST | `/instructor/live-sessions/{uuid}/start` | `{url}` fresh host link |
| GET | `/instructor/live-sessions/{uuid}/registrations` | Roster with `attended, joined_at, left_at, attended_minutes`; `meta.attendance_synced_at` |
| POST | `/instructor/live-sessions/{uuid}/attendance-sync` | Re-pull the Zoom report (ended Zoom sessions) → 202 |
| PUT | `/instructor/live-sessions/{uuid}/replay` | `video_id` (a video I uploaded) → attach as the replay |
| GET/PUT | `/instructor/lessons/{uuid}/quiz` | Quiz lessons only. `pass_mark, time_limit_seconds? (30–14400), attempts_allowed?, shuffle, show_answers, questions[] {id?, type mcq_single\|mcq_multi\|true_false\|short_text, prompt, points, explanation?, options[] {text, is_correct}, accepted_answers[]}`. Questions update **in place by `id`** so past attempts stay intact; removing a question that learners answered → 422 `questions`. GET includes `has_answers` per question |
| GET/PUT | `/instructor/lessons/{uuid}/assignment` | Assignment lessons only. `instructions (HTML), due_days?, max_score, pass_score?, file_types[], max_file_mb, allow_text` |
| GET | `/instructor/submissions?status=submitted\|returned\|graded&course=` | Grading queue for courses I teach (admins: all) |
| PATCH | `/instructor/submissions/{uuid}` | `status graded\|returned, score? (0–max), feedback?`. Graded at/above `pass_score` completes the lesson. Emails the learner |

### Refunds & chargebacks (gateway webhooks)
Stripe: `charge.refunded` (cumulative `amount_refunded`), `charge.dispute.created`, `charge.succeeded` (card fingerprint). Razorpay: `refund.processed` / `payment.refunded`, `payment.dispute.created`. Payments are matched by `gateway_payment_id` or `gateway_reference` (Stripe PaymentIntent of an invoice); partial refunds update `refunded_amount`, full refunds set status `refunded`.

### Notification delivery webhooks
| Path | Verification | Effect |
|---|---|---|
| `GET /webhooks/whatsapp` | `hub.verify_token` = `WHATSAPP_VERIFY_TOKEN` | Echoes `hub.challenge` |
| `POST /webhooks/whatsapp` | `X-Hub-Signature-256` = `sha256=` HMAC(app secret, raw body) | `statuses[]` → sent/delivered/read/failed; inbound "STOP" → WhatsApp opt-out |
| `POST /webhooks/mailgun` | HMAC-SHA256(signing key, timestamp+token), ≤ 15 min old | delivered / opened (→ read) / permanent failed / complained; matched by `notification_id` (X-Mailgun-Variables) |
| `POST /webhooks/twilio` | `X-Twilio-Signature` (HMAC-SHA1 of URL + sorted params) | sent / delivered / failed / undelivered |

### Zoom webhook
`POST /webhooks/zoom`: `x-zm-signature` = `v0=` + hex HMAC-SHA256(`ZOOM_WEBHOOK_SECRET`, `v0:{x-zm-request-timestamp}:{raw body}`), timestamp within 5 min, deduped. Answers `endpoint.url_validation`. Handles `meeting.started` (→ live), `meeting.ended` (→ ended, attendance sync in 5 min), `meeting.participant_joined/left` (early attendance by `customer_key`), `recording.completed` (MP4 → video provider import → replay).

### Video webhook
| POST | `/webhooks/video` | Bunny Stream: `X-BunnyStream-Signature-Version: v1`, `X-BunnyStream-Signature-Algorithm: hmac-sha256`, `X-BunnyStream-Signature` = hex HMAC-SHA256(raw body, Read-Only API key); library id must match; deduped by `EventId`. Status 3/4 → ready, 5/8 → failed → queued `SyncVideo` |
|---|---|---|

### Billing (authenticated)
| Method | Path | Notes |
|---|---|---|
| GET | `/billing/subscription` | Current membership (or most recent ended one), `{data: Subscription\|null}` |
| POST | `/billing/coupons/validate` | `plan_price_id, coupon_code` → `{code, discount, final_price, applies_to: one_time\|first_payment}`; 422 `coupon_code` with reason |
| POST | `/billing/checkout` | `plan_price_id, coupon_code?` → 201 CheckoutResult. 409 `already_subscribed` (use change), `already_lifetime`; 422 `price_not_configured` |
| GET | `/billing/checkout/stripe/{cs_…}` | Success-page sync of a Checkout Session (own sessions only) → `{checkout_status, subscription}` |
| POST | `/billing/checkout/razorpay/verify` | `razorpay_payment_id, razorpay_signature, razorpay_subscription_id \| razorpay_order_id` → verifies HMAC with key secret, fetches and syncs → `{subscription}`; 422 `signature_invalid` |
| POST | `/billing/subscription/preview-change` | `plan_price_id` → `{direction, effective: now\|period_end, effective_at, credit, charge, amount_due_now, currency}` |
| POST | `/billing/subscription/change` | `plan_price_id`. Upgrade: immediate (gateway prorates). Downgrade: scheduled for period end. Choosing the current price withdraws a scheduled change. 409 `cancel_pending`, `lifetime`; 422 `currency_mismatch` |
| POST | `/billing/subscription/cancel` | Cancel at period end (Razorpay: forwarded 24h before renewal) |
| POST | `/billing/subscription/resume` | Undo cancel; 409 `resume_unavailable` once a Razorpay cancel was forwarded |
| GET | `/billing/portal` | `{url}`: Stripe Customer Portal (update card); 409 `portal_unavailable` for Razorpay |
| GET | `/billing/invoices` | Paginated Payment list (succeeded/failed/refunded) |
| GET | `/billing/invoices/{uuid}/pdf` | PDF download (owner or admin); generated on demand if missing |
| PUT | `/billing/details` | `billing_name?, tax_id? (GSTIN/VAT), billing_address{line1,line2,city,state,postal_code,country}?` |

All self-service changes return 409 `manual_subscription` for complimentary memberships.

**Access gating:** `->middleware('tier:2')` (or `tier:pro`) returns **402** `{code: membership_required, required_tier: 2, required_tier_slug: "pro"}`. In policies use `MembershipService::canAccess($user, $content)` where `$content implements GatedContent`, or `Gate::allows('access-content', $content)`.

### Admin (`role:super_admin|admin` + a granular permission per area, see ARCHITECTURE.md)
| Method | Path | Notes |
|---|---|---|
| GET | `/admin/notification-templates` | Every event with its channels, current copy, `customised`, available `vars` |
| PUT · DELETE | `/admin/notification-templates/{event}/{channel}` | `subject, body, action_text` or `whatsapp_template, whatsapp_language, whatsapp_params[]` (422 for unknown `{{variables}}`) · reset to default |
| POST | `/admin/notification-templates/{event}/preview` | `channel, subject, body` → rendered with sample data (`html` for mail), `unknown_vars` |
| GET · POST | `/admin/campaigns` | List with per-channel stats · create draft: `name, channels[mail\|database\|whatsapp\|push], subject, body (markdown), action_text, action_url, whatsapp_template, whatsapp_params[], segment {tiers[], status[], course_id, inactive_days, countries[], roles[]}` |
| POST | `/admin/campaigns/audience` | `segment` → `{count, whatsapp_reachable, sample[]}` |
| GET · PATCH | `/admin/campaigns/{uuid}` | Detail with stats · edit (drafts only) |
| POST | `/admin/campaigns/{uuid}/schedule` · `/cancel` · `/test` | `scheduled_at?` (omit = send now) · cancel · send to yourself |
| GET | `/admin/notification-logs?channel=&status=&event=&campaign=&q=&days=` | Paginated log rows + `meta.summary {channel: {status: n}}` |
| POST | `/admin/notification-logs/{uuid}/retry` | Failed rows with stored variables → re-sent on that channel (202) |
| GET | `/admin/affiliates?status=` | With referrals, conversions, balances |
| POST | `/admin/affiliates/{uuid}/approve\|reject\|suspend` | `notes` (required to reject/suspend) |
| PATCH | `/admin/affiliates/{uuid}/terms` | `tier`, `commission_type percent\|fixed`, `commission_rate` (0-100), `commission_fixed {CUR: minor}` |
| GET · POST | `/admin/affiliate-reviews` · `/admin/affiliate-reviews/{referral}` | Flagged referrals with held amounts · `approve: bool` (reject reverses unpaid commissions) |
| GET | `/admin/affiliate-payouts?period=` | Batch for a month |
| POST | `/admin/affiliate-payouts/batch` | `period` → creates payouts above the minimum (idempotent per affiliate/currency/period) |
| GET | `/admin/affiliate-payouts/export?period=` | CSV with decrypted payout details (`no-store`) |
| POST | `/admin/affiliate-payouts/{uuid}/paid` · `/failed` | `reference` · failed returns commissions to the balance |
| GET | `/admin/instructor-applications?status=pending\|approved\|rejected` | With applicant |
| POST | `/admin/instructor-applications/{uuid}/approve` · `/reject` | `notes?` · `notes` (≥ 10) — approve grants the instructor role |
| GET | `/admin/payouts?period=YYYY-MM` | Statements with instructor + masked payout summary; `meta.totals` per currency |
| POST | `/admin/payouts/generate` · `/finalize` | `period`. Generate (re)computes drafts for a closed month (422 for an open month); finalize locks them |
| GET | `/admin/payouts/{uuid}` | Statement + **decrypted payout details** (`no-store`) |
| POST | `/admin/payouts/{uuid}/paid` | `reference` (finalized only, else 409) |
| GET · PATCH | `/admin/instructors` · `/admin/instructors/{uuid}/revenue-share` | Shares list · `revenue_share_percent` 0-100 or null (default) |
| GET | `/admin/users` | `search, role, status, tier (none\|1\|2\|3), country, verified, joined_from, joined_to, sort (±created_at\|name\|last_login_at), per_page ≤100` → compact rows with current membership |
| GET | `/admin/users/export` | Same filters + `ids[]?`, `format=csv\|xlsx` (audited) |
| GET | `/admin/users/{uuid}` | Detail: profile, subscriptions, last 10 payments, stats (enrollments, completions, certificates, watch seconds, lifetime value per currency), last logins, activity |
| POST | `/admin/users/{uuid}/status` | `status active\|suspended\|banned`, `reason` (required unless active). Suspending revokes tokens and DB sessions. Never staff or yourself |
| POST | `/admin/users/bulk` | `action suspend\|activate, ids[] (≤500), reason?` → `{updated, skipped}` (staff/self skipped) |
| POST | `/admin/users/{uuid}/password-reset` | Emails a reset link (429 if one was just sent) |
| POST | `/admin/users/{uuid}/impersonate` | `users.impersonate`; browser session only, non-staff active users. Signs the session in as the user; `/me` then has `impersonator {id,name}`. Security, billing and admin writes are blocked (403 `code: impersonating`) |
| POST | `/impersonation/stop` | Any signed-in session that is impersonating → back to the admin |
| PUT | `/admin/users/{uuid}/roles` | `roles: string[]`. Admins: student/instructor only, not on staff or themselves. Super admin: any |
| GET | `/admin/users/{uuid}/subscriptions` | All subscriptions with plan |
| POST | `/admin/users/{uuid}/subscriptions` | Complimentary grant: `plan_id (plan uuid), ends_at? (null = until revoked), notes?` → 201 |
| POST | `/admin/users/{uuid}/subscriptions/{uuid}/cancel` | Revokes a complimentary membership; 409 for paid ones |
| GET | `/admin/users/{uuid}/payments` | Paginated payments |
| GET | `/admin/plans` | All plans with all prices (incl. gateway ids) |
| POST | `/admin/plans/{uuid}/prices` | `interval, currency, amount (≥100 minor units), trial_days?, stripe_price_id? (price_…), razorpay_plan_id? (plan_…), is_active?`. One active price per interval and currency; lifetime can't have a trial |
| PATCH | `/admin/plans/{uuid}/prices/{uuid}` | `amount, trial_days, is_active, gateway ids` (interval/currency prohibited) |
| GET | `/admin/courses?status=review\|published\|draft&q=` | Review queue (in-review first) |
| POST | `/admin/courses/{uuid}/approve` · `/reject` | Reject requires `notes` (≥ 10 chars); both email the instructor |
| PATCH | `/admin/courses/{uuid}` | `is_featured?`, `unpublish?` |
| GET · POST | `/admin/categories` | With `courses_count` · `name (unique), slug?, description?, sort?` |
| PATCH · DELETE | `/admin/categories/{uuid}` | Update · delete (422 while courses use it) |
| GET | `/admin/reviews` | `search, rating, max_rating, state visible\|hidden, course (uuid)` |
| POST | `/admin/reviews/{uuid}/hide` · `/unhide` | `reason` to hide. Hidden reviews leave the public list and the course rating |
| DELETE | `/admin/reviews/{uuid}` | Permanent; the audit log keeps the text |
| GET | `/admin/subscriptions` | `search, status, gateway, tier, renewing` + `meta.counts` per status |
| GET | `/admin/subscriptions/{uuid}` | With user and last 24 payments |
| GET | `/admin/payments` | `search (invoice, gateway id, email, name), status, gateway, type membership\|course, currency, country, refunded, from, to` + `meta.totals` per currency |
| GET | `/admin/payments/export` | Same filters, `format` |
| GET | `/admin/payments/{uuid}` | With refund history |
| POST | `/admin/payments/{uuid}/refund` | `payments.refund`: `amount?` (minor units; omit = remaining balance), `reason`. Calls Stripe (`refunds.create` on the PaymentIntent) or Razorpay (`/payments/{id}/refund`), then records it like a refund webhook (commissions/earnings react; the later webhook is a no-op). 422 `refund_unsupported` when the payment has no gateway id to refund against |
| GET | `/admin/commissions/export` | `status, currency, from, to, format` |
| GET | `/admin/analytics/overview` | `from, to (Y-m-d, ≤2 years), currency` → `summary` (KPI cards), `revenue`, `members`, `funnel`, `top_courses`; `meta.currencies`, `meta.timezone` |
| GET | `/admin/analytics/revenue` · `/members` · `/learning` · `/affiliates` | Same params. Definitions in `app/Analytics/AnalyticsService.php` |
| GET | `/admin/analytics/cohorts?months=3..24` | Monthly retention cohorts |
| GET | `/admin/cms/sections` · PUT `/admin/cms/sections/{key}` | 8 fixed homepage sections (defaults merged) · `content {badge, eyebrow, title, highlight, subtitle, intro, primary_cta{label,url}, secondary_cta, items[{title, body}]}`, `is_visible`, `sort` |
| GET · POST | `/admin/cms/faqs\|testimonials\|banners` | List · create |
| PATCH · DELETE | `/admin/cms/{type}/{uuid}` | Update · delete |
| PUT | `/admin/cms/faqs\|testimonials/order` | `ids[]` in the new order |
| GET · PUT | `/admin/cms/pages` · `/admin/cms/pages/{slug}` | Legal/static pages · `title, body (HTML, sanitised), is_published` |
| GET · POST | `/admin/cms/posts` | `status, search` · `title, slug?, excerpt?, body (HTML, sanitised), cover_url?, status draft\|published, published_at? (future = scheduled), seo_title?, seo_description?` |
| GET · PATCH · DELETE | `/admin/cms/posts/{uuid}` | Show · update · soft delete |
| GET | `/admin/settings` | `values` per group (secrets as `{set, last4, source admin\|env}`), `can_manage_secrets`, webhook URLs |
| PUT | `/admin/settings/{branding\|seo\|tax\|payments\|tracking}` | Group fields; blank secret = keep; `clear[]` removes secrets (fall back to .env). Payments group and any secret need `settings.secrets` (super admin) |
| GET | `/admin/audit-log` | `search, causer (uuid), subject_type, event, from, to` → who, what, record, before/after |
| GET/POST | `/admin/coupons` | `code (A-Z0-9_-), type percent\|fixed, value, currency (fixed), max_uses?, expires_at?, razorpay_offer_id?, plan_ids[]?` |
| PATCH/DELETE | `/admin/coupons/{uuid}` | Update (code/type/value immutable) / soft delete |
| POST | `/admin/plans` | `name, slug, tier_level (1-3), description?, features[{key,label,value}]?, is_active?, sort?` |
| PATCH | `/admin/plans/{uuid}` | Partial update of the above |
| DELETE | `/admin/plans/{uuid}` | Deactivate + soft delete |


