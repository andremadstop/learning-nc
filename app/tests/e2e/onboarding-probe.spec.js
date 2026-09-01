const { test, expect } = require('@playwright/test')

/**
 * Codeberg #5, click-through verification against the live 5.4.2 deployment.
 *
 * Four defects were fixed with unit tests proven red first, but nobody has watched the result
 * render. This walks a fresh account through the introduction and then asks the question the
 * whole issue is about: does it come back?
 */
const ORIGIN = new URL(process.env.E2E_BASE_URL || 'https://devcloud.andrestiebitz.de/apps/learning').origin
const USER = process.env.E2E_USERNAME
const PASS = process.env.E2E_PASSWORD

async function login(page) {
  await page.goto(`${ORIGIN}/login`, { waitUntil: 'domcontentloaded' })
  const u = page.locator('input[name="user"], #user, input[autocomplete="username"]').first()
  const p = page.locator('input[type="password"]').first()
  await u.waitFor({ state: 'visible', timeout: 60000 })
  await u.click()
  await u.pressSequentially(USER, { delay: 40 })
  await p.click()
  await p.pressSequentially(PASS, { delay: 40 })
  await page.waitForTimeout(300)
  const btn = page.getByRole('button', { name: /log in|anmelden/i }).first()
  if (await btn.isVisible({ timeout: 5000 }).catch(() => false)) {
    await btn.click()
  } else {
    await p.press('Enter')
  }
  await page.waitForURL(url => !String(url).includes('/login'), { timeout: 60000 })
}

test('a new user meets the introduction, dismisses it, and it stays gone', async ({ page }) => {
  await login(page)

  // 1. First visit — the wizard must appear for a genuinely new account.
  await page.goto(`${ORIGIN}/apps/learning/`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(4000)
  await page.screenshot({ path: 'probe-1-first-visit.png', fullPage: false })

  const wizard = page.locator('.onb-screen, .onb-splash, [class*="onb-"]').first()
  const wizardVisible = await wizard.isVisible({ timeout: 15000 }).catch(() => false)
  console.log('STEP1 wizard visible on first visit:', wizardVisible)
  expect(wizardVisible, 'wizard should appear for a brand new account').toBe(true)

  // 2. Skip it — skipping is the path that used to record nothing server-side.
  // The global skip button sits at top:16px right:16px with z-index 10 — underneath the
  // Nextcloud header, which swallows the click. The splash screen carries its own skip link at
  // the bottom, which is the only one a user can actually reach on this step.
  const skip = page.locator('.onb-skip-link, .onb-skip-global').last()
  if (await skip.isVisible({ timeout: 5000 }).catch(() => false)) {
    await skip.click()
    await page.waitForTimeout(800)
    const confirm = page.getByRole('button', { name: /überspringen|skip|ja|yes/i }).last()
    if (await confirm.isVisible({ timeout: 4000 }).catch(() => false)) {
      await confirm.click()
    }
  }
  await page.waitForTimeout(4000)
  await page.screenshot({ path: 'probe-2-after-skip.png', fullPage: false })
  console.log('STEP2 skipped')

  // 3. Wipe local storage entirely — this simulates the second device, the different browser,
  //    the cleared site data. Before 5.4.2 this alone brought the wizard back.
  await page.evaluate(() => window.localStorage.clear())
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(5000)
  await page.screenshot({ path: 'probe-3-fresh-storage.png', fullPage: false })

  const wizardAgain = await page.locator('.onb-screen, .onb-splash').first()
    .isVisible({ timeout: 8000 }).catch(() => false)
  console.log('STEP3 wizard visible after clearing local storage:', wizardAgain)
  expect(wizardAgain, 'the reported bug: wizard must NOT come back on a wiped browser').toBe(false)
})
