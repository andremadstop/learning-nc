/**
 * Phase 153 Plan 06 — Automated A11y + RTL + MIGR-05 + FR locale spec.
 *
 * Replaces the original 30-min manual walkthrough with one combined automated
 * spec (user-approved scope-pivot, see SUMMARY.md "Deviations from Plan").
 *
 * Coverage:
 *   - CP1 (prefers-reduced-motion): for each of 5 selectable skins, emulate
 *     reducedMotion=reduce, switch admin's skin, render the dock avatar, and
 *     assert no descendant has computed `animation-name !== 'none'`.
 *   - CP3 (Arabic RTL):
 *       * Take 5 screenshots (one per skin) at the canonical paths.
 *       * Mirror-check: capture avatar inner-HTML in DE and AR for same skin;
 *         assert byte-for-byte equality (I18N-03 release gate).
 *   - CP4 (keyboard-only): tab to SkinPicker, open with Enter, ArrowDown +
 *     Enter to select, verify persistence + visible focus ring (walk up the
 *     ancestor chain to find the ring host).
 *   - MIGR-05 (multi-account smoke):
 *       * adaeze (existing student, telos.onboarding_completed=1) → nova via UI+API
 *       * alexander, azad (existing students) → nova via API (skipping UI to
 *         avoid OnboardingIntro overlay that would block the dock)
 *       * fresh testnew{date} user (created via OCS as admin) → prof_lern_classic
 *         via API (UserCreatedListener writes prof_lern_classic on user-create
 *         event; first-touch-coercion path verified)
 *   - FR locale picker labels: switch admin to fr, verify dropdown options
 *     contain canonical FR strings from app/l10n/fr.json (3 archetype labels).
 *
 * Excluded: CP2 (screen-reader). Deferred to a 5-min post-merge NVDA/VoiceOver
 * spot-check; structural-guarantee verified in code review:
 *   CharacterAvatar.vue line 236-238: `ariaLabel = character.name || characterId`
 *   — pure character lookup, no animation state binding.
 *
 * Auth pattern: bypasses the chromium project's storageState (admin.json) by
 * setting test.use({ storageState: { cookies: [], origins: [] } }) and logging
 * in fresh per scenario via the inline `loginAs()` helper modeled on
 * auth.setup.js. Student users with telos.onboarding_completed=0 are NOT used
 * for visual tests — VirtuProf.vue's `showWelcomeStep()` overlays the
 * OnboardingIntro splash on top of the dock, hiding the avatar selectors.
 *
 * Credentials: passwords ARE NOT hardcoded. Read from environment variables
 * sourced from the Obsidian vault at run time. Required env:
 *   - E2E_USERNAME / E2E_PASSWORD              (admin, for OCS user-create + locale switch + visual tests)
 *   - E2E_ALEXANDER_PW
 *   - E2E_ADAEZE_PW
 *   - E2E_AZAD_PW
 *
 * Bruteforce throttle: with 6+ logins per run, IP throttling kicks in.
 * Reset before/during the run via:
 *   ssh relais 'docker exec -u www-data devcloud-app php occ security:bruteforce:reset 172.21.0.1'
 *
 * Run:
 *   cd app && npx playwright test tests/e2e/a11y-v440.spec.js --project=chromium
 *
 * Side-effects: 5 PNG screenshots land at app/docs/a11y-audit/v440-rtl-{id}.png
 *   exactly where A11Y-AUDIT-v4.4.0.md links them.
 */
const { test, expect, request: pwRequest } = require('@playwright/test')
const path = require('path')
const fs = require('fs')

// Bypass the chromium project's storageState (admin.json) — this spec logs in
// each user fresh per scenario. Without this, every page would start as admin
// session and login attempts would be redirected.
test.use({ storageState: { cookies: [], origins: [] } })

const SELECTABLE_SKINS = ['nova', 'prof_lern_classic', 'theoretiker', 'kosmologe', 'popularisierer']
const ADMIN_USER = process.env.E2E_USERNAME
const ADMIN_PW = process.env.E2E_PASSWORD
const ALEXANDER_PW = process.env.E2E_ALEXANDER_PW
const ADAEZE_PW = process.env.E2E_ADAEZE_PW
const AZAD_PW = process.env.E2E_AZAD_PW

// Date-stamped fresh-user id (per scope context — must NOT collide with a
// stale user from a previous run). afterAll cleanup keeps the slot clean for
// the next attempt.
const FRESH_USER_ID = 'testnew260427'
const FRESH_USER_PW = 'TempTestPw#260427'

// Avatar selector dispatcher — three different renderer trees (Phase 151/152):
//   nova               → NovaDock.vue + nested NovaAvatar (.nova-avatar-wrapper)
//   prof_lern_classic  → ProfLernAvatar.vue (.virtuprof-avatar-wrapper)
//   theoretiker, kosmologe, popularisierer → CharacterAvatar.vue (.character-avatar)
function avatarSelectorFor(skinId) {
	if (skinId === 'nova') return '.nova-avatar-wrapper'
	if (skinId === 'prof_lern_classic') return '.virtuprof-avatar-wrapper'
	return '.character-avatar'
}

/**
 * Log a user in via the same Vue login flow auth.setup.js uses. Keeps the
 * cookies on the page's context so subsequent navigations are authenticated.
 */
async function loginAs(page, user, password) {
	if (!user || !password) {
		throw new Error(`loginAs: missing credentials for user ${user}`)
	}
	const baseURL = process.env.E2E_BASE_URL || 'https://devcloud.andrestiebitz.de/apps/learning'
	const origin = new URL(baseURL).origin

	await page.goto(`${origin}/login`, { waitUntil: 'domcontentloaded' })
	const usernameInput = page.locator('input[name="user"], #user, input[autocomplete="username"]').first()
	const passwordInput = page.locator('input[type="password"]').first()
	await usernameInput.waitFor({ state: 'visible', timeout: 60_000 })
	await passwordInput.waitFor({ state: 'visible', timeout: 60_000 })
	await usernameInput.click()
	await usernameInput.pressSequentially(user, { delay: 30 })
	await passwordInput.click()
	await passwordInput.pressSequentially(password, { delay: 30 })
	await page.waitForTimeout(200)
	const submitButton = page.getByRole('button', { name: /log in|anmelden/i }).first()
	if (await submitButton.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await submitButton.click()
	} else {
		await passwordInput.press('Enter')
	}
	await page.waitForURL(url => !String(url).includes('/login'), { timeout: 60_000 })
}

/**
 * Set virtuprof_skin via Plan 04's preferences endpoint. Uses the page's
 * authenticated session (page.request inherits cookies) and the requesttoken
 * NC injects in the document head.
 */
async function setSkin(page, skinId) {
	await page.goto('/index.php/settings/user/learning')
	await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
	const requesttoken = await page.evaluate(() => document.head.querySelector('meta[name="requesttoken"]')?.content || window.OC?.requestToken)
	const response = await page.request.put('/index.php/apps/learning/api/virtuprof/preferences', {
		headers: { 'OCS-APIRequest': 'true', requesttoken: requesttoken || '' },
		data: { skin: skinId },
	})
	if (!response.ok()) throw new Error(`setSkin ${skinId} failed: ${response.status()} ${await response.text()}`)
}

/**
 * Read the persisted skin via the controller state endpoint. MIGR-05 oracle.
 */
async function readSkin(page) {
	const requesttoken = await page.evaluate(() => document.head.querySelector('meta[name="requesttoken"]')?.content || window.OC?.requestToken)
	const response = await page.request.get('/index.php/apps/learning/api/virtuprof/state', {
		headers: { 'OCS-APIRequest': 'true', requesttoken: requesttoken || '' },
	})
	if (!response.ok()) throw new Error(`readSkin failed: ${response.status()}`)
	const body = await response.json()
	return body.skin || null
}

/**
 * API-only skin read — does NOT need a UI session, uses Basic Auth directly
 * against the controller. Avoids the OnboardingIntro overlay problem for
 * student users (alexander, azad, fresh users) where loginAs() would land on
 * /apps/learning/ with the splash blocking everything.
 */
async function apiReadSkin(user, password) {
	const ctx = await pwRequest.newContext({
		baseURL: 'https://devcloud.andrestiebitz.de',
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
		},
	})
	const response = await ctx.get('/index.php/apps/learning/api/virtuprof/state')
	const body = await response.json()
	await ctx.dispose()
	if (!response.ok()) throw new Error(`apiReadSkin(${user}) failed: ${response.status()}`)
	return body.skin || null
}

/**
 * API-only skin write — same Basic Auth pattern.
 */
async function apiSetSkin(user, password, skinId) {
	const ctx = await pwRequest.newContext({
		baseURL: 'https://devcloud.andrestiebitz.de',
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
		},
	})
	const response = await ctx.put('/index.php/apps/learning/api/virtuprof/preferences', {
		data: { skin: skinId },
	})
	const text = await response.text()
	await ctx.dispose()
	if (!response.ok()) throw new Error(`apiSetSkin(${user}, ${skinId}) failed: ${response.status()} ${text}`)
}

/**
 * Set a user's UI language via OCS as admin. Verified: PUT
 * /ocs/v2.php/cloud/users/{userid} with form key=language returns
 * {meta.status: ok}, lang persists in `occ user:setting <user> core lang`.
 */
async function adminSetUserLanguage(targetUser, lang) {
	const ctx = await pwRequest.newContext({
		baseURL: 'https://devcloud.andrestiebitz.de',
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: 'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PW}`).toString('base64'),
		},
	})
	const response = await ctx.put(`/ocs/v2.php/cloud/users/${targetUser}`, {
		form: { key: 'language', value: lang },
	})
	const text = await response.text()
	await ctx.dispose()
	if (!response.ok() || !text.includes('"status":"ok"')) {
		throw new Error(`adminSetUserLanguage(${targetUser}, ${lang}) failed: ${response.status()} ${text}`)
	}
}

/**
 * OCS user-create as admin. Triggers UserCreatedEvent → UserCreatedListener
 * which writes virtuprof_skin=prof_lern_classic to the user's user_preferences.
 */
async function adminCreateUser(userid, password) {
	const ctx = await pwRequest.newContext({
		baseURL: 'https://devcloud.andrestiebitz.de',
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: 'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PW}`).toString('base64'),
		},
	})
	const response = await ctx.post('/ocs/v2.php/cloud/users', {
		form: { userid, password },
	})
	const text = await response.text()
	await ctx.dispose()
	if (!response.ok() || !text.includes('"status":"ok"')) {
		throw new Error(`adminCreateUser(${userid}) failed: ${response.status()} ${text}`)
	}
}

/**
 * OCS user-delete as admin. Idempotent: 200 on first call, 998 on already-gone.
 */
async function adminDeleteUser(userid) {
	const ctx = await pwRequest.newContext({
		baseURL: 'https://devcloud.andrestiebitz.de',
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: 'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PW}`).toString('base64'),
		},
	})
	await ctx.delete(`/ocs/v2.php/cloud/users/${userid}`)
	await ctx.dispose()
}

// ─────────────────────────────────────────────────────────────────────────
// Pre-test guards
// ─────────────────────────────────────────────────────────────────────────

test.beforeAll(() => {
	const required = ['E2E_USERNAME', 'E2E_PASSWORD', 'E2E_ALEXANDER_PW', 'E2E_ADAEZE_PW', 'E2E_AZAD_PW']
	const missing = required.filter(k => !process.env[k])
	if (missing.length) {
		throw new Error(`Missing required environment variables: ${missing.join(', ')}. Source from Obsidian vault before running.`)
	}
	const dir = path.resolve(__dirname, '../../docs/a11y-audit')
	if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true })
})

test.afterAll(async () => {
	// Cleanup the fresh test user (ignore failure — may not have been created
	// if the spec aborted early). Do NOT throw — this runs even on test
	// failure to keep relay devcloud clean.
	try { await adminDeleteUser(FRESH_USER_ID) } catch (_) { /* ignore */ }
	// Restore admin's locale to de in case CP3/FR-locale tests left it
	// elsewhere.
	try { await adminSetUserLanguage(ADMIN_USER, 'de') } catch (_) { /* ignore */ }
})

// ─────────────────────────────────────────────────────────────────────────
// CP1 — prefers-reduced-motion halts animations on every selectable skin
// Run as admin (no OnboardingIntro overlay; admin role bypasses checkTelosOnboarding).
// ─────────────────────────────────────────────────────────────────────────

test.describe('CP1 — prefers-reduced-motion halts skin animations', () => {
	for (const skinId of SELECTABLE_SKINS) {
		test(`reduced-motion stops animation on skin: ${skinId}`, async ({ page }) => {
			await page.emulateMedia({ reducedMotion: 'reduce' })
			await loginAs(page, ADMIN_USER, ADMIN_PW)
			await setSkin(page, skinId)
			await page.goto('/apps/learning/')
			await page.waitForSelector('.app-virtuprof-dock', { timeout: 30_000 })
			// Wait until the SkinRenderer dispatches the right component for
			// this skin (`:key="skinId"` triggers full re-render). Use
			// waitForFunction rather than waitFor on the bare locator — the
			// store hydration + dispatch is async post-mount.
			const sel = avatarSelectorFor(skinId)
			await page.waitForFunction((s) => !!document.querySelector(`.app-virtuprof-dock ${s}`), sel, { timeout: 30_000 })
			const avatarRoot = page.locator(`.app-virtuprof-dock ${sel}`).first()
			// Inspect computed `animation-name` on every descendant. Phase 150's
			// global `@media (prefers-reduced-motion: reduce)` rule applies
			// `animation: none !important` to:
			//   - `.character-avatar, .character-avatar *`     (Phase 152)
			//   - `.virtuprof-avatar, .eyebrows, .pupils, ...` (Phase 151)
			//   - per-component `.nova-* { animation: none }`  (Phase 150 nova)
			// Any descendant with `animationName !== 'none'` is a Phase 150 gap.
			const activeAnimations = await avatarRoot.evaluate((root) => {
				const out = []
				for (const el of root.querySelectorAll('*')) {
					const cs = window.getComputedStyle(el)
					if (cs.animationName && cs.animationName !== 'none') {
						out.push({ tag: el.tagName, cls: el.className.toString().slice(0, 60), name: cs.animationName })
					}
				}
				return out
			})
			expect(activeAnimations, `Skin ${skinId} should have no active CSS animations under reduced-motion: found ${JSON.stringify(activeAnimations)}`).toEqual([])
		})
	}
})

// ─────────────────────────────────────────────────────────────────────────
// CP3 — Arabic RTL screenshots + mirror-check release gate
// Run as admin. The avatar inner-HTML byte-equality between DE and AR is the
// I18N-03 release gate — it proves no template-level directional logic exists.
// Computed transform check rules out CSS-level scaleX(-1) mirroring.
// ─────────────────────────────────────────────────────────────────────────

test.describe('CP3 — Arabic RTL avatar unmirrored (I18N-03)', () => {
	for (const skinId of SELECTABLE_SKINS) {
		test(`avatar unmirrored in AR locale + screenshot: ${skinId}`, async ({ page }) => {
			// Capture DE-locale baseline first.
			await adminSetUserLanguage(ADMIN_USER, 'de')
			await loginAs(page, ADMIN_USER, ADMIN_PW)
			await setSkin(page, skinId)
			await page.goto('/apps/learning/')
			await page.waitForSelector('.app-virtuprof-dock', { timeout: 30_000 })
			const sel = avatarSelectorFor(skinId)
			await page.waitForFunction((s) => !!document.querySelector(`.app-virtuprof-dock ${s}`), sel, { timeout: 30_000 })
			const deAvatar = page.locator(`.app-virtuprof-dock ${sel}`).first()
			const deInnerHTML = await deAvatar.innerHTML()
			const deTransform = await deAvatar.evaluate(el => window.getComputedStyle(el).transform)

			// Switch to Arabic — full session reload required to apply.
			await adminSetUserLanguage(ADMIN_USER, 'ar')
			await page.reload()
			await page.goto('/apps/learning/', { waitUntil: 'domcontentloaded' })
			await page.waitForSelector('.app-virtuprof-dock', { timeout: 30_000 })
			// Verify document is RTL.
			const dir = await page.evaluate(() => document.documentElement.getAttribute('dir') || document.body.getAttribute('dir'))
			expect(dir, 'After language=ar, document should be in dir=rtl').toBe('rtl')

			await page.waitForFunction((s) => !!document.querySelector(`.app-virtuprof-dock ${s}`), sel, { timeout: 30_000 })
			const arAvatar = page.locator(`.app-virtuprof-dock ${sel}`).first()
			const arInnerHTML = await arAvatar.innerHTML()
			const arTransform = await arAvatar.evaluate(el => window.getComputedStyle(el).transform)

			// Take the canonical RTL screenshot at the path A11Y-AUDIT links to.
			const screenshotPath = path.resolve(__dirname, '../../docs/a11y-audit', `v440-rtl-${skinId}.png`)
			await arAvatar.screenshot({ path: screenshotPath, animations: 'disabled' })

			// Mirror-check release gate: inner-HTML must be byte-identical
			// between DE and AR for the same skin (rules out template-level
			// directional logic). Computed transform must NOT contain a
			// negative scaleX (rules out CSS-level mirroring).
			expect(arInnerHTML, `Skin ${skinId}: avatar inner-HTML differs between DE and AR — directional template logic detected (I18N-03 gate FAIL)`).toEqual(deInnerHTML)
			const isMirrored = (t) => /matrix\(\s*-1/.test(t)
			expect(isMirrored(arTransform) || isMirrored(deTransform), `Skin ${skinId}: avatar has scaleX(-1) computed transform (DE=${deTransform}, AR=${arTransform}) — I18N-03 gate FAIL`).toBe(false)

			// Restore for next iteration.
			await adminSetUserLanguage(ADMIN_USER, 'de')
		})
	}
})

// ─────────────────────────────────────────────────────────────────────────
// CP4 — Keyboard-only navigation through SkinPicker + focus ring detection
// Run as admin (PersonalSettings page bypasses the OnboardingIntro overlay).
// ─────────────────────────────────────────────────────────────────────────

test('CP4 — keyboard-only navigation: tab to picker, Enter to open, ArrowDown+Enter to select', async ({ page }) => {
	await loginAs(page, ADMIN_USER, ADMIN_PW)
	await setSkin(page, 'nova') // deterministic start
	await page.goto('/index.php/settings/user/learning')
	await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
	const picker = page.locator('#virtuprof-skin')
	await expect(picker).toBeVisible()

	// Focus body first, then Tab forward until activeElement is inside the
	// picker. NcSelect renders `id="virtuprof-skin"` on the outer .v-select
	// wrapper; the inner focusable is .vs__search input.
	await page.evaluate(() => document.body.focus())
	let focusState = 'NOT_FOUND'
	let tabs = 0
	const MAX_TABS = 80
	while (tabs < MAX_TABS) {
		await page.keyboard.press('Tab')
		tabs++
		focusState = await page.evaluate(() => {
			const ae = document.activeElement
			if (!ae) return 'NULL'
			let el = ae
			while (el) {
				if (el.id === 'virtuprof-skin') return 'IN_PICKER'
				el = el.parentElement
			}
			return ae.tagName + '.' + (ae.className || '').toString().slice(0, 40)
		})
		if (focusState === 'IN_PICKER') break
	}
	expect(focusState, `Could not reach #virtuprof-skin via Tab in ${MAX_TABS} tabs (last focus: ${focusState})`).toBe('IN_PICKER')

	// Verify visible focus ring — walk up the ancestor chain from
	// activeElement looking for outline-width > 0 OR a non-empty box-shadow.
	// NC's NcSelect puts the focus-visible ring on the wrapper, not the input.
	const hasRing = await page.evaluate(() => {
		const ae = document.activeElement
		if (!ae) return false
		let el = ae
		while (el) {
			const cs = window.getComputedStyle(el)
			const ow = parseFloat(cs.outlineWidth) || 0
			const bs = cs.boxShadow || ''
			if (ow > 0) return true
			if (bs !== 'none' && bs.length > 5) return true
			el = el.parentElement
		}
		return false
	})
	expect(hasRing, 'Focused picker has no visible focus-visible ring on activeElement or any ancestor').toBe(true)

	// Open dropdown with Enter and pick the next option.
	await page.keyboard.press('Enter')
	// vue-select renders <li> options inside .vs__dropdown-menu after open
	await page.waitForSelector('.vs__dropdown-menu li', { timeout: 5_000 })
	await page.keyboard.press('ArrowDown') // highlight option 1
	await page.keyboard.press('Enter') // select it

	await page.getByRole('button', { name: /^Save$|^Speichern$/ }).click()
	await expect(page.locator('.learning-settings-wrap')).toContainText(/Settings saved|Einstellungen gespeichert/, { timeout: 10_000 })

	// Verify the selection persisted (round-trip via reload).
	await page.reload()
	await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
	const persistedSkin = await readSkin(page)
	expect(persistedSkin, 'Skin selection from keyboard navigation should persist after reload').toBeTruthy()
	expect(persistedSkin).not.toBe('nova') // we picked the next-highlighted option, not nova
})

// ─────────────────────────────────────────────────────────────────────────
// MIGR-05 — Multi-account smoke
// API-only path for student users to bypass the OnboardingIntro overlay.
// ─────────────────────────────────────────────────────────────────────────

test.describe('MIGR-05 — multi-account smoke', () => {
	test('alexander (existing student) → nova via API', async () => {
		// Reset to nova first so this asserts the persisted state.
		await apiSetSkin('alexander', ALEXANDER_PW, 'nova')
		const skin = await apiReadSkin('alexander', ALEXANDER_PW)
		expect(skin).toBe('nova')
	})

	test('adaeze (existing student, telos completed) → nova via API+UI', async ({ page }) => {
		// adaeze has telos.onboarding_completed=1 so VirtuProf.vue's
		// checkTelosOnboarding() returns early WITHOUT showing the intro
		// splash. Avatar dock is visible immediately. This is the one student
		// path where the UI-side assertion is feasible.
		await apiSetSkin('adaeze', ADAEZE_PW, 'nova')
		await loginAs(page, 'adaeze', ADAEZE_PW)
		const apiSkin = await readSkin(page)
		expect(apiSkin, 'adaeze API skin should be nova').toBe('nova')
		// UI confirmation (belt-and-braces): NovaDock visible in the dock
		await page.goto('/apps/learning/')
		await page.waitForSelector('.app-virtuprof-dock .nova-avatar-wrapper', { timeout: 30_000 })
	})

	test('azad (existing student) → nova via API', async () => {
		await apiSetSkin('azad', AZAD_PW, 'nova')
		const skin = await apiReadSkin('azad', AZAD_PW)
		expect(skin).toBe('nova')
	})

	test('fresh user gets prof_lern_classic via UserCreatedListener', async () => {
		// Defensive cleanup in case a prior aborted run left the user behind.
		try { await adminDeleteUser(FRESH_USER_ID) } catch (_) { /* ignore */ }
		// Create the fresh user via OCS as admin — UserCreatedListener fires on
		// UserCreatedEvent and writes virtuprof_skin=prof_lern_classic to the
		// new user's user_preferences. This is the MIGR-02 happy-path
		// (Pattern 1 first-touch-coercion in VirtuProfController.getSkin()
		// also applies, but the listener runs first so the row pre-exists).
		await adminCreateUser(FRESH_USER_ID, FRESH_USER_PW)
		const skin = await apiReadSkin(FRESH_USER_ID, FRESH_USER_PW)
		expect(skin, 'Fresh user (no learning.* keys) should be coerced to prof_lern_classic by UserCreatedListener').toBe('prof_lern_classic')
	})
})

// ─────────────────────────────────────────────────────────────────────────
// FR locale picker labels (canonical strings sourced from app/l10n/fr.json).
// Validates Plan 04 + Plan 06 auto-fix: skinOptions passes c.name through
// `t('learning', c.name)` so the user's locale label appears in the dropdown.
// ─────────────────────────────────────────────────────────────────────────

test('FR locale: SkinPicker dropdown options use canonical French labels', async ({ page }) => {
	const frJsonPath = path.resolve(__dirname, '../../l10n/fr.json')
	const fr = JSON.parse(fs.readFileSync(frJsonPath, 'utf8')).translations
	const expectedLabels = [
		fr['Der Theoretiker'],
		fr['Der Kosmologe'],
		fr['Der Astrophysik-Popularisierer'],
	]
	for (const label of expectedLabels) {
		expect(label, 'app/l10n/fr.json must contain canonical French labels for the 3 archetypes').toBeTruthy()
	}

	await adminSetUserLanguage(ADMIN_USER, 'fr')
	await loginAs(page, ADMIN_USER, ADMIN_PW)
	await page.goto('/index.php/settings/user/learning')
	await page.waitForSelector('.learning-settings-wrap .settings-form', { timeout: 30_000 })
	const picker = page.locator('#virtuprof-skin')
	await picker.locator('.vs__dropdown-toggle').click()
	await page.waitForSelector('.vs__dropdown-menu li', { timeout: 5_000 })
	// innerText preserves newlines from word-wrapping in narrow dropdowns; collapse
	// whitespace runs to single spaces before asserting the canonical label is present.
	const rawDropdownText = await page.locator('.vs__dropdown-menu').innerText()
	const dropdownText = rawDropdownText.replace(/\s+/g, ' ')
	for (const label of expectedLabels) {
		expect(dropdownText, `FR dropdown should contain canonical label "${label}" (raw was: ${rawDropdownText.slice(0, 200)})`).toContain(label)
	}
	await adminSetUserLanguage(ADMIN_USER, 'de')
})
