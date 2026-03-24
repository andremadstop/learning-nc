/**
 * Global setup: Login via HTTP and save session cookies for Playwright.
 * Runs once before all tests.
 */
import https from 'https'
import fs from 'fs'

const HOST = 'devcloud.andrestiebitz.de'
const BASE = `https://${HOST}`
const USER = 'andre'
const PASS = 'Pa$$w0rd!'

function httpGet(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      let body = ''
      res.on('data', d => body += d)
      res.on('end', () => resolve({ body, headers: res.headers }))
    }).on('error', reject)
  })
}

function httpPost(url, data, cookies) {
  return new Promise((resolve, reject) => {
    const u = new URL(url)
    const req = https.request({
      hostname: u.hostname,
      port: u.port || 443,
      path: u.pathname,
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Cookie': cookies,
        'Content-Length': Buffer.byteLength(data),
      },
    }, (res) => {
      let body = ''
      res.on('data', d => body += d)
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }))
    })
    req.on('error', reject)
    req.write(data)
    req.end()
  })
}

export default async function globalSetup() {
  // Step 1: GET login page
  const { body, headers } = await httpGet(`${BASE}/login`)
  const rawCookies = headers['set-cookie'] || []
  const tokenMatch = body.match(/data-requesttoken="([^"]+)"/)
  const token = tokenMatch ? tokenMatch[1] : ''
  const cookieStr = rawCookies.map(c => c.split(';')[0]).join('; ')

  // Step 2: POST login
  const postData = `user=${encodeURIComponent(USER)}&password=${encodeURIComponent(PASS)}&timezone=Europe%2FBerlin&requesttoken=${encodeURIComponent(token)}`
  const result = await httpPost(`${BASE}/login`, postData, cookieStr)

  if (result.status !== 303) {
    throw new Error(`Login failed with status ${result.status}`)
  }

  // Step 3: Build Playwright cookies
  const allRaw = [...rawCookies, ...(result.headers['set-cookie'] || [])]
  const cookies = []
  const seen = new Set()
  for (const raw of allRaw) {
    const main = raw.split(';')[0]
    const eq = main.indexOf('=')
    const name = main.substring(0, eq)
    const value = main.substring(eq + 1)
    if (seen.has(name)) continue
    seen.add(name)
    const c = { name, value, sameSite: 'Lax', secure: true }
    if (name.startsWith('__Host-')) {
      c.url = `${BASE}/`
    } else {
      c.domain = HOST
      c.path = '/'
    }
    cookies.push(c)
  }

  // Save
  const state = { cookies, origins: [{ origin: BASE, localStorage: [] }] }
  fs.writeFileSync('tests/auth-state.json', JSON.stringify(state, null, 2))
  console.log(`Auth: ${result.status} | ${cookies.length} cookies saved`)
}
