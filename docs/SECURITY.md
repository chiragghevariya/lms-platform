# Security (Phase 10)

Reviewed and scanned on 2026-11-13. The ZAP reports are in `docs/security/`.

## Automated scan: OWASP ZAP 2.17.0
**Result: 0 High, 0 Critical.** The API host has **no Medium findings** either.

- **Scope:** the production-like stack (debug off, config cached) with an **authenticated** learner token. 32 seeded API requests (GET and POST: auth, contact, heartbeat, notes, coupons, billing, `/me/*`, and admin endpoints, which must return 403), plus a spider of the built SPA served with the production headers. Passive scan and **active scan** (injection, XSS, path traversal and so on).
- **Re-run:** `zap.sh -cmd -autorun loadtest/zap-plan.json`, with `ZAP_TOKEN` set to a test learner's token.

| Risk | Finding | Where | Status |
|---|---|---|---|
| Medium | CSP `style-src 'unsafe-inline'` | SPA | **Accepted**: React `style` props, Radix positioning, sonner and Plyr set inline styles. Scripts stay strict (no inline scripts except one hashed snippet; no `eval` apart from `wasm-unsafe-eval` for the Zoom SDK). |
| Medium | HTTP only site | SPA | **Local artefact**: the test server had no TLS. Production is HTTPS-only with HSTS (`deploy/nginx/lms.conf`). |
| Info | Suspicious comments; modern web application; authentication request identified | SPA / API | Informational |

## OWASP Top 10 review
Every non-admin controller with a route-bound object was reviewed for object-level authorisation, plus mass assignment, SQL injection, uploads, redirects and rate limits. Admin routes are gated per area by `can:` permission middleware (Phase 9).

**Fixed during the review (with regression tests in `tests/Feature/Security/HardeningTest.php` and `tests/Feature/Auth/GoogleLoginTest.php`):**
1. **High: Google mobile sign-in token replay.** `POST /auth/google/token` accepted any Google access token, so a token issued to *another* app the victim used could sign in as them. It now checks the token's `aud`/`azp` against `GOOGLE_CLIENT_ID` + `GOOGLE_MOBILE_CLIENT_IDS` via Google's `tokeninfo` endpoint.
2. **Medium: instructors could attach any instructor's video** (by id) to their own lessons as a free preview. The `OwnVideo` rule now allows only your own uploads, or the video already attached.
3. **Low/medium: device slots of other users could be cleared** through `DELETE /me/sessions/token_{id}`. Ownership is now checked before anything is touched.
4. **Low: preview-lesson quizzes and assignments accepted submissions from non-members.** Writes now require course access (reading previews is still open).
5. **Low: password change and instructor uploads lacked a dedicated throttle.** `throttle:6,1` and `throttle:uploads` added.

**Checked and OK:** learner-owned objects (notes, bookmarks, attempts, certificates, statements, notifications); lesson access (publish state, tier, purchase, drip); instructor studio writes via the parent course policy; scoped reorders; live-session management; billing ownership and webhook signatures (all `hash_equals`); mass assignment (`validated()` / explicit arrays everywhere, and `$guarded` models never receive request input); SQL (no user input in raw fragments); no open redirects.

## Controls in place

| Area | Control |
|---|---|
| **Headers** | `SecurityHeaders` middleware on every Laravel response: strict CSP for the JSON API (`default-src 'none'; frame-ancestors 'none'`), small same-origin CSP for server-rendered pages, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, HSTS over HTTPS, `X-Powered-By` removed. SPA headers and CSP live in `deploy/nginx/lms.conf` (or a CloudFront response headers policy). |
| **CORS** | Only `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL`, with credentials; other origins get no allow-origin. |
| **CSRF** | Sanctum SPA cookies with XSRF token; API tokens for mobile. |
| **XSS** | All rich text purified server-side (HTMLPurifier profiles `rich_text`, `cms`) and DOMPurify on render. |
| **Uploads** | Mime/extension and size rules on every upload; random server-side names; private disks for learner files; **malware scan hook** `CleanUpload` → `UploadScanner` (ClamAV `clamd` INSTREAM over a socket, `UPLOAD_SCAN_DRIVER=clamav`). Fails closed if the scanner is down (`UPLOAD_SCAN_FAIL_CLOSED`). |
| **Bot protection** | Cloudflare **Turnstile** (free) on register, login, contact and forgot-password when `CAPTCHA_DRIVER=turnstile`. The token goes through `X-Captcha-Token` and verification fails closed. Put Cloudflare's WAF managed rules and Bot Fight Mode in front of both hosts (plan-dependent; see "Cloudflare" below). |
| **Rate limits** | Global `api` (`API_RATE_LIMIT`/min per user or IP), `admin` (300/min), `uploads` (10/min, 60/h), `auth`, `otp`, `two-factor`, `contact`, password change 6/min, exports 10/min, checkout, heartbeat, playback. |
| **Account sharing** | Learners can be signed in on `MAX_DEVICES` (2) devices; a new sign-in pushes out the least recently used one (its token is revoked, or its browser session ends with `device_signed_out`). Staff are exempt. Users manage devices in Security settings. |
| **Content protection** | Short-lived **signed** Bunny HLS URLs (token auth), refreshed on expiry; poster signed too; no download control; **moving watermark** with the viewer's email and id, drawn inside the player container so it stays visible in fullscreen. In Bunny → Stream library → Security, enable **Token authentication**, **Allowed referrers** (your SPA domain) and **Block direct URL file access** to restrict playback to your site. |
| **Secrets** | Only in env or encrypted settings (`settings` table, APP_KEY); never returned by the API (only "set" + last 4 characters); never written to the audit log. |
| **Encryption at rest** | Payout details (instructors, affiliates), instructor tax ids, 2FA secrets and recovery codes, Zoom join/host URLs and passcodes, **billing addresses and tax ids** (users and invoice copies). |
| **Privacy (GDPR / DPDP)** | `GET /me/export` (all personal data as JSON); `DELETE /me` (password or typed confirmation). Personal data is deleted or anonymised; invoices are kept for tax law (GST: 8 years) on an anonymised account. Renewals are cancelled at the gateway first. Blocked for staff, instructors with published courses, and unpaid affiliate balances. **Cookie consent** banner (only when GA4 or Meta Pixel is configured), with Reject all as easy as Accept all and a "Cookie settings" link in the footer; nothing non-essential loads before consent. |
| **Audit** | Admin actions and admin-managed records (Phase 9) plus privacy events (`account.exported`, `account.deleted`). |
| **Impersonation** | Browser-only, never staff, audited; security, billing and admin writes are blocked while it's active. |

## Cloudflare (recommended, in front of both hosts)
- **Free plan:** proxied DNS, TLS, DDoS protection, **Bot Fight Mode**, a free managed ruleset, rate-limiting rules (limited), **Turnstile**.
- **Pro plan (paid, ask before enabling):** the OWASP Core Ruleset and Cloudflare Managed Rules (full WAF).
- Suggested rules: block non-`GET` requests to `/api/v1/*` from outside the SPA and app origins, unless they're webhooks (exempt `/api/v1/webhooks/*`); challenge `/api/v1/auth/*` above 20 requests/min per IP; cache `/assets/*`.
- When using Cloudflare, set `TRUSTED_PROXIES` to Cloudflare's IP ranges (or use `CF-Connecting-IP`) so rate limits see real client IPs.

## Operations checklist
- `APP_DEBUG=false`, `APP_ENV=production`, a unique `APP_KEY` (rotating it makes encrypted settings unreadable, so re-enter secrets afterwards).
- PHP: `expose_php=Off`.
- Run `php artisan db:seed --class=RolesAndPermissionsSeeder` after each deploy that adds permissions.
- Run ClamAV (`clamd`) on upload-handling servers and set `UPLOAD_SCAN_DRIVER=clamav`.
- Re-run the ZAP plan against staging before each release (Phase 11 CI).
