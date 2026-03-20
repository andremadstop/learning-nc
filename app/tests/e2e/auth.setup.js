const { test: setup, expect } = require('@playwright/test')
const path = require('path')
const fs = require('fs')

setup('authenticate as e2e user', async ({ page }) => {
  const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080/apps/learning'
  const origin = new URL(baseURL).origin
  const user = process.env.E2E_USERNAME || 'admin'
  const password = process.env.E2E_PASSWORD || 'admin'
  const stateFile = path.join(__dirname, '.auth', 'admin.json')

  fs.mkdirSync(path.dirname(stateFile), { recursive: true })

  await page.goto(`${origin}/login`)

  // NC30 login is a Vue SPA — wait for fields to render
  await page.waitForSelector('input[name="user"], #user, input[autocomplete="username"]', { timeout: 30_000 })

  await page.locator('input[name="user"], #user, input[autocomplete="username"]').first().fill(user)
  await page.locator('input[type="password"]').first().fill(password)

  // Submit — NC30 "Log in" button
  await page.getByRole('button', { name: /log in|anmelden/i }).click()

  // Wait for URL to leave /login (any redirect target is fine)
  await page.waitForURL(url => !String(url).includes('/login'), { timeout: 60_000 })

  await page.goto(`${origin}/apps/learning/`)
  await page.waitForSelector('#app-content, #app, [id="app-learning"], .app-learning', { timeout: 30_000 })

  await page.context().storageState({ path: stateFile })
})
