const { test, expect } = require('@playwright/test')

test.describe('Learning App Shell', () => {
  test('loads learning app shell after auth setup', async ({ page }) => {
    await page.goto('/')
    await page.waitForSelector('#app-content, #app, .app-learning', { timeout: 30_000 })
    await expect(page.locator('h3, h2, h1').filter({ hasText: /Question Pools|Pools|Fragen|Lernen|Learning/i }).first()).toBeVisible()
  })
})
