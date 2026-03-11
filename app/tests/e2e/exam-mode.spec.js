const { test, expect } = require('@playwright/test')

test.describe('Exam Hardening', () => {
  test('shows exam setup and can start/resume flow shell', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByText('Learning')).toBeVisible()

    // This is intentionally lightweight and environment-agnostic.
    // Full authenticated flow should be run in CI against a seeded Nextcloud instance.
    await page.getByRole('button', { name: /Exam/i }).click()
    await expect(page.getByText(/Exam Mode/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /Start Exam/i })).toBeVisible()
  })
})
