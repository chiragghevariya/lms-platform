// The acceptance run: 1,000 concurrent users at once — 700 browsing, 250 watching, 50 buying.
import { browse } from './catalogue.js'
import { buy } from './checkout.js'
import { watch } from './heartbeat.js'

export { browse, buy, watch }

const hold = __ENV.HOLD || '3m'
const ramp = (target) => [{ duration: '45s', target }, { duration: hold, target }, { duration: '20s', target: 0 }]

export const options = {
  scenarios: {
    browse: { executor: 'ramping-vus', stages: ramp(700), exec: 'browse' },
    watch: { executor: 'ramping-vus', stages: ramp(250), exec: 'watch' },
    buy: { executor: 'ramping-vus', stages: ramp(50), exec: 'buy' },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:listing}': ['p(95)<500'],
    'http_req_duration{name:course}': ['p(95)<500'],
    'http_req_duration{name:heartbeat}': ['p(95)<300'],
    'http_req_duration{name:checkout}': ['p(95)<800'],
  },
}
