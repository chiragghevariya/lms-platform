// Shared helpers for the k6 scenarios. BASE_URL points at the API host (no /api/v1 suffix).
import http from 'k6/http'

export const BASE = (__ENV.BASE_URL || 'http://localhost:8080') + '/api/v1'
export const fixtures = JSON.parse(open(__ENV.FIXTURES || '../backend/tests/load/fixtures.json'))
export const pick = (list) => list[Math.floor(Math.random() * list.length)]
export const json = { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } }
export const auth = (token) => ({ headers: { ...json.headers, Authorization: `Bearer ${token}` } })
export const get = (path, params = json, tags = {}) => http.get(`${BASE}${path}`, { ...params, tags })
export const post = (path, body, params, tags = {}) => http.post(`${BASE}${path}`, JSON.stringify(body), { ...params, tags })
