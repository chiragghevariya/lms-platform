// Bundle analysis + budget: gzip size of every chunk, what the first page load pulls in
// (index.html's entry + modulepreloads), and a hard budget for that initial load.
// Usage: npm run build && node scripts/bundle-report.mjs   (CI fails if the budget is exceeded)
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const dist = new URL('../dist/', import.meta.url).pathname
const BUDGET_KB = Number(process.env.INITIAL_JS_BUDGET_KB ?? 200) // gzip

const gz = (file) => gzipSync(readFileSync(file)).length / 1024
const assets = readdirSync(join(dist, 'assets')).filter((f) => f.endsWith('.js') || f.endsWith('.css'))
const rows = assets
  .map((f) => ({ f, raw: statSync(join(dist, 'assets', f)).size / 1024, gz: gz(join(dist, 'assets', f)) }))
  .sort((a, b) => b.gz - a.gz)

const html = readFileSync(join(dist, 'index.html'), 'utf8')
const initial = [...html.matchAll(/(?:src|href)="\/assets\/([^"]+\.(?:js|css))"/g)].map((m) => m[1])
const initialKb = rows.filter((r) => initial.includes(r.f)).reduce((s, r) => s + r.gz, 0)

console.log('Largest chunks (gzip):')
for (const r of rows.slice(0, 15)) {
  console.log(`  ${r.gz.toFixed(1).padStart(7)} KB  (${r.raw.toFixed(0).padStart(5)} KB raw)  ${initial.includes(r.f) ? '[initial] ' : ''}${r.f}`)
}
console.log(`\nInitial load (entry + preloads, gzip): ${initialKb.toFixed(1)} KB · budget ${BUDGET_KB} KB`)
if (initialKb > BUDGET_KB) {
  console.error('✗ Initial JS/CSS over budget — lazy-load the new dependency or split it out.')
  process.exit(1)
}
console.log('✓ Within budget')
