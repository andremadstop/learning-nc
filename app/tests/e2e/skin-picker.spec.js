/**
 * Phase 153 Plan 02 Wave-0 scaffold for TEST-04 + TEST-05.
 *
 * Combines the picker E2E flow (login → PersonalSettings → select Prof. Lern
 * Classic → assert no-reload remount → reload → assert persistence) with the
 * visual-stability assertion (per-screenshot `animations: 'disabled'`).
 *
 * Pattern (RESEARCH.md Pattern 3):
 *   - `page.emulateMedia({ reducedMotion: 'reduce' })` once in beforeEach to
 *     stop all CSS animations app-wide via the Phase 150 prefers-reduced-motion
 *     CSS block.
 *   - `animations: 'disabled'` per-screenshot — Playwright belt-and-braces over
 *     the reducedMotion media emulation. NOT a `use:` config option.
 *
 * Both functional tests are SKIPPED until Plan 04 ships PersonalSettings.vue's
 * #virtuprof-skin selector AND Plan 03 ships VirtuProfController.getSkin()
 * first-touch-coercion. Plan 04's <done> includes removing the `test.skip`
 * markers.
 *
 * Initial visual snapshot baseline (TEST-05) is generated on the first GREEN
 * run via:
 *   cd app && npx playwright test tests/e2e/skin-picker.spec.js \
 *     --project=chromium --update-snapshots
 */
const { test, expect } = require('@playwright/test')

test.describe('Skin picker (TEST-04 + TEST-05)', () => {
	test.beforeEach(async ({ page }) => {
		// TEST-05: Stop all CSS animations app-wide for stable visual runs.
		await page.emulateMedia({ reducedMotion: 'reduce' })
	})

	test('TEST-04: select Prof. Lern Classic and persist across reload', async ({ page }) => {
		test.skip(true, 'Pending Plan 04 PersonalSettings.vue + Plan 03 first-touch-coercion controller')

		await page.goto('/index.php/settings/user/learning')

		// Open the picker dropdown (NcSelect; #virtuprof-skin id is stable per Plan 04)
		await page.locator('#virtuprof-skin').click()
		await page.getByRole('option', { name: /Prof\. Lern Classic/ }).click()

		// Reactive remount without navigation (Pinia + :key)
		await expect(page.locator('.skin-renderer .prof-lern-avatar')).toBeVisible()

		// Round-trip persistence — reload, expect dropdown still on classic
		await page.reload()
		await expect(page.locator('#virtuprof-skin')).toContainText('Prof. Lern Classic')
	})

	test('TEST-05: visual stability with animations disabled', async ({ page }) => {
		test.skip(true, 'Pending Plan 04 PersonalSettings.vue + initial snapshot baseline (run with --update-snapshots once)')

		await page.goto('/index.php/settings/user/learning')
		await page.locator('#virtuprof-skin').click()
		await page.getByRole('option', { name: /Prof\. Lern Classic/ }).click()

		// Per-screenshot animations: 'disabled' = belt-and-braces over the
		// beforeEach reducedMotion. The combo is the Playwright-recommended
		// pattern for animated UI.
		await expect(page.locator('.skin-renderer')).toHaveScreenshot('skin-renderer-classic.png', {
			animations: 'disabled',
		})
	})
})
