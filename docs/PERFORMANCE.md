# Performance & scalability (Phase 10)

Measured on 2026-11-13. Raw outputs are in `docs/performance/` (k6 summaries, Lighthouse HTML reports).

## Summary

| Target | Result |
|---|---|
| k6: 1,000 concurrent users on catalogue, player heartbeat and checkout | ✅ **Mixed run of 1,000 VUs** (700 browsing, 250 watching, 50 buying) for 3 minutes: **0.49% errors**; p95 listing **77 ms**, course **82 ms**, heartbeat **262 ms**, checkout **312 ms** (including 150 ms of simulated gateway latency) |
| Lighthouse ≥ 90 on home and course pages | ✅ Over HTTP/2 + TLS (how production serves the SPA): home **97 / 100 / 100 / 100**, course **95–96 / 100 / 100 / 100** (performance / accessibility / best practices / SEO; mobile, three runs each) |
| Initial JS + CSS budget | ✅ **153 KB gzip** (budget 200 KB, enforced by `npm run bundle:report`) |

## How the load test was run

**Environment:** one MacBook (8 cores, 8 GB RAM) running *both* the load generator (k6) and the whole stack. That is a deliberately harsh setup; production separates them.

| Part | Setup |
|---|---|
| Web | nginx 1.31.8, gzip, php-fpm 8.3 over a Unix socket, **16 workers** (`pm = static`), OPcache with `validate_timestamps=0`, config/route/event caches |
| Database | PostgreSQL 16 (local). Production targets MySQL 8 / RDS; the query layer is the same, and every migration also ran on Postgres |
| Cache / sessions / queue | Redis 8.10.3 over a Unix socket (Predis, persistent connections) |
| Payments | `BILLING_SANDBOX_GATEWAY=true`: a fake gateway that sleeps 150 ms in place of the Stripe/Razorpay API call (refuses to run in production) |
| Data | Demo seed, plus `php artisan loadtest:prepare --learners=1000 --buyers=200` (members with API tokens; buyers without a membership) |

```bash
# 1. backend/.env.loadtest  (APP_ENV=loadtest, pgsql/mysql, CACHE_STORE=redis, SESSION_DRIVER=redis,
#    API_RATE_LIMIT=1000000, BILLING_SANDBOX_GATEWAY=true, DB_PERSISTENT=true)
APP_ENV=loadtest php artisan migrate:fresh --seed --force
APP_ENV=loadtest php artisan loadtest:prepare --learners=1000 --buyers=200
APP_ENV=loadtest php artisan config:cache && APP_ENV=loadtest php artisan route:cache
# 2. run nginx + php-fpm against backend/public
# 3. from another machine (ideally):
cd loadtest
BASE_URL=https://staging-api.example.com VUS=1000 HOLD=2m k6 run catalogue.js
BASE_URL=... VUS=1000 k6 run heartbeat.js
BASE_URL=... VUS=200  k6 run checkout.js
BASE_URL=... HOLD=3m  k6 run mixed.js     # the acceptance run
```

Never point `loadtest:prepare` or the sandbox gateway at production. The command refuses in `production`, and the gateway override is ignored there.

## Results

### Catalogue, 1,000 anonymous visitors (`catalogue.js`)
Each visitor loads the homepage content, a listing (random sort, page and category) and a course page with its reviews, with think time between requests.

| | p95 | median | errors |
|---|---|---|---|
| listing | 106 ms | 7 ms | |
| home (`/cms/home`, `/site`) | 111 ms | 7 ms | |
| course (+ reviews) | 486 ms | 14 ms | |
| **total** | | | **0.25%** at 476 req/s |

### Player heartbeat, 1,000 concurrent viewers (`heartbeat.js`)
One heartbeat every 15 s per viewer, the same as the real player. Each writes lesson progress, the learning streak, watch time for instructor earnings and queues a progress recalculation.

| | p95 | median | errors |
|---|---|---|---|
| heartbeat | **59 ms** | 26 ms | **0%** |

### Mixed acceptance run, 1,000 VUs for 3 minutes (`mixed.js`)
| | p95 | errors (checks) |
|---|---|---|
| listing | 77 ms | 0.53% |
| course | 82 ms | 0.44% |
| heartbeat | 262 ms | 0.55% |
| checkout (incl. 150 ms provider) | 312 ms | 1.44% |
| **all requests** | | **0.49%** (threshold < 1%) at 343 req/s |

All the failures were connections php-fpm refused while its listen queue was full. macOS caps that queue at 128 (`kern.ipc.somaxconn`); Linux servers are configured far higher. No request failed inside the application.

## What the load test found (fixed)
1. **Heartbeats crashed on PostgreSQL**: `ON CONFLICT … SET seconds = seconds + n` is ambiguous there (it could mean the stored row or the incoming one). Both upserts (`learning_days`, `course_watch_daily`) now qualify the column. SQLite in the test suite never showed it.
2. **Homepage content and site settings hit the database on every request.** Guests now get cached JSON (`CatalogCache`, invalidated by CMS, settings and catalogue changes). Home p95 fell from 752 ms to 111 ms.
3. **Too many php-fpm workers made latency worse**: at 64 workers on 8 cores the heartbeat p95 was 525–646 ms; at 16 it was 59 ms. **Sizing rule: about 2 workers per vCPU** for this I/O-light app, and scale out with more servers rather than more workers per server.
4. **Redis outage resilience**: settings loading now falls back to the database if the cache store is unreachable, rather than failing every request.
5. **Ephemeral-port exhaustion** (a local test artefact): TCP for nginx→fpm and PHP→Redis ran out of loopback ports. Use Unix sockets on a single box; across servers, use persistent connections (`DB_PERSISTENT`, `REDIS_PERSISTENT`) or a pooler.

## Frontend performance
- **Route-level code splitting** (every page is `lazy`). The dashboard shells (student, instructor, admin), Radix menus, the video player (hls.js + Plyr, 207 KB gzip), the rich text editor, charts and the Zoom SDK all load only when used. The theme and mobile menus load Radix on first click.
- **Initial load: 153 KB gzip** (it was 188 KB). `npm run bundle:report` prints the largest chunks and fails CI above `INITIAL_JS_BUDGET_KB` (default 200).
- **Prefetch on intent**: hovering or focusing a course card loads its page chunk and data. Opening `/courses/:slug` directly starts the data request alongside the chunk (route loader) rather than after it.
- **Images**: course thumbnails get WebP variants at 480, 960 and 1600 px at upload (GD), served with `srcset`/`sizes`, explicit dimensions, `loading="lazy"` and `decoding="async"` (the hero image gets `fetchpriority="high"`).
- **First paint**: a static app shell in `index.html` paints before the JS arrives. The theme script is inline (allowed by a CSP `sha256` hash, so the CSP still forbids inline scripts). The public layout reserves a full screen of height so the footer can't shift.
- **Lighthouse**, mobile emulation (Moto G Power, slow 4G):

| Page | HTTP/2 + TLS (production-like) | HTTP/1.1, no TLS (local only) |
|---|---|---|
| Home | **97** / 100 / 100 / 100 · LCP 2.3 s · CLS 0 | 90–91 |
| Course | **95–96** / 100 / 100 / 100 · LCP 2.4–2.5 s · CLS 0 | 87–89 |

Over HTTP/1.1 the browser opens only 6 connections, so the course page's ~30 small lazy chunks queue up. Serve the SPA over HTTP/2 or HTTP/3 (CloudFront, or nginx `http2 on;`), as `deploy/nginx/lms.conf` does.

## Caching layers
| Layer | What | Invalidation |
|---|---|---|
| Browser/CDN | `/assets/*`: 1 year, `immutable` (hashed names); `index.html`: `no-cache` | Deploy (new hashes) |
| CDN + browser | Anonymous catalogue/home/site JSON: `public, max-age=60, s-maxage=300` | Expires; edits bump the server cache immediately |
| Redis (`CATALOG_CACHE_STORE`) | Anonymous `/courses`, `/courses/{slug}`, `/categories`, `/cms/home`, `/site` | Version bump on any Course, Category, Review, Section, Lesson, Video, CMS model or settings change |
| Redis (`ANALYTICS_CACHE_STORE`) | Admin analytics reports | Generation bump on payment, refund and subscription events; nightly warm-up |
| Redis (default store) | Site settings, device checks, rate limits | Settings: on save (workers reload via a version stamp) |
| OPcache + `config:cache` / `route:cache` / `event:cache` | PHP | Deploy |

## Scaling architecture

```
             CloudFront (SPA, assets, images) ── S3 (frontend build, uploads, invoices)
                         │
 users ── CloudFront/ALB (TLS, WAF) ── web 1..N  (nginx + php-fpm, stateless)
                                          │
                     ┌────────────────────┼───────────────────────┐
              RDS MySQL primary ──► read replica(s)      ElastiCache Redis
             (writes, sticky reads)  (DB_READ_HOST)      (cache, sessions, queues, locks)
                                          │
                          workers 1..M (Horizon: critical / messaging / media)
                          scheduler (one server: `schedule:run` every minute, onOneServer)
```

- **Stateless web servers**: `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `FILESYSTEM_DISK=s3` (avatars, thumbnails, invoices and lesson resources on S3). Add or remove servers behind the load balancer freely. Set `TRUSTED_PROXIES` to the load balancer's range so client IPs, rate limits and HTTPS detection work.
- **Device limit and sessions** use their own `user_devices` table, so they work the same with Redis sessions.
- **Read replica**: set `DB_READ_HOST=replica1,replica2`. Reads go to replicas, writes to the primary, and `sticky` keeps a request's reads on the primary after it writes.
- **Horizon** runs on separate worker servers with three supervisors (`config/horizon.php`):
  - `supervisor-critical`: `webhooks`, `default`. Scale on wait time; payments unlock access.
  - `supervisor-messaging`: `notifications`, `mail`. Scale on queue size; campaigns are bursty.
  - `supervisor-media`: `media`.
- **Scheduler**: on exactly one server (`onOneServer()` needs the shared Redis cache).
- **Octane (optional)**: the app's singletons (`SiteSettings`, `AnalyticsService`) are request-safe apart from settings, which already reload via their version stamp. Octane + FrankenPHP or Swoole would remove per-request bootstrap cost (~5–8 ms CPU). It isn't enabled; php-fpm met the targets.
- **Connection pooling**: with many web servers, use RDS Proxy / ProxySQL rather than `DB_PERSISTENT=true`.

### Rough sizing (from the measurements)
- One 8-vCPU box served about **475 cached catalogue req/s** while also running the load generator and database, or about **60 heartbeat req/s with 59 ms p95** (writes).
- 1,000 concurrent learners ≈ 67 heartbeats/s + ~0.5 catalogue req/s each while browsing. **Start with two c7g.large (2 vCPU) web servers** behind the load balancer (redundancy plus headroom), one t4g.medium Redis and a db.t4g.medium MySQL with a replica. Add web servers when CPU passes 60% sustained; the app has no per-server state.
- Re-run `mixed.js` against staging from a separate machine before launch (Phase 11), and after major features.
