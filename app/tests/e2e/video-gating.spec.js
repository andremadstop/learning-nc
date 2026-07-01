/**
 * Phase 162 Plan 04 — Video/Material gating a11y + gate E2E spec (VIDEO-05/07/08, DSGVO-04).
 *
 * Automates the machine-checkable half of the Task-3 human-verify checkpoint:
 *   - the WCAG player controls are keyboard-reachable with a visible focus ring,
 *   - Space toggles play/pause,
 *   - a WebVTT <track> is always present and the media never autoplays,
 *   - the document "Gelesen" button flips that item's gate status to complete.
 *
 * The screen-reader announcement, AA-contrast, no-third-party-request-before-consent
 * and Art.13 wording checks stay MANUAL (human-only) — see 162-04-PLAN.md how-to-verify.
 *
 * PRECONDITION (why this skips by default):
 *   The student registry-read path is currently instructor-gated server-side
 *   (CourseVideoController::index → 403 for students), so a student cannot yet load
 *   the "Pflichtinhalte" tab live. This spec therefore skips unless a seeded gated
 *   course id is provided AND (once the backend follow-up lands) the student can read
 *   it. Mirrors the critical-flows.spec.js env-gated skip pattern.
 *
 * Required env to run live:
 *   - E2E_VIDEO_COURSE_ID     numeric id of a course with video_gate_enabled + ≥1 nc-files
 *                             video and ≥1 document registry row
 *   - E2E_STUDENT_USER / E2E_STUDENT_PW   an enrolled student (else falls back to the
 *                             chromium project's stored session)
 *
 * Run:
 *   cd app && npx playwright test tests/e2e/video-gating.spec.js --project=chromium
 */
const { test, expect } = require('@playwright/test')

const COURSE_ID = process.env.E2E_VIDEO_COURSE_ID
const STUDENT_USER = process.env.E2E_STUDENT_USER
const STUDENT_PW = process.env.E2E_STUDENT_PW

/**
 * Optional fresh student login (mirrors a11y-v440.spec.js). When creds are absent
 * we rely on the chromium project's stored session (admin) — useful only if that
 * account is enrolled in the seeded course.
 */
async function maybeLoginStudent(page) {
	if (!STUDENT_USER || !STUDENT_PW) {
		return
	}
	const baseURL = process.env.E2E_BASE_URL || 'https://devcloud.andrestiebitz.de/apps/learning'
	const origin = new URL(baseURL).origin
	await page.goto(`${origin}/login`, { waitUntil: 'domcontentloaded' })
	const user = page.locator('input[name="user"], #user, input[autocomplete="username"]').first()
	const pw = page.locator('input[type="password"]').first()
	await user.waitFor({ state: 'visible', timeout: 60_000 })
	await user.click()
	await user.pressSequentially(STUDENT_USER, { delay: 30 })
	await pw.click()
	await pw.pressSequentially(STUDENT_PW, { delay: 30 })
	await pw.press('Enter')
	await page.waitForURL((url) => !String(url).includes('/login'), { timeout: 60_000 })
}

/**
 * Open the student "Pflichtinhalte" tab for the seeded course. Returns true when the
 * gated-content section is visible, false when the course exposes no student-readable
 * gate (e.g. the 403 backend blocker) so the test can skip rather than hard-fail.
 */
async function openPflichtinhalte(page) {
	await page.goto(`/apps/learning/#/course/${COURSE_ID}`, { waitUntil: 'domcontentloaded' })
	const tab = page.getByRole('button', { name: /Pflichtinhalte/i }).first()
	if (!(await tab.isVisible({ timeout: 15_000 }).catch(() => false))) {
		return false
	}
	await tab.click()
	return await page.locator('.student-videos-section').isVisible({ timeout: 10_000 }).catch(() => false)
}

test.describe('VIDEO-08 / VIDEO-07 — accessible player + document gate', () => {
	test.skip(!COURSE_ID, 'Needs E2E_VIDEO_COURSE_ID (a seeded gated course) + a student-readable registry path — see 162-04 blocker')

	test.beforeEach(async ({ page }) => {
		await maybeLoginStudent(page)
	})

	test('player exposes a WebVTT track and does not autoplay', async ({ page }) => {
		const ready = await openPflichtinhalte(page)
		test.skip(!ready, 'Pflichtinhalte tab not reachable (likely the instructor-gated read 403) — backend follow-up required')

		const media = page.locator('.video-player__media').first()
		await expect(media).toBeVisible()

		// Always-present subtitle track (empty WebVTT data-URI when no captions).
		await expect(media.locator('track[kind="subtitles"]')).toHaveCount(1)

		// No autoplay: the media element must not carry the autoplay attribute and
		// must be paused shortly after load.
		expect(await media.getAttribute('autoplay')).toBeNull()
		const pausedOnLoad = await media.evaluate((el) => el.paused)
		expect(pausedOnLoad, 'video must not autoplay on load').toBe(true)
	})

	test('play control is keyboard-reachable with a visible focus ring and Space toggles play', async ({ page }) => {
		const ready = await openPflichtinhalte(page)
		test.skip(!ready, 'Pflichtinhalte tab not reachable (instructor-gated read 403) — backend follow-up required')

		const playBtn = page.locator('.video-player__btn--play').first()
		await expect(playBtn).toBeVisible()

		// Tab from the section until the play button is the active element.
		await page.locator('.student-videos-section').focus().catch(() => {})
		let focused = false
		for (let i = 0; i < 40 && !focused; i++) {
			await page.keyboard.press('Tab')
			focused = await playBtn.evaluate((el) => el === document.activeElement).catch(() => false)
		}
		expect(focused, 'play button should be reachable via Tab').toBe(true)

		// Visible focus ring (outline width or box-shadow) on the focused control.
		const hasRing = await playBtn.evaluate((el) => {
			const cs = window.getComputedStyle(el)
			return (parseFloat(cs.outlineWidth) || 0) > 0 || (cs.boxShadow && cs.boxShadow !== 'none')
		})
		expect(hasRing, 'focused play control must show a visible focus ring').toBe(true)

		// Space toggles play.
		const media = page.locator('.video-player__media').first()
		await playBtn.press(' ')
		await expect.poll(async () => media.evaluate((el) => el.paused), { timeout: 5_000 }).toBe(false)
		await playBtn.press(' ')
		await expect.poll(async () => media.evaluate((el) => el.paused), { timeout: 5_000 }).toBe(true)
	})

	test('document "Gelesen" button flips the gate status to complete', async ({ page }) => {
		const ready = await openPflichtinhalte(page)
		test.skip(!ready, 'Pflichtinhalte tab not reachable (instructor-gated read 403) — backend follow-up required')

		// Find a document item (the one carrying a Gelesen button).
		const gelesenBtn = page.getByRole('button', { name: /^Gelesen$/ }).first()
		test.skip(!(await gelesenBtn.isVisible({ timeout: 5_000 }).catch(() => false)), 'No un-read document item seeded in this course')

		const item = page.locator('.video-item', { has: gelesenBtn })
		const status = item.locator('.video-item__status')
		await expect(status).toHaveClass(/video-item__status--pending/)

		await gelesenBtn.click()

		// Server records the read; the status badge flips to done and the button disables.
		await expect(status).toHaveClass(/video-item__status--done/, { timeout: 10_000 })
		await expect(item.getByRole('button', { name: /gelesen bestätigt/i })).toBeDisabled()
	})
})
