const { test, expect } = require('@playwright/test')

test.describe('Learning App Shell', () => {
  test('loads learning app shell after auth setup', async ({ page }) => {
    await page.goto('/')
    await expect(page.locator('#app-content')).toBeVisible()
    await expect(page.locator('h3, h2, h1').filter({ hasText: /Question Pools|Pools|Fragen|Lernen|Learning/i }).first()).toBeVisible()
  })
})
