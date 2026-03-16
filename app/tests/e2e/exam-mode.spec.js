const { test, expect } = require('@playwright/test')

test.describe('Learning App Shell', () => {
  test('loads learning app shell after auth setup', async ({ page }) => {
    await page.goto('/apps/learning/')
    await page.waitForSelector('#app-content', { timeout: 30_000 })
    await expect(page.locator('h3, h2, h1').filter({ hasText: /Question Pools|Pools|Fragen|Lernen|Learning/i }).first()).toBeVisible()
  })
})
