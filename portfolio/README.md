# Portfolio media

Captured from the running app with demo data (no real personal data). Regenerate any time with
`capture.js` (Playwright) — see the comment at the top of that file.

| File | What it shows (suggested Upwork caption) |
|---|---|
| `webp/01-homepage.webp` | CMS-driven marketing homepage with admin-editable hero, sections, FAQ |
| `webp/02-course-catalogue.webp` | Course catalogue with filters, tiers and ratings |
| `webp/03-course-page.webp` | Course page: curriculum, instructor, reviews, preview |
| `webp/04-membership-pricing.webp` | Tiered membership pricing (Stripe + Razorpay, GST-ready) |
| `webp/05-learner-dashboard.webp` | Learner dashboard: streaks, points, badges, live classes |
| `webp/06-lesson-player-watermark.webp` | Secure HLS player with signed URLs and a moving email watermark |
| `webp/07-certificates.webp` | Verifiable PDF certificates |
| `webp/08-live-classes.webp` | Live classes (Zoom SDK) with registration and replays |
| `webp/09-instructor-dashboard.webp` | Instructor dashboard and course analytics |
| `webp/10-instructor-earnings.webp` | Revenue-share earnings (watch-time pool + course sales) |
| `webp/11-admin-dashboard.webp` | Admin KPIs: MRR, members, churn, revenue — reconciled with payments |
| `webp/12-admin-analytics.webp` | Analytics: ARR, LTV, ARPU, trial conversion, cohort retention |
| `webp/13-admin-users.webp` | User management: filters, bulk actions, impersonation, exports |
| `webp/14-admin-payments-refunds.webp` | Payments & gateway refunds |
| `webp/15-cms-homepage-editor.webp` | Built-in CMS (homepage, FAQs, banners, blog, legal pages) |
| `webp/16-campaign-builder.webp` | Email / WhatsApp / push campaign builder with segments |
| `webp/17-affiliates-admin.webp` | Affiliate program: approvals, fraud review, payouts |
| `webp/18-audit-log.webp` | Audit log of every admin action |
| `video/lms-walkthrough.webp` | 44 s animated walkthrough (1100 px, loops) |
| `video/lms-walkthrough.mp4` | Same walkthrough as MP4 (1280 px) for video slots |

`screenshots/*.png` are the 2880×1800 originals.

## Combined files
- `lms-portfolio.pdf`: all 18 screenshots in one PDF (cover page + one captioned page per feature, 19 pages, ~3.7 MB).
- `lms-all-features.png`: all 18 screenshots on one image (3 × 6 grid, 2860 × 4208).
- Regenerate both after re-capturing: `python3 make_pdf.py .` (needs Pillow).
