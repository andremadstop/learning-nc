const { test, expect } = require('@playwright/test')

/**
 * Is the wizard's global skip button actually reachable? It sits at top:16px right:16px with
 * z-index 10, which is where the Nextcloud header lives. The splash screen has its own skip link
 * at the bottom, so step 1 is fine — but from step 2 onwards the global button is the only way
 * out. If the header swallows the click, a user cannot leave the wizard at all, which is exactly
 * what Codeberg #5 is complaining about.
 */
const ORIGIN = new URL(process.env.E2E_BASE_URL || 'https://devcloud.andrestiebitz.de/apps/learning').origin

test('the global skip button can be clicked past the first step', async ({ page }) => {
  // goto first: localStorage is not reachable on about:blank.
  await page.goto(`${ORIGIN}/apps/learning/`, { waitUntil: 'domcontentloaded' })
  await page.evaluate(() => window.localStorage.clear())
  await page.reload({ waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(4000)

  // Needs an account that still gets the wizard — since 5.4.2 that is server state, so clearing
  // browser storage does not provision it. Skip loudly instead of failing; reset with
  //   occ user:setting --delete <uid> learning onboarding_acknowledged
  const start = page.getByRole('button', { name: /start mission|mission starten/i }).first()
  const wizardShowing = await start.isVisible({ timeout: 15000 }).catch(() => false)
  test.skip(!wizardShowing,
    'needs an account with onboarding_acknowledged unset — see the occ command above')

  // Advance past the splash into step 2, where the global button is the only exit.
  await start.click()
  await page.waitForTimeout(2500)

  const skip = page.locator('.onb-skip-global').first()
  const visible = await skip.isVisible().catch(() => false)
  console.log('SKIP visible:', visible)

  // What does the browser hand a click at the button's own centre to?
  const whoGetsTheClick = await page.evaluate(() => {
    const el = document.querySelector('.onb-skip-global')
    if (!el) return 'button not in DOM'
    const r = el.getBoundingClientRect()
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
    return hit === el || el.contains(hit)
      ? 'the skip button itself'
      : `${hit?.tagName}.${hit?.className} (inside #${hit?.closest('[id]')?.id || '?'})`
  })
  console.log('CLICK LANDS ON:', whoGetsTheClick)

  const clickable = await skip.click({ timeout: 5000 }).then(() => true).catch(() => false)
  console.log('SKIP clickable:', clickable)
  expect(clickable, 'a user must be able to leave the wizard from step 2').toBe(true)
})
