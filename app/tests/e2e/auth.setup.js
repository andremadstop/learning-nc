const { test: setup, expect } = require('@playwright/test')
const path = require('path')
const fs = require('fs')

setup('authenticate as e2e user', async ({ page }) => {
  const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080/apps/learning'
  const origin = new URL(baseURL).origin
  const user = process.env.E2E_USERNAME
  const password = process.env.E2E_PASSWORD
  const stateFile = path.join(__dirname, '.auth', 'admin.json')
  const screenshotDir = path.join(process.cwd(), 'test-results')
  const screenshotFile = path.join(screenshotDir, 'auth-setup-failure.png')

  fs.mkdirSync(path.dirname(stateFile), { recursive: true })
  fs.mkdirSync(screenshotDir, { recursive: true })

  if (!user || !password) {
    throw new Error('E2E auth setup requires E2E_USERNAME and E2E_PASSWORD environment variables.')
  }

  try {
    await page.goto(`${origin}/login`, { waitUntil: 'domcontentloaded' })

    const usernameInput = page.locator('input[name="user"], #user, input[autocomplete="username"]').first()
    const passwordInput = page.locator('input[type="password"]').first()
    const submitButton = page.getByRole('button', { name: /log in|anmelden/i }).first()

    // NC30 login is a Vue SPA and can be slow on the dev host.
    await usernameInput.waitFor({ state: 'visible', timeout: 90_000 })
    await passwordInput.waitFor({ state: 'visible', timeout: 90_000 })

    await usernameInput.fill(user)
    await passwordInput.fill(password)

    // Submit — button if rendered, otherwise Enter fallback on password input.
    if (await submitButton.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await submitButton.click()
    } else {
      await passwordInput.press('Enter')
    }

    // Wait for URL to leave /login (any redirect target is fine).
    await page.waitForURL(url => !String(url).includes('/login'), { timeout: 90_000 })

    await page.goto(`${origin}/apps/learning/`, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#app-content, #app, [id="app-learning"], .app-learning', { timeout: 90_000 })

    await page.context().storageState({ path: stateFile })
  } catch (error) {
    await page.screenshot({ path: screenshotFile, fullPage: true }).catch(() => {})
    throw error
  }
})
