// screenshots/shots.spec.cjs — the pictures of README, guide and App Store (GitHub #74).
//
// Runs against the instance of run.sh `up` (persona seed + seed.mjs). The order builds the state
// the pictures show: the administrator puts the organisation's logo into the library, the
// instructor shows the course setup, Sam confirms one required document and practises the example
// question, Alex confirms both, passes both exams and gets the certificate, an anonymous visitor
// opens its verification page, and the instructor's compliance report then has one certificate,
// one learner half way and one who has not started.
// Every picture is the browser window (lib.mjs: VIEWPORT, light theme, en-GB), saved as
// out/raw/<id>.png; optimise.mjs makes the repository copies. Nothing here checks the app — that is
// the persona gate's job — but a step that cannot find what it photographs fails, so a picture never
// silently shows the wrong screen.
const { test } = require('@playwright/test')
const fs = require('fs')
const path = require('path')

const OUT = path.dirname(process.env.PERSONA_STATE)
const RAW = path.join(OUT, 'raw')
const state = {}
for (const line of fs.readFileSync(process.env.PERSONA_STATE, 'utf8').split('\n')) {
	const m = line.match(/^([A-Z_]+)='(.*)'$/)
	if (m) state[m[1]] = m[2]
}
const seed = JSON.parse(fs.readFileSync(path.join(OUT, 'seed.json'), 'utf8'))
const shotsSeed = JSON.parse(fs.readFileSync(path.join(OUT, 'shots-seed.json'), 'utf8'))
const BASE = state.URL
const APP = `${BASE}/index.php/apps/learning`
const API = `${APP}/api`
const COURSE_TITLE = 'Annual safety training'
const PASSWORDS = { admin: state.ADMIN_PASS, instructor: state.INSTRUCTOR_PASS, learner1: state.LEARNER_PASS, learner2: state.LEARNER_PASS, learner3: state.LEARNER_PASS }
const LOGO_NAME = 'Northwind emblem'

let lib
let verifyUrl = null
test.describe.configure({ mode: 'serial' })
test.beforeAll(async () => { lib = await import('./lib.mjs') })

// ---------------------------------------------------------------- helpers
async function login(browser, user) {
	const context = await browser.newContext({
		locale: 'en-GB', timezoneId: 'Europe/London', colorScheme: 'light',
		viewport: lib.VIEWPORT, deviceScaleFactor: lib.DEVICE_SCALE_FACTOR,
	})
	await context.addInitScript(() => {
		try { localStorage.removeItem('learning:view-as-student') } catch { /* storage blocked */ }
	})
	const page = await context.newPage()
	page.on('dialog', (d) => (page.__acceptDialogs ? d.accept() : d.dismiss()).catch(() => {}))
	await page.goto(`${BASE}/index.php/login`)
	await page.fill('#user', user)
	await page.fill('#password', PASSWORDS[user])
	await page.click('button[type=submit]', { timeout: 60000 })
	await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 60000 })
	return { context, page }
}

async function settle(page, ms = 700) {
	await page.waitForLoadState('networkidle').catch(() => {})
	await page.waitForTimeout(ms)
}

/** The browser window as it is, without the mouse pointer's hover state or a blinking caret. */
async function shoot(page, id) {
	if (!lib.SHOTS.some((s) => s.id === id)) throw new Error(`unknown picture ${id}`)
	await page.mouse.move(0, 0)
	await page.evaluate(() => document.activeElement?.blur?.())
	await page.waitForTimeout(300)
	await page.screenshot({ path: path.join(RAW, `${id}.png`), caret: 'hide', animations: 'disabled' })
}

async function openTopTab(page, label) {
	await page.goto(APP)
	await settle(page)
	await page.locator('.main-nav [role=tab]').filter({ hasText: label }).first().click()
	await settle(page)
}

async function openMegaTab(page, label) {
	await openTopTab(page, 'Courses')
	await page.getByText(COURSE_TITLE, { exact: true }).first().click()
	await page.locator('.mega-tab-selector [role=tab]').first().waitFor({ timeout: 15000 })
	await page.locator('.mega-tab-selector [role=tab]').filter({ hasText: label }).first().click()
	await settle(page)
}

async function openLernraum(page, sub) {
	await openMegaTab(page, 'Learning space')
	await page.locator('.lernraum-subnav button').filter({ hasText: sub }).first().click()
	await settle(page)
}

/** Scrolls the app's own scroll container so that `locator` starts `offset` px below the top. */
async function scrollTo(page, locator, offset = 16) {
	await locator.first().waitFor({ timeout: 15000 })
	await locator.first().evaluate((el, offset) => {
		let box = el.parentElement
		while (box && !(box.scrollHeight > box.clientHeight && /(auto|scroll)/.test(getComputedStyle(box).overflowY))) box = box.parentElement
		const scroller = box || document.scrollingElement
		const top = el.getBoundingClientRect().top - (box ? box.getBoundingClientRect().top : 0)
		scroller.scrollTop += top - offset
	}, offset)
	await page.waitForTimeout(400)
}

async function call(user, method, p, body, extraHeaders = {}) {
	const res = await fetch(API + p, {
		method,
		headers: {
			Authorization: 'Basic ' + Buffer.from(`${user}:${PASSWORDS[user]}`).toString('base64'),
			'OCS-APIRequest': 'true', Accept: 'application/json', ...extraHeaders,
			...(body !== undefined && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
		},
		body: body === undefined ? undefined : (body instanceof FormData ? body : JSON.stringify(body)),
	})
	const text = await res.text()
	if (!res.ok) throw new Error(`${method} ${p} → ${res.status}: ${text.slice(0, 300)}`)
	try { return JSON.parse(text) } catch { return null }
}

/** Answers every question of a counting exam right (persona seed: question text → right answer). */
async function passExam(page) {
	page.__acceptDialogs = true
	await page.getByRole('button', { name: 'Start Exam' }).first().click()
	await page.locator('.question-text').first().waitFor({ timeout: 15000 })
	const resultsShown = () => page.locator('.results-screen').isVisible().catch(() => false)
	for (let n = 0; n < 30 && !(await resultsShown()); n++) {
		const q = page.locator('.question-text').first()
		if (!(await q.isVisible().catch(() => false))) { await page.waitForTimeout(500); continue }
		const text = (await q.textContent()).trim()
		const answer = seed.answers[text]
		if (!answer) throw new Error(`no seeded answer for "${text}"`)
		const labels = (await page.locator('.answer-btn').allTextContents()).map((s) => s.replace(/\s+/g, ' ').trim())
		const pick = labels.findIndex((l) => l === answer || l.endsWith(answer))
		if (pick === -1) throw new Error(`no answer button "${answer}"`)
		await page.locator('.answer-btn').nth(pick).click()
		await page.waitForTimeout(500)
		if (await resultsShown()) break
		const progress = (await page.locator('.progress-label').count()) ? await page.locator('.progress-label').textContent() : ''
		const m = progress.match(/(\d+)\s*\/\s*(\d+)/)
		if (m && m[1] === m[2]) { await page.locator('.end-exam-btn').click(); break }
		const now = (await q.isVisible().catch(() => false)) ? (await q.textContent()).trim() : ''
		if (now === text) await page.locator('.nav-dot').nth(Number(m?.[1] || n + 1)).click().catch(() => {})
	}
	await page.locator('.results-screen').waitFor({ timeout: 20000 })
	page.__acceptDialogs = false
	await settle(page)
	const banner = (await page.locator('.result-banner__status').textContent().catch(() => '')).trim()
	if (banner !== 'Passed') throw new Error(`exam result is "${banner}"`)
}

async function confirmRequired(page, onlyFirst = false) {
	const buttons = page.locator('[data-test="required-content-learner"] > li').getByRole('button', { name: 'Confirm I have read this' })
	const n = await buttons.count()
	if (!n) throw new Error('no "Confirm I have read this" button')
	for (let i = 0; i < (onlyFirst ? 1 : n); i++) {
		await buttons.first().click()
		await page.waitForTimeout(900)
	}
}

// ---------------------------------------------------------------- pictures
test('administrator: the organisation logo in the certificate logo library', async ({ browser }) => {
	test.setTimeout(5 * 60_000)
	// A plain emblem drawn by the browser (no image file in the repository, no real brand).
	const drawer = await browser.newPage({ viewport: { width: 400, height: 400 } })
	await drawer.setContent(`<html><body style="margin:0;background:transparent">
		<svg id="logo" xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 64 64">
			<rect x="2" y="2" width="60" height="60" rx="14" fill="#1a3a5c"/>
			<path d="M18 46V18h6l16 18V18h6v28h-6L24 28v18z" fill="#fff"/>
			<circle cx="49" cy="15" r="5" fill="#f2b632"/>
		</svg></body></html>`)
	const png = await drawer.locator('#logo').screenshot({ omitBackground: true })
	await drawer.close()
	const form = new FormData()
	form.append('name', LOGO_NAME)
	form.append('logo', new Blob([png], { type: 'image/png' }), 'northwind-emblem.png')
	const added = await call('admin', 'POST', '/settings/admin/cert-logos', form)
	if (!added?.added) throw new Error('logo upload: ' + JSON.stringify(added))
	await call('instructor', 'PUT', `/courses/${seed.courseId}/cert-layout/logo`, { logoId: added.added })
	// The persona seed's placeholder logo (bars) is no longer used: out of the library.
	await call('admin', 'DELETE', `/settings/admin/cert-logos/${seed.certificate.logoId}`)

	const { context, page } = await login(browser, 'admin')
	await page.goto(`${BASE}/index.php/settings/admin/learning`)
	await settle(page, 1200)
	await scrollTo(page, page.locator('[data-test="cert-logo-library"]'), 24)
	await shoot(page, 'admin-logos')
	await context.close()
})

test('instructor: course profile, materials, course rules, pool import', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const { context, page } = await login(browser, 'instructor')

	await openTopTab(page, 'Courses')
	await page.getByRole('button', { name: /create course/i }).first().click()
	const dialog = page.locator('[role=dialog]').filter({ visible: true }).first()
	await dialog.waitFor()
	await dialog.locator('input').first().fill('Annual safety training 2027')
	await dialog.locator('textarea').first().fill('Fire safety and data protection, once a year.').catch(() => {})
	await dialog.locator('label').filter({ hasText: 'Mandatory training with certificate' }).first().click()
	await page.waitForTimeout(400)
	await shoot(page, 'create-course')
	await page.keyboard.press('Escape')
	await page.waitForTimeout(400)

	await openLernraum(page, 'Learning materials')
	await scrollTo(page, page.locator('.material-table'), 200)
	await shoot(page, 'instructor-materials')

	await openMegaTab(page, 'Administration')
	await scrollTo(page, page.getByText('Certification', { exact: true }), 16)
	await shoot(page, 'course-rules')
	await scrollTo(page, page.locator('[data-test="cert-layout-org"]'), 16)
	await shoot(page, 'certificate-design')

	await openTopTab(page, 'Pools')
	await page.locator('.pool-list-import-pools').first().click()
	const importDialog = page.locator('[role=dialog]').filter({ visible: true }).first()
	await importDialog.waitFor()
	await importDialog.locator('input[type=file]').setInputFiles(path.join(__dirname, '..', '..', 'examples', 'app-tour', 'pool.json'))
	await page.waitForTimeout(600)
	await importDialog.getByRole('button', { name: 'Check file' }).click()
	await importDialog.getByText('Nothing has been saved yet.', { exact: false }).waitFor({ timeout: 20000 })
	await page.waitForTimeout(500)
	await shoot(page, 'pool-import')
	await importDialog.getByRole('button', { name: 'Cancel' }).click().catch(() => page.keyboard.press('Escape'))
	await context.close()
})

test('Sam: one required document confirmed, the example question practised', async ({ browser }) => {
	test.setTimeout(5 * 60_000)
	const { context, page } = await login(browser, 'learner2')
	await openLernraum(page, 'Learning materials')
	await confirmRequired(page, true)

	await openLernraum(page, 'Training')
	await page.locator('#app-content .pool-item').filter({ hasText: shotsSeed.tour.poolName }).first().click()
	await settle(page)
	await page.getByRole('button', { name: 'Start Training' }).first().click()
	await page.locator('.question-text').first().waitFor({ timeout: 15000 })
	const text = (await page.locator('.question-text').first().textContent()).trim()
	if (text !== shotsSeed.tour.question) throw new Error(`Training shows "${text}", not the example question`)
	const labels = (await page.locator('.answer-btn').allTextContents()).map((s) => s.replace(/\s+/g, ' ').trim())
	const pick = labels.findIndex((l) => l === shotsSeed.tour.wrongAnswer || l.endsWith(shotsSeed.tour.wrongAnswer))
	if (pick === -1) throw new Error(`no answer button "${shotsSeed.tour.wrongAnswer}"`)
	await page.locator('.answer-btn').nth(pick).click()
	await page.locator('.rich-feedback').first().waitFor({ timeout: 15000 })
	await page.locator('.qmedia').first().waitFor({ timeout: 15000 })
	// Step to the second line of the dialog, so that the picture shows a caption.
	const next = page.locator('.qmedia').getByRole('button', { name: /Next line/ })
	await next.click().catch(() => {})
	await page.waitForTimeout(500)
	await scrollTo(page, page.locator('.training-active .question-card .question-text'), 16)
	await shoot(page, 'question-feedback')
	await context.close()
})

test('Alex: required materials, both exams, certificate, completion', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const { context, page } = await login(browser, 'learner1')
	await openLernraum(page, 'Learning materials')
	await scrollTo(page, page.locator('[data-test="required-content-locked"]'), 16)
	await shoot(page, 'learner-materials')
	await confirmRequired(page)

	for (const [i, pool] of ['Fire safety', 'Data protection'].entries()) {
		await openLernraum(page, 'Exam')
		await page.locator('#app-content').getByText(pool, { exact: true }).first().click()
		await settle(page)
		if (i === 0) await shoot(page, 'exam-start')
		await passExam(page)
	}
	await page.getByRole('button', { name: 'Open certificate' }).click()
	await page.locator('.certificate-printable').waitFor({ timeout: 15000 })
	await settle(page, 1200)
	await shoot(page, 'certificate')
	verifyUrl = (await page.locator('.certificate-verify-url').first().textContent()).trim()
	if (!/\/verify\//.test(verifyUrl)) throw new Error(`no verification address on the certificate: "${verifyUrl}"`)
	await page.keyboard.press('Escape')

	await openMegaTab(page, 'Completion')
	await shoot(page, 'completion')
	await context.close()
})

test('anyone: the public verification page', async ({ browser }) => {
	if (!verifyUrl) throw new Error('no verification address from the certificate step')
	const context = await browser.newContext({
		locale: 'en-GB', timezoneId: 'Europe/London', colorScheme: 'light',
		viewport: lib.VIEWPORT, deviceScaleFactor: lib.DEVICE_SCALE_FACTOR,
	})
	const page = await context.newPage()
	// The certificate shows the instance's overwrite.cli.url, which is this instance.
	await page.goto(verifyUrl)
	await settle(page, 1000)
	await shoot(page, 'verify')
	await context.close()
})

test('instructor: compliance report and coverage', async ({ browser }) => {
	test.setTimeout(5 * 60_000)
	const { context, page } = await login(browser, 'instructor')
	await openMegaTab(page, 'Participants')
	const tabs = page.locator('#app-content [role=tablist]:not(.main-nav):not(.mega-tab-selector) [role=tab]')
	for (let i = 0; i < (await tabs.count()); i++) {
		await tabs.nth(i).click()
		await settle(page)
		if (await page.locator('.cert-report-section').isVisible().catch(() => false)) break
	}
	await scrollTo(page, page.locator('.cert-report-section'), 16)
	await shoot(page, 'compliance')
	await context.close()
})
