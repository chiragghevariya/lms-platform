// Anonymous visitors browsing the catalogue: home content, listing (filters, pages), course pages.
// Target: 1,000 concurrent users · p95 < 500 ms · errors < 1%.
import { check, sleep } from 'k6'
import { fixtures, get, pick } from './lib.js'

export const options = {
  scenarios: {
    browse: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: Number(__ENV.VUS || 1000) },
        { duration: __ENV.HOLD || '2m', target: Number(__ENV.VUS || 1000) },
        { duration: '20s', target: 0 },
      ],
      exec: 'browse',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:listing}': ['p(95)<500'],
    'http_req_duration{name:course}': ['p(95)<500'],
    'http_req_duration{name:home}': ['p(95)<500'],
  },
}

const SORTS = ['popular', 'newest', 'rating']

export function browse() {
  check(get('/cms/home', undefined, { name: 'home' }), { 'home 200': (r) => r.status === 200 })
  get('/site', undefined, { name: 'home' })
  sleep(1 + Math.random() * 2)

  const qs = `?sort=${pick(SORTS)}&page=${1 + Math.floor(Math.random() * 3)}` + (Math.random() < 0.3 ? `&category=${pick(fixtures.categories)}` : '')
  check(get(`/courses${qs}`, undefined, { name: 'listing' }), { 'listing 200': (r) => r.status === 200 })
  sleep(1 + Math.random() * 3)

  const slug = pick(fixtures.courses)
  check(get(`/courses/${slug}`, undefined, { name: 'course' }), { 'course 200': (r) => r.status === 200 })
  get(`/courses/${slug}/reviews`, undefined, { name: 'course' })
  sleep(2 + Math.random() * 4)
}

export default browse
