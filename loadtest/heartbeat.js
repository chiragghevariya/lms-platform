// Signed-in learners watching lessons: a heartbeat every 15 s per viewer (what the player sends).
// Target: 1,000 concurrent viewers · p95 < 300 ms · errors < 1%.
import { check, sleep } from 'k6'
import { auth, fixtures, pick, post } from './lib.js'

export const options = {
  scenarios: {
    watch: {
      executor: 'ramping-vus',
      stages: [
        { duration: '30s', target: Number(__ENV.VUS || 1000) },
        { duration: __ENV.HOLD || '2m', target: Number(__ENV.VUS || 1000) },
        { duration: '15s', target: 0 },
      ],
      exec: 'watch',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:heartbeat}': ['p(95)<300'],
  },
}

export function watch() {
  const token = fixtures.learnerTokens[(__VU - 1) % fixtures.learnerTokens.length]
  const lesson = pick(fixtures.lessons)
  let position = Math.floor(Math.random() * 60)
  for (let i = 0; i < 8; i++) {
    sleep(15)
    position += 15
    const r = post(`/learn/lessons/${lesson}/heartbeat`, { position_seconds: position, watched_seconds: 15, playback_rate: 1 }, auth(token), { name: 'heartbeat' })
    check(r, { 'heartbeat 200': (res) => res.status === 200 })
  }
}

export default watch
