// Portfolio capture (Playwright): retina screenshots of the main features + one walkthrough
// recording. Run via the Playwright MCP "run code" tool (receives `page`), against the dev
// servers (SPA :5180) with demo data seeded. Output: portfolio/screenshots/*.png, portfolio/video/*.webm
async (page) => {
  const OUT = '/Users/donaldmethus/Sites/lms-platform/portfolio'
  const BASE = 'http://localhost:5180'
  const LESSON = '/learn/email-marketing-that-converts/01a1d010-9658-7308-a5b0-ba7ae3da196d'
  const browser = page.context().browser()
  const settle = async (p, ms = 1800) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms) }

  async function login(p, email) {
    await p.goto(`${BASE}/login`)
    await p.locator('input[type=email], input[name=email]').first().fill(email)
    await p.locator('input[type=password]').first().fill('password')
    await p.locator('form button[type=submit]').first().click()
    await p.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 })
    await settle(p, 800)
  }
  async function logout(p) {
    await p.evaluate(async () => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
      await fetch('/api/v1/auth/logout', { method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': x } })
    })
  }

  // ---------------------------------------------------------------- screenshots (2x)
  const shots = [
    { role: null, items: [['01-homepage', '/'], ['02-course-catalogue', '/courses'], ['03-course-page', '/courses/laravel-apis-for-paying-clients'], ['04-membership-pricing', '/pricing']] },
    { role: 'starter@example.com', items: [['05-learner-dashboard', '/app'], ['06-lesson-player-watermark', LESSON], ['07-certificates', '/app/certificates'], ['08-live-classes', '/app/live']] },
    { role: 'instructor@example.com', items: [['09-instructor-dashboard', '/instructor'], ['10-instructor-earnings', '/instructor/earnings']] },
    {
      role: 'admin@example.com',
      items: [
        ['11-admin-dashboard', '/admin?from=2026-01-01&to=2026-11-13'],
        ['12-admin-analytics', '/admin/analytics?from=2026-01-01&to=2026-11-13'],
        ['13-admin-users', '/admin/users'],
        ['14-admin-payments-refunds', '/admin/payments'],
        ['15-cms-homepage-editor', '/admin/cms/homepage'],
        ['16-campaign-builder', '/admin/campaigns'],
        ['17-affiliates-admin', '/admin/affiliates'],
        ['18-audit-log', '/admin/audit-log'],
      ],
    },
  ]
  const files = []
  for (const group of shots) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme: 'light' })
    const p = await ctx.newPage()
    if (group.role) await login(p, group.role)
    for (const [name, path] of group.items) {
      await p.goto(BASE + path)
      await settle(p, path === LESSON ? 4000 : 2000)
      await p.screenshot({ path: `${OUT}/screenshots/${name}.png` })
      files.push(name)
    }
    await ctx.close()
  }

  // ---------------------------------------------------------------- walkthrough video
  const vctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light', recordVideo: { dir: `${OUT}/video`, size: { width: 1280, height: 800 } } })
  const v = await vctx.newPage()
  const scroll = async (px, steps = 6) => { for (let i = 0; i < steps; i++) { await v.mouse.wheel(0, px / steps); await v.waitForTimeout(180) } }
  await v.goto(BASE + '/'); await settle(v, 1500); await scroll(1400); await v.waitForTimeout(800)
  await v.goto(BASE + '/courses'); await settle(v, 1500); await scroll(500)
  await v.goto(BASE + '/courses/laravel-apis-for-paying-clients'); await settle(v, 1500); await scroll(700)
  await v.goto(BASE + '/pricing'); await settle(v, 1800)
  await login(v, 'starter@example.com'); await v.waitForTimeout(1500)
  await v.goto(BASE + LESSON); await settle(v, 3500)
  await v.goto(BASE + '/app/certificates'); await settle(v, 1500)
  await logout(v)
  await login(v, 'admin@example.com')
  await v.goto(BASE + '/admin?from=2026-01-01&to=2026-11-13'); await settle(v, 2500); await scroll(600)
  await v.goto(BASE + '/admin/analytics?from=2026-01-01&to=2026-11-13'); await settle(v, 2000); await scroll(2600, 14)
  await v.goto(BASE + '/admin/users'); await settle(v, 1500)
  await v.locator('main tbody tr').first().click().catch(() => {}); await v.waitForTimeout(2200)
  await v.keyboard.press('Escape')
  await v.goto(BASE + '/admin/cms/homepage'); await settle(v, 1800); await scroll(500)
  await v.goto(BASE + '/admin/campaigns'); await settle(v, 1800)
  const video = v.video()
  await vctx.close()
  const videoPath = video ? await video.path() : null
  return { files, videoPath }
}
