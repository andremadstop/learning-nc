const { test, expect } = require('@playwright/test')

test.describe('Learning App Shell', () => {
  test('loads learning app shell after auth setup', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByText('Learning')).toBeVisible()
    await expect(page.getByText(/Question Pools|Pools/i)).toBeVisible()
  })
})
