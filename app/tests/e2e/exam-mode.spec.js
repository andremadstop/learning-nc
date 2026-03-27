const { test, expect } = require('@playwright/test')

async function visitLearningApp(page) {
  await page.goto('/apps/learning/')
  await expect(
    page.locator('h3, h2, h1').filter({ hasText: /Question Pools|Pools|Fragen|Lernen|Learning/i }).first()
  ).toBeVisible({ timeout: 30_000 })
}

test.describe('Learning App Shell', () => {
  test('loads learning app shell after auth setup', async ({ page }) => {
    await visitLearningApp(page)
  })

  test('shows VirtuProf dock after login', async ({ page }) => {
    await visitLearningApp(page)
    await expect(page.locator('.app-virtuprof-dock')).toBeVisible({ timeout: 30_000 })
  })
})
