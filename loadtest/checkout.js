// Buyers starting checkout (sandbox gateway with ~150 ms simulated provider latency).
// Target: p95 < 800 ms (incl. provider latency) · errors < 1%.
import { check, sleep } from 'k6'
import { auth, fixtures, get, pick, post } from './lib.js'

export const options = {
  scenarios: {
    buy: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: Number(__ENV.VUS || 200) },
        { duration: __ENV.HOLD || '2m', target: Number(__ENV.VUS || 200) },
        { duration: '15s', target: 0 },
      ],
      exec: 'buy',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:checkout}': ['p(95)<800'],
    'http_req_duration{name:plans}': ['p(95)<500'],
  },
}

export function buy() {
  const token = fixtures.buyerTokens[(__VU - 1) % fixtures.buyerTokens.length]
  get('/plans', undefined, { name: 'plans' })
  sleep(2 + Math.random() * 3)
  const r = post('/billing/checkout', { plan_price_id: pick(fixtures.prices) }, auth(token), { name: 'checkout' })
  check(r, { 'checkout 201': (res) => res.status === 201 })
  sleep(5 + Math.random() * 5)
}

export default buy
