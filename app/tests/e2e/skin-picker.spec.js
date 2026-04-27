/**
 * Phase 153 Plan 05 — TEST-04 + TEST-05 (LIVE — un-skipped).
 *
 * Picker E2E flow (login → PersonalSettings → select Prof. Lern → reload →
 * assert persistence) + visual-stability assertion on the picker control.
 *
 * Pattern (RESEARCH.md Pattern 3):
 *   - `page.emulateMedia({ reducedMotion: 'reduce' })` once in beforeEach.
 *   - `animations: 'disabled'` per-screenshot — Playwright belt-and-braces.
 *
 * Selector contract (Plan 04, app/src/components/PersonalSettings.vue:118-125):
 *   <NcSelect id="virtuprof-skin" ... /> renders a wrapper div with the id,
 *   containing `.vs__dropdown-toggle` (clickable). Display name from
 *   characters.js is "Prof. Lern" (NOT "Prof. Lern Classic" — the id is
 *   `prof_lern_classic` but the user-facing label is shorter).
 *
 * Note: The settings page mounts its own Vue app at #learning-personal-settings
 * and does NOT include the SkinRenderer/VirtuProf dock (those live in the main
 * learning App at #learning). Persistence verification therefore rounds through
 * the picker state (load → /api/virtuprof/state → form.skinId → NcSelect
 * model-value), which exercises the Plan 03 first-touch-coercion path on the
 * server side.
 *
 * Initial visual snapshot baseline (TEST-05) is generated on the first run via:
 *   cd app && npx playwright test tests/e2e/skin-picker.spec.js \
 *     --project=chromium --update-snapshots
 */
const { test, expect } = require('@playwright/test')

/**
 * Reset the active user's virtuprof_skin to 'nova' via the savePreferences
 * endpoint so each test starts from a deterministic state. Uses Playwright's
 * `request` API which inherits the storageState session (no extra auth round-
 * trip). NC PUT requires the requesttoken header — fetched via the session.
 */
async function resetSkinToNova(page) {
	await page.goto('/index.php/settings/user/learning')
	await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
	const requesttoken = await page.evaluate(() => document.head.querySelector('meta[name="requesttoken"]')?.content || window.OC?.requestToken)
	const response = await page.request.put('/index.php/apps/learning/api/virtuprof/preferences', {
		headers: { 'OCS-APIRequest': 'true', requesttoken: requesttoken || '' },
		data: { skin: 'nova' },
	})
	if (!response.ok()) throw new Error(`reset failed: ${response.status()} ${await response.text()}`)
}

test.describe('Skin picker (TEST-04 + TEST-05)', () => {
	test.beforeEach(async ({ page }) => {
		// TEST-05: Stop all CSS animations app-wide for stable visual runs.
		await page.emulateMedia({ reducedMotion: 'reduce' })
		// Deterministic start state: virtuprof_skin row → 'nova' before each test.
		await resetSkinToNova(page)
	})

	test('TEST-04: select Prof. Lern and persist across reload', async ({ page }) => {
		await page.goto('/index.php/settings/user/learning')
		// Wait for the personal-settings Vue app to mount and the form to render
		// (loading is async — depends on /api/settings/personal + /api/virtuprof/state).
		await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })

		// Open the picker dropdown — the clickable element is the inner
		// .vs__dropdown-toggle (NcSelect renders the `id` on the outer wrapper).
		const picker = page.locator('#virtuprof-skin')
		await picker.locator('.vs__dropdown-toggle').click()
		// Pick "Prof. Lern" — exact display name from characters.js (id remains
		// 'prof_lern_classic' under the hood; label is shorter).
		await page.getByRole('option', { name: 'Prof. Lern', exact: true }).click()

		// Save the change so the controller persists virtuprof_skin server-side.
		await page.getByRole('button', { name: /^Save$|^Speichern$/ }).click()
		// Wait for the success NcNoteCard ("Settings saved" / "Einstellungen gespeichert")
		await expect(page.locator('.learning-settings-wrap')).toContainText(/Settings saved|Einstellungen gespeichert/, { timeout: 10_000 })

		// Reactive: picker now shows the new selection without navigation.
		await expect(picker).toContainText('Prof. Lern')

		// Round-trip persistence — reload, expect picker still on Prof. Lern
		// (this exercises Plan 03's controller getSkin() returning the persisted
		// virtuprof_skin user_config row, NOT first-touch-coercion).
		await page.reload()
		await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
		await expect(picker).toContainText('Prof. Lern')
	})

	test('TEST-05: visual stability of picker with animations disabled', async ({ page }) => {
		await page.goto('/index.php/settings/user/learning')
		await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })

		// Open the picker so the dropdown selection ("NOVA" — set by the
		// beforeEach reset) is fully painted before the screenshot.
		const picker = page.locator('#virtuprof-skin')
		// Per-screenshot animations: 'disabled' = belt-and-braces over the
		// beforeEach reducedMotion. The combo is the Playwright-recommended
		// pattern for animated UI. The picker control is the only stable target
		// on the settings page (SkinRenderer/VirtuProf dock are NOT mounted here).
		// maxDiffPixels: 300 — absorbs cross-platform anti-aliasing drift between
		// local-Chromium (relay/devcloud, where baseline was captured 2026-04-26)
		// and CI-Chromium (GitHub Actions Linux). Observed drift ~154px / 14000px
		// (ratio 0.01) on the bf81d3e+9112d81 baseline run; 300 leaves headroom
		// for font-rendering shifts without hiding actual layout regressions.
		await expect(picker).toHaveScreenshot('skin-renderer-classic.png', {
			animations: 'disabled',
			maxDiffPixels: 300,
		})
	})
})
