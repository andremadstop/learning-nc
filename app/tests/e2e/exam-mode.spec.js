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

  test('shows instructor onboarding or a VirtuProf entry point after login', async ({ page }) => {
    await visitLearningApp(page)

    const onboardingIntro = page.locator('.onboarding-intro')
    const virtuProfEntryPoint = page.locator('.virtuprof-rail, .virtuprof-panel, .app-virtuprof-dock .virtuprof-container').first()

    await expect.poll(async () => {
      if (await onboardingIntro.isVisible().catch(() => false)) {
        return 'onboarding'
      }
      if (await virtuProfEntryPoint.isVisible().catch(() => false)) {
        return 'virtuprof'
      }
      return 'pending'
    }, {
      timeout: 30_000,
      message: 'expected either the instructor onboarding overlay or a visible VirtuProf entry point',
    }).not.toBe('pending')
  })
})
