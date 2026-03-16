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
  await page.locator('input[name="user"]').fill(user)
  await page.locator('input[name="password"]').fill(password)
  await page.locator('input[type="submit"], button[type="submit"]').first().click()

  await page.waitForURL(/\/apps\/(dashboard|files|learning)/, { timeout: 60_000 })
  await page.goto(`${origin}/apps/learning/`)
  await page.waitForURL(/\/apps\/learning\/?/, { timeout: 60_000 })
  await page.waitForSelector('#app-content, #app, .app-learning', { timeout: 30_000 })

  await page.context().storageState({ path: stateFile })
})
