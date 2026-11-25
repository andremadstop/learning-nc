// persona.sweep.cjs — the Persona-Gate: click through Learning as each role would.
//
// C5: the learner first confirms the required documents (#75; the mandatory-training run gates the
// exam on them), the certificate carries the course's organisation name and library logo (#77/#78),
// the instructor reads and writes the Required column and the certificate design, the admin works
// the logo library and the technical-ticket recipients (#59, with a real notification), and axe
// checks the new controls hovered and focused too (axeStates, #76).
// Order matters: the learner passes both exams BEFORE any generic sweep (a sweep that touched an
// exam would burn attempts and start the cooldown); exam-start buttons are on the deny list.
// Results go to out/gamification-<off|on>/ (the mode of the running instance): findings.json,
// shots/, downloads/. report.mjs turns findings.json into report.md and stations.json there.
const { test, expect } = require('@playwright/test')
const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')
const { expectedReason, axeFindings, stationText, pickAnswerIndex, REFUSED_LOGO_STATION, REFUSED_BACKGROUND_STATION, ACHIEVEMENT_LINK_STATION, DEAD_LINK_STATION_PREFIX } = require('./lib.cjs')

const state = {}
for (const line of fs.readFileSync(process.env.PERSONA_STATE, 'utf8').split('\n')) {
	const m = line.match(/^([A-Z_]+)='(.*)'$/)
	if (m) state[m[1]] = m[2]
}
const OUT = path.join(__dirname, 'out')
const RUN = path.join(OUT, `gamification-${state.GAMIFICATION === 'on' ? 'on' : 'off'}`)
const DOWNLOADS = path.join(RUN, 'downloads')
const AUTH = path.join(OUT, 'auth')
// A filtered run (PERSONA_STATIONS) writes its own findings and screenshots and leaves the full
// run's alone: its screenshot numbers count from its own findings file, so in shots/ they would
// overwrite images that stations.json names.
const SHOTS = path.join(RUN, process.env.PERSONA_STATIONS ? 'shots-filtered' : 'shots')
const FINDINGS = path.join(RUN, process.env.PERSONA_STATIONS ? 'findings-filtered.json' : 'findings.json')
const seed = JSON.parse(fs.readFileSync(path.join(OUT, 'seed.json'), 'utf8'))
// GitHub #91: written by the learner's certificate station, read by the public verification test
const VERIFY_URL_FILE = path.join(RUN, 'verify-url.txt')
// axe-core from app/node_modules (devDependency, pinned). Injected with page.evaluate: that runs
// over the DevTools protocol, outside the page's CSP. A <script> tag would be blocked by
// Nextcloud's nonce CSP, and bypassCSP would hide the CSP violations this sweep has caught before.
const AXE_SOURCE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
const BASE = state.URL
const APP = `${BASE}/index.php/apps/learning`
const COURSE_TITLE = 'Annual safety training'
const PASSWORDS = { admin: state.ADMIN_PASS, instructor: state.INSTRUCTOR_PASS, learner1: state.LEARNER_PASS, learner2: state.LEARNER_PASS, learner3: state.LEARNER_PASS }

// Buttons the sweep never clicks: destructive, irreversible, or they use up an exam attempt.
// Fresh-eyes C5: "deselect" — NcSelect labels a chip's x "Deselect <name>"; the sweep clicked it on
// the admin settings and then "Save recipients", so every later station showed nobody notified.
// GitHub #82: "activat" — the instructor's course menu offers "Activate" on the archived seed course;
// clicking it would turn the dead-link station's archived course back into an active one.
const DENY = /activat|make (instructor|student|learner)|promot|demot|delet|remov|deselect|abwählen|revok|withdraw|unenrol|unenroll|leave|archiv|merge|reset|log ?out|sign out|generat|rotat|start exam|start the exam|practice exam|begin|abort|end exam|uninstall|discard|clear|close course|close panel|minimi[sz]e panel|import|upload|löschen|entfern|widerruf|austragen|zurücksetzen|new certification cycle|neuen zertifizierungszyklus/i
const MAX_BUTTONS_PER_STATION = 30
// Nextcloud core noise in a bare container (no user_status backend data); not the app's.
const CORE_NOISE = /user_status|Failed to load user status/

// German in an English UI: umlauts / ß, or common German words.
const GERMAN = /[äöüÄÖÜß]|\b(und|oder|nicht|für|mit|eine?|einen|werden|wird|sind|ist|Kurs|Kurse|Kursbereiche|Prüfung|Fragen?|Einstellungen|Speichern|Abbrechen|Lernende[nr]?|Zertifikat|Bereiche|Werkzeuge|Schließen|Hinzufügen|keine?|alle|noch|jetzt|bitte|Teilnehmer|Gruppe|Übersicht|zurück|Bericht|Lernraum|Verwaltung|Kommunikation|Wettbewerb|Simulatoren|herunterladen)\b/

const isAppUrl = (u) => u.includes('/apps/learning/') || u.includes('/ocs/v2.php/apps/learning')
const shortUrl = (u) => u.replace(BASE, '').replace(/\?.*$/, '')
const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60)

// ---------------------------------------------------------------- findings
let allFindings = []
try { allFindings = JSON.parse(fs.readFileSync(FINDINGS, 'utf8')) } catch { /* first test */ }
function flush() { fs.writeFileSync(FINDINGS, JSON.stringify(allFindings, null, 2)) }

class Recorder {
	constructor(persona, { studentView = false } = {}) {
		this.persona = persona
		const user = persona.replace(/-.*/, '')
		// GitHub #82: the ids a dead-link station may get a 404 for; the course with nobody but its
		// instructor is dead for everyone else
		const deadLinks = seed.deadLinks
			? { ...seed.deadLinks, noAccessCourseIds: user === seed.poolOwner ? [] : [seed.deadLinks.privateCourseId] }
			: null
		this.ctx = { user, studentView, poolOwner: seed.poolOwner, deadLinks }
		this.current = null
		this.requests = []
		this.toastsSeen = new Set()
	}

	attach(page) {
		this.page = page
		page.on('response', (r) => {
			const u = r.url()
			const req = r.request()
			// Outside the app only a rate limit counts: Nextcloud's own limits (core OCS, login,
			// notifications) hit the same learners while they click through Learning.
			if (!isAppUrl(u)) {
				if (r.status() === 429) this.add('http', `${req.method()} 429 ${shortUrl(u)} (outside the app)`)
				return
			}
			this.requests.push({ t: Date.now(), method: req.method(), status: r.status(), url: shortUrl(u) })
			if (r.status() >= 400) this.add('http', `${req.method()} ${r.status()} ${shortUrl(u)}`)
		})
		page.on('console', (m) => {
			if (m.type() !== 'error') return
			const text = m.text()
			if (CORE_NOISE.test(text)) return
			// "Failed to load resource" for an app URL is already recorded as an http finding.
			if (/^Failed to load resource/.test(text) && isAppUrl(m.location()?.url || '')) return
			if (/^Failed to load resource/.test(text) && !isAppUrl(m.location()?.url || '')) return
			this.add('console', text.replace(/\s+/g, ' ').slice(0, 300))
		})
		page.on('pageerror', (e) => this.add('pageerror', String(e.message || e).slice(0, 300)))
	}

	begin(name) {
		this.current = { persona: this.persona, station: name, url: '', screenshot: '', findings: [] }
		allFindings.push(this.current)
	}

	add(kind, detail) {
		if (!this.current) this.begin('(before first station)')
		// The app working as intended: kept, but in its own list (report: "Expected, not counted").
		const reason = expectedReason({ kind, detail }, { ...this.ctx, station: this.current.station })
		const list = reason ? (this.current.expected ||= []) : this.current.findings
		if (list.some((f) => f.kind === kind && f.detail === detail)) return
		list.push(reason ? { kind, detail, reason } : { kind, detail })
	}
}

// ---------------------------------------------------------------- page checks
async function settle(page, ms = 600) {
	await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {})
	await page.waitForTimeout(ms)
}

async function checkPage(page, rec) {
	const res = await page.evaluate((germanSrc) => {
		const german = new RegExp(germanSrc)
		const visible = (el) => {
			if (!el || !el.getClientRects().length) return false
			const s = getComputedStyle(el)
			return s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05
		}
		// Text the app marks as another language than the page (lang="de" on the German-only
		// campaign stories, GitHub #52, WCAG 3.1.2) is declared German on purpose: not an
		// untranslated string. Unmarked German still counts.
		const pageLang = String(document.documentElement.lang || '').toLowerCase().split('-')[0]
		const markedOtherLang = (el) => {
			const holder = el.closest('[lang]')
			if (!holder || holder === document.documentElement) return false
			return String(holder.getAttribute('lang') || '').toLowerCase().split('-')[0] !== pageLang
		}
		const hits = new Set()
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
		let node
		while ((node = walker.nextNode())) {
			const text = node.textContent.replace(/\s+/g, ' ').trim()
			if (!text || !german.test(text)) continue
			const el = node.parentElement
			if (!el || el.closest('script,style,noscript,textarea') || !visible(el) || markedOtherLang(el)) continue
			hits.add('text: ' + text.slice(0, 140))
		}
		for (const el of document.querySelectorAll('[placeholder],[title],[aria-label]')) {
			if (!visible(el) || markedOtherLang(el)) continue
			for (const attr of ['placeholder', 'title', 'aria-label']) {
				const v = el.getAttribute(attr)
				if (v && german.test(v)) hits.add(`${attr}: ${v.slice(0, 140)}`)
			}
		}
		// Main area: text outside tab bars and the VirtuProf panel.
		const main = document.querySelector('#app-content') || document.querySelector('#content') || document.body
		const clone = main.cloneNode(true)
		const fullscreenBot = /\/virtuprof$/.test(location.pathname)
		for (const n of clone.querySelectorAll(fullscreenBot ? '[role=tablist], .main-nav' : '[role=tablist], .app-virtuprof-dock, .virtuprof-container, .main-nav')) n.remove()
		const mainText = clone.textContent.replace(/\s+/g, ' ').trim()
		// What a reader sees (fresh-eyes review): innerText of the live area, tabs and VirtuProf
		// included — innerText of the detached clone above has no layout and would include hidden text.
		const visibleText = main.innerText || ''
		return { german: [...hits].slice(0, 25), mainTextLength: mainText.length, mainText: mainText.slice(0, 80), visibleText }
	}, GERMAN.source)
	for (const h of res.german) rec.add('german', h)
	if (res.mainTextLength < 40) rec.add('empty', `main area has only ${res.mainTextLength} characters: "${res.mainText}"`)
	rec.current.text = stationText(res.visibleText)
	for (const f of await runAxe(page)) rec.add(f.kind, f.detail)
}

/**
 * axe-core on the app's area: WCAG 2.x A/AA rules, violations plus the 1:1 contrast nodes axe only
 * calls incomplete (see lib.cjs: axeFindings, equalRatioContrast).
 */
async function runAxe(page, rootSelector = null) {
	const hasAxe = await page.evaluate(() => typeof window.axe === 'object').catch(() => false)
	if (!hasAxe) await page.evaluate(AXE_SOURCE)
	const results = await page.evaluate(async (rootSelector) => {
		// rootSelector: a dialog NcModal renders outside #app-content (C9b: archive, add pool)
		const shown = rootSelector ? [...document.querySelectorAll(rootSelector)].find((el) => el.getClientRects().length) : null
		const root = shown || document.querySelector('#app-content') || document.querySelector('#content') || document.body
		const res = await window.axe.run(root, {
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
			// incomplete too: axe files 1:1 text (same colour as its background) there, not as a
			// violation — GitHub #76 (lib.cjs: equalRatioContrast)
			resultTypes: ['violations', 'incomplete'],
		})
		const slim = (list) => list.map((v) => ({
			id: v.id, impact: v.impact, help: v.help,
			nodes: v.nodes.map((n) => ({ impact: n.impact, target: n.target, any: n.any.map((c) => ({ data: c.data })) })),
		}))
		return { violations: slim(res.violations), incomplete: slim(res.incomplete.filter((v) => v.id === 'color-contrast')) }
	}, rootSelector)
	return axeFindings(results.violations, { incomplete: results.incomplete })
}

/**
 * axe only measures the state it sees (GitHub #76). For one control: hover it and check contrast,
 * then focus it from the keyboard (a key press first, so :focus-visible applies) and check again.
 * The state at rest is the station's own page check. Only contrast findings are recorded here.
 */
async function axeStates(page, rec, locator, what, focusLocator = null) {
	const el = locator.first()
	if (!(await el.isVisible().catch(() => false))) {
		rec.add('station-error', `hover/focus check: "${what}" not visible`)
		return
	}
	await el.scrollIntoViewIfNeeded().catch(() => {})
	await el.hover().catch(() => {})
	await page.waitForTimeout(300)
	for (const f of await runAxe(page)) if (f.detail.startsWith('color-contrast')) rec.add(f.kind, `hovered ${what}: ${f.detail}`)
	await page.mouse.move(0, 0)
	await page.keyboard.press('Shift')
	await (focusLocator || el).first().focus().catch(() => {})
	await page.waitForTimeout(200)
	for (const f of await runAxe(page)) if (f.detail.startsWith('color-contrast')) rec.add(f.kind, `focused ${what}: ${f.detail}`)
	await page.evaluate(() => document.activeElement?.blur?.())
}

async function checkToasts(page, rec, since, action) {
	const toasts = await page.evaluate(() => [...document.querySelectorAll('.toastify')].map((t) => ({
		text: t.textContent.replace(/\s+/g, ' ').trim(),
		success: t.classList.contains('toast-success'),
		error: t.classList.contains('toast-error'),
	})))
	for (const t of toasts) {
		const key = `${t.text}|${t.success}|${t.error}`
		if (rec.toastsSeen.has(key)) continue
		rec.toastsSeen.add(key)
		// Every toast is recorded, not only the suspicious ones: a detector that never sees a toast
		// would otherwise look like an app without problems.
		;(rec.current.toasts ||= []).push(`${t.success ? 'success' : t.error ? 'error' : 'other'}: ${t.text.slice(0, 100)}`)
		const reqs = rec.requests.filter((r) => r.t >= since - 300)
		if (t.error) rec.add('error-toast', `after "${action}": ${t.text.slice(0, 160)}`)
		if (t.success) {
			const writes = reqs.filter((r) => r.method !== 'GET' && r.status < 400)
			const failed = reqs.filter((r) => r.status >= 400)
			if (failed.length) rec.add('toast-despite-error', `after "${action}": "${t.text.slice(0, 120)}" while ${failed.map((r) => `${r.method} ${r.status} ${r.url}`).join(', ')}`)
			else if (!writes.length) rec.add('toast-without-write', `after "${action}": "${t.text.slice(0, 120)}" — no successful non-GET app request (${reqs.map((r) => `${r.method} ${r.url}`).join(', ') || 'no app request at all'})`)
		}
	}
	// Toasts stay for seconds; forget them once gone so that a repeat is seen again.
	if (!toasts.length) rec.toastsSeen.clear()
}

async function closeOverlays(page) {
	for (let i = 0; i < 2; i++) {
		const modal = await page.locator('.modal-mask, [role=dialog]').filter({ visible: true }).count().catch(() => 0)
		if (!modal) break
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(250)
	}
	const stillOpen = page.locator('.modal-container__close, button[aria-label="Close"]').filter({ visible: true }).first()
	if (await stillOpen.count().catch(() => 0)) await stillOpen.click({ timeout: 1500 }).catch(() => {})
}

async function shoot(page, rec, name) {
	const file = `${slug(rec.persona)}--${String(allFindings.length).padStart(3, '0')}-${slug(name)}.png`
	await page.screenshot({ path: path.join(SHOTS, file) }).catch(() => {})
	return file
}

/** One station: run fn, then the page checks, a screenshot, and save the findings. */
const ONLY = process.env.PERSONA_STATIONS ? new RegExp(process.env.PERSONA_STATIONS) : null
const DEBUG = process.env.PERSONA_DEBUG === '1'
let skipClicks = false
async function station(page, rec, name, fn) {
	if (ONLY && !ONLY.test(name)) {
		// Filtered out: still navigate (later stations build on it), but no clicks, no record.
		skipClicks = true
		rec.current = { persona: rec.persona, station: '(filtered)', findings: [] } // not saved
		try { await fn() } catch { /* not recorded */ }
		skipClicks = false
		return
	}
	rec.begin(name)
	try {
		await fn()
		await settle(page)
	} catch (e) {
		rec.add('station-error', String(e.message || e).split('\n')[0].slice(0, 300))
	}
	rec.current.url = shortUrl(page.url())
	await checkPage(page, rec).catch((e) => rec.add('station-error', 'page check failed: ' + e.message))
	rec.current.screenshot = await shoot(page, rec, name)
	flush()
}

/** Click every visible, non-destructive button in the main area, one after the other. */
async function clickAll(page, rec, reenter) {
	if (skipClicks) return
	const collect = () => page.evaluate((denySrc) => {
		const deny = new RegExp(denySrc, 'i')
		const root = document.querySelector('#app-content') || document.body
		const out = []
		for (const el of root.querySelectorAll('button, [role=button], a.button')) {
			if (!el.getClientRects().length || el.disabled || el.getAttribute('aria-disabled') === 'true') continue
			if (el.closest('[role=tablist]') || el.getAttribute('role') === 'tab') continue
			if (el.closest('.app-virtuprof-dock, .virtuprof-container')) continue
			const label = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim()
			if (!label || deny.test(label)) continue
			out.push(label)
		}
		return out
	}, DENY.source)
	const labels = (await collect()).slice(0, MAX_BUTTONS_PER_STATION)
	if (DEBUG) console.log(`[clickAll] ${rec.current.station}: ${JSON.stringify(labels)}`)
	const seen = new Map()
	const home = page.url()
	for (const label of labels) {
		const nth = seen.get(label) || 0
		seen.set(label, nth + 1)
		if (page.url() !== home) await reenter()
		const mark = () => page.evaluate(({ label, nth, denySrc }) => {
			document.querySelectorAll('[data-persona-target]').forEach((e) => e.removeAttribute('data-persona-target'))
			const deny = new RegExp(denySrc, 'i')
			const root = document.querySelector('#app-content') || document.body
			let i = 0
			for (const el of root.querySelectorAll('button, [role=button], a.button')) {
				if (!el.getClientRects().length || el.disabled) continue
				if (el.closest('[role=tablist]') || el.getAttribute('role') === 'tab') continue
				if (el.closest('.app-virtuprof-dock, .virtuprof-container')) continue
				const l = (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim()
				if (l !== label || deny.test(l)) continue
				if (i++ === nth) { el.setAttribute('data-persona-target', '1'); return true }
			}
			return false
		}, { label, nth, denySrc: DENY.source })
		let found = await mark()
		// A click can change the view without changing the URL ("← Back to courses"): re-enter once.
		if (!found) { await reenter(); found = await mark() }
		if (DEBUG) console.log(`[clickAll] ${rec.current.station}: "${label}"#${nth} found=${found} url=${shortUrl(page.url())}`)
		if (!found) continue
		const since = Date.now()
		await page.locator('[data-persona-target]').first().click({ timeout: 3000 }).catch(() => {})
		await page.waitForTimeout(700)
		await checkToasts(page, rec, since, label)
		await closeOverlays(page)
		rec.current.clicked = (rec.current.clicked || 0) + 1
	}
	// Fresh-eyes C4: the screenshot is taken after this; without re-entering it showed wherever the
	// last click led (the pool list after "← Back to pools", Manage instead of Exam), so stations
	// looked as if their tab did not switch. Re-enter after any click, not only on a URL change.
	if (page.url() !== home || rec.current.clicked) await reenter()
}

// ---------------------------------------------------------------- navigation
async function login(browser, persona, { studentView = false, contextOptions = {} } = {}) {
	const user = persona.replace(/-.*/, '')
	const context = await browser.newContext({ acceptDownloads: true, locale: 'en-GB', timezoneId: 'Europe/London', viewport: { width: 1400, height: 900 }, ...contextOptions })
	await context.addInitScript(({ studentView }) => {
		try {
			if (studentView) localStorage.setItem('learning:view-as-student', 'yes')
			else localStorage.removeItem('learning:view-as-student')
		} catch { /* storage blocked */ }
		// The print dialog would block a headless run; record the call instead.
		window.print = () => { window.__personaPrinted = (window.__personaPrinted || 0) + 1 }
	}, { studentView })
	const page = await context.newPage()
	page.on('dialog', (d) => (page.__acceptDialogs ? d.accept() : d.dismiss()).catch(() => {}))
	await page.goto(`${BASE}/index.php/login`)
	await page.fill('#user', user)
	await page.fill('#password', PASSWORDS[user])
	// A user's first login copies the skeleton files and fills the app caches; on a fresh instance
	// that can take longer than the 10 s action timeout, which also covers the navigation the
	// click starts. The login gets 60 s, the stations keep their 10 s.
	await page.click('button[type=submit]', { timeout: 60000 })
	await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 60000 })
	return { context, page }
}

async function openApp(page) {
	await page.goto(APP)
	await settle(page)
}

const mainNav = (page) => page.locator('.main-nav [role=tab]')

async function openTopTab(page, label) {
	await openApp(page)
	await mainNav(page).filter({ hasText: label }).first().click()
	await settle(page)
}

async function openCourse(page) {
	await openTopTab(page, 'Courses')
	await page.getByText(COURSE_TITLE, { exact: true }).first().click()
	await page.locator('.mega-tab-selector [role=tab]').first().waitFor({ timeout: 15000 })
	await settle(page)
}

async function openMegaTab(page, label) {
	await openCourse(page)
	await page.locator('.mega-tab-selector [role=tab]').filter({ hasText: label }).first().click()
	await settle(page)
}

/** Tab bars inside the content, except the app and course bars and simulator internals. */
async function subTabLabels(page, { skip = '' } = {}) {
	return page.evaluate((skip) => {
		const out = []
		for (const list of document.querySelectorAll('#app-content [role=tablist]')) {
			if (list.matches('.main-nav, .mega-tab-selector, .sim-nav, .sim-tool__tabs') || !list.getClientRects().length) continue
			if (skip && list.matches(skip)) continue
			if (list.closest('.sim-tool, [class*=simulator]')) continue
			const cls = [...list.classList].map((c) => '.' + c).join('')
			for (const el of list.querySelectorAll('button, [role=tab]')) {
				const label = el.textContent.replace(/\s+/g, ' ').trim()
				if (label && el.getClientRects().length) out.push({ list: cls || '[role=tablist]', label })
			}
		}
		return out
	}, skip)
}

async function clickSubTab(page, sub) {
	await page.locator(`${sub.list}`).first().locator('button, [role=tab]').filter({ hasText: sub.label }).first().click()
	await settle(page)
}

async function download(page, trigger, name) {
	const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), trigger()])
	const file = path.join(DOWNLOADS, `${name}-${dl.suggestedFilename()}`)
	await dl.saveAs(file)
	return { file, size: fs.statSync(file).size, suggested: dl.suggestedFilename(), head: fs.readFileSync(file).subarray(0, 400) }
}

/** Poll check() until it is true; false after ms. */
async function waitUntil(check, ms = 6000, step = 250) {
	const end = Date.now() + ms
	for (;;) {
		if (await check().catch(() => false)) return true
		if (Date.now() > end) return false
		await new Promise((resolve) => setTimeout(resolve, step))
	}
}

/** Whether an NcButton is the primary one (GitHub #92: one clear next action). */
const isPrimary = (locator) => locator.first().evaluate((el) => el.classList.contains('button-vue--primary')).catch(() => false)

/**
 * The exam set up on the page (after "Start Exam"): every question answered right, or every one
 * wrong (GitHub #87: attempts used up). Returns the result banner and the score line.
 */
async function takeExam(page, { wrong = false } = {}) {
	page.__acceptDialogs = true
	try {
		await page.getByRole('button', { name: 'Start Exam' }).first().click()
		await page.locator('.question-text').first().waitFor({ timeout: 15000 })
		const resultsShown = () => page.locator('.results-screen').isVisible().catch(() => false)
		for (let n = 0; n < 30; n++) {
			if (await resultsShown()) break
			const q = page.locator('.question-text').first()
			if (!(await q.isVisible().catch(() => false))) { await page.waitForTimeout(500); continue }
			const text = (await q.textContent()).trim()
			const answer = seed.answers[text]
			if (!answer) throw new Error(`no seeded answer for question "${text}"`)
			const buttons = await page.locator('.answer-btn').allTextContents()
			const right = pickAnswerIndex(buttons, answer)
			if (right === -1) throw new Error(`no answer button "${answer}" for question "${text}"`)
			const pick = wrong ? buttons.findIndex((_, i) => i !== right) : right
			await page.locator('.answer-btn').nth(pick).click()
			await page.waitForTimeout(500)
			if (await resultsShown()) break
			const progress = (await page.locator('.progress-label').count()) ? await page.locator('.progress-label').textContent() : ''
			const m = progress.match(/(\d+)\s*\/\s*(\d+)/)
			if (m && m[1] === m[2]) {
				await page.locator('.end-exam-btn').click() // confirm() is accepted
				break
			}
			const now = (await q.isVisible().catch(() => false)) ? (await q.textContent()).trim() : ''
			if (now === text) await page.locator('.nav-dot').nth(Number(m?.[1] || n + 1)).click().catch(() => {})
		}
		await page.locator('.results-screen').waitFor({ timeout: 20000 })
		await settle(page)
	} finally {
		page.__acceptDialogs = false
	}
	const banner = (await page.locator('.result-banner__status').textContent().catch(() => '')).trim()
	const score = (await page.locator('.results-screen .stat-value').first().textContent().catch(() => '')).trim()
	return { banner, score }
}

// GitHub #86: the learner's required documents on Learning materials. With the course option
// "confirm only after opening" (on in the mandatory-training profile) every confirm button waits
// for "Open document", says why (hint, aria-describedby), and the server refuses a confirmation of
// an unopened file too. Without the option (the IT course) nothing waits, as before.
const requiredItems = (page) => page.locator('[data-test="required-content-learner"] > li')
const itemConfirm = (item) => item.locator('[data-test^="document-confirm-"]').first()
const itemTitle = async (item) => (await item.locator('.video-item__title').first().textContent().catch(() => '')).trim()

async function checkConfirmLock(page, rec, openFirst, who) {
	const items = requiredItems(page)
	const n = await items.count()
	if (!n) rec.add('materials', `${who}: no required documents on Learning materials`)
	for (let i = 0; i < n; i++) {
		const item = items.nth(i)
		const btn = itemConfirm(item)
		const title = await itemTitle(item)
		if ((await btn.textContent().catch(() => '')).trim() === 'Confirmed as read') {
			rec.add('materials', `${who}: "${title}" is already confirmed before it was opened in this cycle`)
			continue
		}
		const locked = await btn.isDisabled().catch(() => null)
		const hint = item.locator('p[data-test^="document-open-first-"]')
		const hinted = await hint.isVisible().catch(() => false)
		if (openFirst) {
			if (!locked) rec.add('materials', `${who}: "${title}" can be confirmed before it was opened (#86)`)
			if (!hinted) rec.add('materials', `${who}: "${title}" is locked without the hint to open it first (#86)`)
			else {
				const id = await hint.getAttribute('id')
				const describedBy = await btn.getAttribute('aria-describedby')
				if (!id || describedBy !== id) rec.add('a11y', `${who}: the locked confirm button of "${title}" is described by "${describedBy}", not by the hint "${id}" (#86)`)
			}
		} else {
			if (locked) rec.add('materials', `${who}: "${title}" cannot be confirmed although the course does not ask for opening first`)
			if (hinted) rec.add('materials', `${who}: "${title}" shows "Open the document first" although the course does not ask for it`)
		}
	}
	return n
}

/** GitHub #86: the server refuses a confirmation of an unopened file (409 document_not_opened). */
async function probeConfirmRefused(page, rec, user, who) {
	const items = requiredItems(page)
	const ids = []
	for (let i = 0; i < await items.count(); i++) {
		const dt = await itemConfirm(items.nth(i)).getAttribute('data-test').catch(() => null)
		const id = Number(String(dt || '').replace('document-confirm-', ''))
		if (id) ids.push(id)
	}
	if (!ids.length) { rec.add('station-error', `${who}: no content id to probe the server with`); return }
	// Node fetch with the learner's password, not the page: the expected 409 is not an app request
	// of the station, and the page must not learn of it.
	const res = await fetch(`${APP}/api/video-progress/document-read`, {
		method: 'POST',
		headers: { Authorization: 'Basic ' + Buffer.from(`${user}:${PASSWORDS[user]}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
		body: JSON.stringify({ contentId: ids[0] }),
	})
	const body = await res.json().catch(() => null)
	if (res.status !== 409 || body?.code !== 'document_not_opened') rec.add('materials', `${who}: the server answers ${res.status} ${JSON.stringify(body).slice(0, 120)} to a confirmation of an unopened document (expected 409 document_not_opened, #86)`)
	;(rec.current.notes ||= []).push(`server, confirm before open: ${res.status} ${body?.code || ''}`)
}

/**
 * Open every unconfirmed required document as a learner would, then confirm it. A PDF opens in a
 * new tab; a Markdown document opens in Learning's reader (GitHub #110), which must show the
 * rendered text and close again.
 */
async function openAndConfirmAll(page, rec, who) {
	const items = requiredItems(page)
	const n = await items.count()
	for (let i = 0; i < n; i++) {
		const item = items.nth(i)
		const btn = itemConfirm(item)
		const title = await itemTitle(item)
		if ((await btn.textContent().catch(() => '')).trim() === 'Confirmed as read') continue
		const markdown = /\.(md|markdown)$/i.test(title)
		const opened = markdown ? Promise.resolve(null) : page.context().waitForEvent('page', { timeout: 10000 }).catch(() => null)
		await item.locator('a[data-test^="document-open-"]').first().click()
		if (markdown) {
			const body = page.locator('[data-test="material-reader-body"]')
			await body.waitFor({ timeout: 10000 }).catch(() => {})
			const text = (await body.textContent().catch(() => '')).trim()
			if (!text) rec.add('materials', `${who}: the reader of "${title}" shows no text (#110)`)
			else if (/^#\s|\n#\s/.test(text)) rec.add('materials', `${who}: the reader of "${title}" shows Markdown source, not rendered text (#110)`)
			;(rec.current.notes ||= []).push(`reader "${title}": ${text.length} characters`)
			await page.getByRole('button', { name: 'Close' }).last().click().catch(() => {})
			if (!(await waitUntil(async () => !(await body.isVisible().catch(() => false)), 5000))) {
				rec.add('materials', `${who}: the reader of "${title}" does not close (#110)`)
			}
		} else {
			const tab = await opened
			if (!tab) rec.add('materials', `${who}: "Open document" of "${title}" opened nothing`)
			else {
				await tab.waitForLoadState().catch(() => {})
				await tab.close().catch(() => {})
			}
		}
		// the page reads the state again 1.5 s after the click and on return to the tab
		if (!(await waitUntil(async () => !(await btn.isDisabled()), 8000))) {
			rec.add('materials', `${who}: "${title}" is still locked 8 s after "Open document" (#86)`)
			continue
		}
		await btn.click()
		await waitUntil(async () => (await btn.textContent()).trim() === 'Confirmed as read', 5000)
	}
}

// ---------------------------------------------------------------- the generic sweep
async function sweepPersona(browser, persona, opts = {}) {
	const rec = new Recorder(persona, opts)
	const { context, page } = await login(browser, persona, opts)
	rec.attach(page)

	// Top-level tabs and their sub-tabs.
	await openApp(page)
	const topLabels = (await mainNav(page).allTextContents()).map((s) => s.replace(/\s+/g, ' ').trim())
	for (const top of topLabels) {
		const enter = () => openTopTab(page, top)
		await station(page, rec, `Top: ${top}`, async () => { await enter(); await clickAll(page, rec, enter) })
		if (top === 'Courses') continue // the course itself is swept below
		for (const sub of await subTabLabels(page, { skip: '.mode-selector' }).catch(() => [])) {
			const enterSub = async () => { await enter(); await clickSubTab(page, sub) }
			await station(page, rec, `Top: ${top} › ${sub.label}`, async () => { await enterSub(); await clickAll(page, rec, enterSub) })
		}
	}

	// Course list sub-tabs (instructors) without a selected course.
	await openTopTab(page, 'Courses')
	for (const sub of await subTabLabels(page).catch(() => [])) {
		const enterSub = async () => { await openTopTab(page, 'Courses'); await clickSubTab(page, sub) }
		await station(page, rec, `Courses › ${sub.label}`, async () => { await enterSub(); await clickAll(page, rec, enterSub) })
	}

	// Course: every main tab, every sub-tab.
	const courseReachable = await openCourse(page).then(() => true, () => false)
	if (!courseReachable) {
		await station(page, rec, 'Course list: course missing', async () => {
			await openTopTab(page, 'Courses')
			rec.add('course-missing', `"${COURSE_TITLE}" is not in this persona's course list`)
		})
	}
	const megaLabels = !courseReachable ? [] : (await page.locator('.mega-tab-selector [role=tab]').allTextContents()).map((s) => s.trim())
	for (const mega of megaLabels) {
		const enterMega = () => openMegaTab(page, mega)
		await station(page, rec, `Course › ${mega}`, async () => { await enterMega() })
		const subs = await subTabLabels(page).catch(() => [])
		if (!subs.length) {
			await station(page, rec, `Course › ${mega} (buttons)`, async () => { await enterMega(); await clickAll(page, rec, enterMega) })
		}
		for (const sub of subs) {
			const enterSub = async () => { await enterMega(); await clickSubTab(page, sub) }
			await station(page, rec, `Course › ${mega} › ${sub.label}`, async () => { await enterSub(); await clickAll(page, rec, enterSub) })
		}
	}

	// Pool window: open the first pool, every tab of it.
	const enterPool = async () => {
		await openTopTab(page, 'Pools')
		await page.getByText('Fire safety', { exact: true }).first().click()
		await settle(page)
	}
	let poolReachable = true
	await station(page, rec, 'Pool window: Fire safety', async () => {
		await enterPool().catch(() => {
			poolReachable = false
			rec.add('pool-missing', 'the pool "Fire safety" is not in this persona\'s pool list')
		})
	})
	// GitHub #46: a pool editor's window starts as Manage + Statistics; the learner modes sit
	// behind the "practise as learner" switch. Sweep the editor tabs with the switch off, then
	// switch it on and sweep the tabs it adds, so the gate still visits Training, Leitner, Exam.
	const setPractise = async (on) => {
		const input = page.locator('.pool-practise-switch input').first()
		if (!(await input.count()) || (await input.isChecked()) === on) return false
		// @nextcloud/vue 9 renders the switch as <span><input> + <span class="…__content"></span>,
		// without a <label>: click the visible text, as a person would.
		await page.locator('.pool-practise-switch .checkbox-radio-switch__content').first().click()
		await settle(page)
		return true
	}
	const enterPoolAs = async (practise) => { await enterPool(); await setPractise(practise) }
	if (poolReachable) await enterPoolAs(false).catch(() => {})
	const plainTabs = poolReachable ? await subTabLabels(page).catch(() => []) : []
	for (const sub of plainTabs) {
		const enterSub = async () => { await enterPoolAs(false); await clickSubTab(page, sub) }
		await station(page, rec, `Pool window › ${sub.label}`, async () => { await enterSub(); await clickAll(page, rec, enterSub) })
	}
	const hasSwitch = poolReachable && await enterPoolAs(false).then(() => page.locator('.pool-practise-switch').count(), () => 0)
	if (hasSwitch) {
		await station(page, rec, 'Pool window › Practise as learner', async () => { await enterPoolAs(true) })
		const seen = new Set(plainTabs.map((t) => t.list + '|' + t.label))
		for (const sub of (await subTabLabels(page).catch(() => [])).filter((t) => !seen.has(t.list + '|' + t.label))) {
			const enterSub = async () => { await enterPoolAs(true); await clickSubTab(page, sub) }
			await station(page, rec, `Pool window (practising) › ${sub.label}`, async () => { await enterSub(); await clickAll(page, rec, enterSub) })
		}
		await enterPoolAs(false).catch(() => {})
	}

	// Settings pages.
	const enterPersonal = async () => { await page.goto(`${BASE}/index.php/settings/user/learning`); await settle(page, 1200) }
	await station(page, rec, 'Personal settings', async () => { await enterPersonal(); await clickAll(page, rec, enterPersonal) })
	// GitHub #76: "Reset profile" at the very end of the page was white on white (1:1) and the sweep
	// passed it: axe files 1:1 under incomplete (runAxe now counts it), and axe only measures the
	// state it sees. Here the learner scrolls down and axe checks the button at rest, hovered and
	// focused (the station's own page check then runs on the focused state).
	if (persona.startsWith('learner')) {
		await station(page, rec, 'Personal settings › end of page: Reset profile (rest, hover, focus)', async () => {
			await enterPersonal()
			await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
			const reset = page.getByRole('button', { name: /^reset profile$/i }).first()
			if (!(await reset.isVisible().catch(() => false))) {
				rec.add('station-error', 'Reset profile button not found at the end of the personal settings')
				return
			}
			await reset.scrollIntoViewIfNeeded()
			for (const f of await runAxe(page)) rec.add(f.kind, `at rest: ${f.detail}`)
			await reset.hover()
			await page.waitForTimeout(300)
			for (const f of await runAxe(page)) if (f.kind === 'a11y' && f.detail.startsWith('color-contrast')) rec.add(f.kind, `hovered: ${f.detail}`)
			await page.mouse.move(0, 0)
			await reset.focus()
			await page.waitForTimeout(200)
		})
	}
	if (persona.startsWith('admin')) {
		const enterAdmin = async () => { await page.goto(`${BASE}/index.php/settings/admin/learning`); await settle(page, 1200) }
		await station(page, rec, 'Admin settings', async () => {
			await enterAdmin()
			// Fresh-eyes C5: the recipient saved by the admin test must survive every sweep before
			// this one (a whole run only: a station filter may skip the admin test).
			if (!ONLY && await page.locator('.ticket-recipients-none').isVisible().catch(() => false)) rec.add('tickets', 'the ticket recipient saved earlier in this run is gone')
			await clickAll(page, rec, enterAdmin)
		})
	}
	flush()
	await context.close()
}

// ---------------------------------------------------------------- tests
test.describe.configure({ mode: 'serial' })

test.beforeAll(() => {
	for (const d of [SHOTS, DOWNLOADS, AUTH]) fs.mkdirSync(d, { recursive: true })
	allFindings = []
	flush()
})

test('learner passes both exams, opens, prints and downloads the certificate', async ({ browser }) => {
	fs.rmSync(VERIFY_URL_FILE, { force: true }) // a stale address must not stand in for this run's certificate
	test.setTimeout(10 * 60_000)
	const rec = new Recorder('learner1')
	const { context, page } = await login(browser, 'learner1')
	rec.attach(page)
	// The toast check must be able to fail: Nextcloud's own toast without any app request has to
	// come out as "success toast without a write request". The finding is moved to the station's
	// selfTest field so that it does not count as an app finding.
	await station(page, rec, 'Detector self-test: toast without a request', async () => {
		await openApp(page)
		const since = Date.now()
		await page.evaluate(() => window.OCP?.Toast?.success('persona self-test'))
		await page.waitForTimeout(500)
		await checkToasts(page, rec, since, 'self-test')
		const hit = rec.current.findings.findIndex((f) => f.kind === 'toast-without-write' && f.detail.includes('persona self-test'))
		rec.current.selfTest = hit >= 0 ? 'toast check works' : 'TOAST CHECK BROKEN: the self-test toast was not reported'
		if (hit >= 0) rec.current.findings.splice(hit, 1)
		else rec.add('detector', rec.current.selfTest)
		await page.evaluate(() => document.querySelectorAll('.toastify').forEach((t) => t.remove()))
	})
	// Same for the contrast check: grey on grey in the app's area must come out as a finding.
	await station(page, rec, 'Detector self-test: low contrast', async () => {
		await openApp(page)
		await page.evaluate(() => {
			const el = document.createElement('p')
			el.id = 'persona-axe-selftest'
			el.textContent = 'persona self-test: grey on grey'
			el.style.cssText = 'color:#777;background:#888;font-size:14px;padding:4px'
			// GitHub #76: text in its background's colour (1:1) is filed by axe as incomplete, not as a
			// violation; it must come out as a finding too.
			const same = document.createElement('p')
			same.id = 'persona-axe-selftest-equal'
			same.textContent = 'persona self-test: white on white'
			same.style.cssText = 'color:#fff;background:#fff;font-size:14px;padding:4px'
			const root = document.querySelector('#app-content') || document.body
			root.prepend(same)
			root.prepend(el)
		})
		const found = await runAxe(page)
		const hit = (id) => found.some((f) => f.detail.startsWith('color-contrast') && new RegExp('#' + id + '(?![\\w-])').test(f.detail))
		await page.evaluate(() => ['persona-axe-selftest', 'persona-axe-selftest-equal'].forEach((id) => document.getElementById(id)?.remove()))
		const broken = [hit('persona-axe-selftest') ? null : 'grey on grey', hit('persona-axe-selftest-equal') ? null : 'white on white (1:1)'].filter(Boolean)
		rec.current.axeSelfTest = broken.length ? `CONTRAST CHECK BROKEN: not reported: ${broken.join(', ')}` : 'contrast check works'
		if (broken.length) rec.add('detector', rec.current.axeSelfTest)
	})
	const enterExamTab = async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Exam' }).first().click()
		await settle(page)
	}
	await station(page, rec, 'Course: reload keeps the course (bookmark / refresh)', async () => {
		await openCourse(page)
		const own = page.url() // the URL the app itself shows, with or without index.php
		await page.goto(own)
		await settle(page, 1200)
		const shown = await page.locator('.mega-tab-selector [role=tab]').first().waitFor({ timeout: 10000 }).then(() => true, () => false)
		if (!shown) {
			rec.add('deep-link', `reloading ${shortUrl(own)} does not show the course (landed on ${shortUrl(page.url())})`)
		}
	})
	// GitHub #75: one "Learning materials" tab for learners: the required documents first, each with
	// "Confirm I have read this", the optional file below with an "Optional" hint. In the
	// mandatory-training run the exam waits for both confirmations (seed: gate on).
	const mats = seed.materials
	const enterMaterials = async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Learning materials' }).first().click()
		await settle(page)
	}
	const requiredList = () => page.locator('[data-test="required-content-learner"] > li')
	const confirmButtons = () => requiredList().getByRole('button', { name: /^(Confirm I have read this|Confirmed as read|Saving…)$/ })
	await station(page, rec, 'Learning materials (learner): required and optional', async () => {
		await enterMaterials()
		const titles = (await requiredList().locator('.video-item__title').allTextContents()).map((s) => s.trim())
		for (const file of mats.required) if (!titles.includes(file)) rec.add('materials', `required document "${file}" is not in the required list (${titles.join(', ') || 'empty'})`)
		for (const file of mats.optional) if (titles.includes(file)) rec.add('materials', `optional document "${file}" is listed as required`)
		const optionalRows = page.locator('.student-videos-section .material-table tbody tr')
		const optionalText = (await optionalRows.allTextContents()).join(' | ')
		for (const file of mats.optional) {
			const row = optionalRows.filter({ hasText: file }).first()
			if (!(await row.isVisible().catch(() => false))) rec.add('materials', `optional document "${file}" is not under "More learning material" (${optionalText.slice(0, 160) || 'no rows'})`)
			else if (!(await row.locator('.material-optional-hint').isVisible().catch(() => false))) rec.add('materials', `optional document "${file}" carries no "Optional" hint`)
		}
		for (const file of mats.required) if (optionalText.includes(file)) rec.add('materials', `required document "${file}" is also listed as optional`)
		const locked = await page.locator('[data-test="required-content-locked"]').isVisible().catch(() => false)
		if (mats.examGated && !locked) rec.add('materials', 'the exam is gated, but the learner is not told it is locked until the required content is done')
		if (!mats.examGated && locked) rec.add('materials', 'the course does not gate the exam, but the learner is told it is locked')
		// C5: VirtuProf's guide for this tab was the instructor's "Upload reference materials for students."
		const guide = await page.evaluate(() => document.body.innerText)
		if (/Upload reference materials/i.test(guide)) rec.add('materials', 'the learner is guided to "Upload reference materials for students" on Learning materials')
		if ((await confirmButtons().count()) !== mats.required.length) rec.add('materials', `${await confirmButtons().count()} confirm buttons for ${mats.required.length} required documents`)
		await page.screenshot({ path: path.join(SHOTS, 'guide-learner-materials.png'), fullPage: true })
		await axeStates(page, rec, confirmButtons(), '"Confirm I have read this"')
	})
	// GitHub #86: in the mandatory-training run every confirm button waits for "Open document" (and
	// the server refuses a confirmation without it); in the IT course nothing waits.
	await station(page, rec, 'Learning materials (learner): confirm waits for "Open document" (#86)', async () => {
		await enterMaterials()
		await checkConfirmLock(page, rec, mats.openFirst, 'learner1')
		if (mats.openFirst) await probeConfirmRefused(page, rec, 'learner1', 'learner1')
		await page.screenshot({ path: path.join(SHOTS, 'guide-learner-materials-open-first.png'), fullPage: true })
	})
	await station(page, rec, 'Learning materials (learner): confirm both required documents', async () => {
		await enterMaterials()
		await openAndConfirmAll(page, rec, 'learner1')
		await enterMaterials() // read back: the confirmation is stored, not only shown
		const labels = (await confirmButtons().allTextContents()).map((s) => s.trim())
		const open = labels.filter((l) => l !== 'Confirmed as read')
		if (open.length) rec.add('materials', `after confirming and reloading, ${open.length} of ${labels.length} required documents are not confirmed: ${labels.join(', ')}`)
		if (mats.examGated && !(await page.locator('[data-test="required-content-unlocked"]').isVisible().catch(() => false))) {
			rec.add('materials', 'all required documents confirmed, but the exam is not shown as unlocked')
		}
	})
	// GitHub #92: with the required content done, one primary button leads on — to the exam, since
	// the course certifies — and the area it opens is announced.
	await station(page, rec, 'Learning materials (learner): next step after the required content (#92)', async () => {
		await enterMaterials()
		const next = page.locator('[data-test="materials-next-step-button"]')
		if (!(await next.isVisible().catch(() => false))) {
			rec.add('guidance', 'all required content confirmed, but no button leads on (#92)')
			return
		}
		const label = (await next.textContent()).trim()
		if (label !== 'Go to the exam') rec.add('guidance', `the next step after the required content reads "${label}", expected "Go to the exam" (#92)`)
		if (!(await isPrimary(next))) rec.add('guidance', 'the next step after the required content is not the primary button (#92)')
		await axeStates(page, rec, next, `"${label}"`)
		await next.click()
		await settle(page)
		if (!/\/exam$/.test(new URL(page.url()).pathname)) rec.add('guidance', `"${label}" led to ${shortUrl(page.url())}, not to the exam (#92)`)
		if (!(await page.locator('#app-content').getByText('Fire safety', { exact: true }).first().isVisible().catch(() => false))) rec.add('guidance', `"${label}" does not show the exam pools (#92)`)
		const said = (await page.locator('[data-test="guide-announcement"]').textContent().catch(() => '')).trim()
		if (!said) rec.add('a11y', `"${label}" changes the area without announcing it (#92)`)
		;(rec.current.notes ||= []).push(`announced: ${said}`)
	})
	const pools = ['Fire safety', 'Data protection']
	for (const [i, pool] of pools.entries()) {
		await station(page, rec, `Exam setup: ${pool}`, async () => {
			await enterExamTab()
			if (i === 0) await page.screenshot({ path: path.join(SHOTS, 'guide-exam-tab.png') })
			await page.locator('#app-content').getByText(pool, { exact: true }).first().click()
			await settle(page)
			if (i === 0) await page.screenshot({ path: path.join(SHOTS, 'guide-exam-start.png') })
		})
		await station(page, rec, `Exam: ${pool} (all answers right)`, async () => {
			const { banner, score } = await takeExam(page)
			if (banner !== 'Passed') rec.add('exam', `${pool}: result banner says "${banner}" after all answers right`)
			// "Passed" alone let a wrong click through (7 of 8 is still over the pass mark).
			const sm = score.match(/(\d+)\s*\/\s*(\d+)/)
			if (!sm || sm[1] !== sm[2]) rec.add('exam', `${pool}: ${score || 'no score'} correct after all answers right`)
		})
	}
	await station(page, rec, 'Exam result: certificate notice', async () => {
		const open = page.getByRole('button', { name: 'Open certificate' })
		if (!(await open.isVisible().catch(() => false))) rec.add('certificate', 'no "Open certificate" after passing both required pools')
		// GitHub #92: after the pass that brings the certificate, the way on is the certificate, and
		// "Retake exam" is no longer the blue button
		const mine = page.locator('[data-test="guide-certificate"]')
		if (!(await mine.isVisible().catch(() => false))) rec.add('guidance', 'no "Go to my certificate" after the pass that brought the certificate (#92)')
		else if (!(await isPrimary(mine))) rec.add('guidance', '"Go to my certificate" is not the primary button (#92)')
		const retake = page.locator('[data-test="guide-retake"]')
		if (await retake.isVisible().catch(() => false) && await isPrimary(retake)) rec.add('guidance', '"Retake exam" is the primary button after a pass (#92)')
	})
	await station(page, rec, 'Certificate (from the exam result)', async () => {
		await page.getByRole('button', { name: 'Open certificate' }).click()
		await page.locator('.certificate-printable').waitFor({ timeout: 15000 })
		await settle(page)
		await page.screenshot({ path: path.join(SHOTS, 'guide-certificate.png') })
		const card = (await page.locator('.certificate-printable').textContent()).replace(/\s+/g, ' ')
		for (const must of ['Learner 1', COURSE_TITLE]) {
			if (!card.includes(must)) rec.add('certificate', `certificate card does not show "${must}"`)
		}
		// GitHub #77/#78: the course's organisation name and its library logo (seed), not Theming's
		if (!card.includes(seed.certificate.issuerName)) rec.add('certificate', `certificate card does not show the course's organisation name "${seed.certificate.issuerName}"`)
		// GitHub #91: the public verification page is checked from outside with this address
		const verifyUrl = (await page.locator('.certificate-printable .certificate-verify-url').first().textContent().catch(() => '')).trim()
		if (verifyUrl) fs.writeFileSync(VERIFY_URL_FILE, verifyUrl)
		else rec.add('certificate', 'certificate card shows no verification address')
		// GitHub #93: issued while the course had the library background, so the card carries it
		await expectBackground(page, rec, 'certificate from the exam result')
		const logo = page.locator('.certificate-printable .certificate-logo').first()
		if (!(await logo.isVisible().catch(() => false))) rec.add('certificate', 'certificate card shows no logo')
		else {
			if (!(await logo.evaluate((el) => el.classList.contains('certificate-logo--library')))) rec.add('certificate', 'certificate logo is not the library logo the course chose')
			if (!(await logo.evaluate((el) => el.complete && el.naturalWidth > 0))) rec.add('certificate', 'certificate logo image did not load')
		}
	})
	await station(page, rec, 'Certificate: print path', async () => {
		await page.locator('.certificate-actions').getByRole('button', { name: /print/i }).first().click()
		await page.waitForTimeout(800)
		const printed = await page.evaluate(() => window.__personaPrinted || 0)
		if (!printed) rec.add('print', 'Print did not call window.print()')
		const rootText = await page.evaluate(() => document.querySelector('#learning-certificate-print-root')?.textContent?.trim().length || 0)
		if (rootText < 50) rec.add('print', `print copy #learning-certificate-print-root has ${rootText} characters`)
		const printName = await page.evaluate((name) => (document.querySelector('#learning-certificate-print-root')?.textContent || '').includes(name), seed.certificate.issuerName)
		if (!printName) rec.add('print', `print copy does not show the organisation name "${seed.certificate.issuerName}"`)
		await page.emulateMedia({ media: 'print' })
		await page.screenshot({ path: path.join(SHOTS, 'guide-certificate-print.png'), fullPage: true })
		// GitHub #80: the page size the stylesheet asks for (A4 landscape), as Chrome's print dialog uses it
		await page.pdf({ path: path.join(DOWNLOADS, 'certificate-print.pdf'), preferCSSPageSize: true, printBackground: true })
		await page.emulateMedia({ media: 'screen' })
		const pdfSize = fs.statSync(path.join(DOWNLOADS, 'certificate-print.pdf')).size
		if (pdfSize < 5000) rec.add('print', `printed PDF is only ${pdfSize} bytes`)
		// C10: in Nextcloud (html absolute, body fixed) the named A4-landscape page was dropped (Letter portrait)
		const paper = (execFileSync('pdfinfo', [path.join(DOWNLOADS, 'certificate-print.pdf')], { encoding: 'utf8' }).match(/Page size:\s+([\d.]+) x ([\d.]+)/) || []).slice(1).map((v) => Math.round(Number(v)))
		if (paper[0] !== 842 || paper[1] !== 595) rec.add('print', `the printed certificate is ${paper.join(' × ')} pt, not A4 landscape (842 × 595) (#80)`)
	})
	await station(page, rec, 'Certificate: download', async () => {
		// GitHub #95: the JSON file sits behind a closed "Technical details" disclosure
		const details = page.locator('details.certificate-credential').first()
		if (!(await details.count())) {
			rec.add('download', 'certificate shows no "Technical details" section (course switch is on by default)')
			return
		}
		if (await details.evaluate((el) => el.open)) rec.add('download', '"Technical details" is open before the learner opens it')
		else await details.locator('summary').click()
		const dl = await download(page, () => details.getByRole('button', { name: /download/i }).first().click(), 'learner-certificate')
		if (dl.size < 200) rec.add('download', `certificate download is ${dl.size} bytes (${dl.suggested})`)
		// GitHub #94: the PDF has its own button now; this one must give the signed JSON credential
		if (!/^\s*\{/.test(dl.head.toString('utf8'))) rec.add('download', `"Download credential (JSON)" gave something else than JSON: ${dl.suggested} (${JSON.stringify(dl.head.subarray(0, 12).toString('latin1'))})`)
	})
	await closeOverlays(page)
	await station(page, rec, 'Course › Completion & certificate (after passing)', async () => {
		await openMegaTab(page, 'Completion')
	})
	flush()
	await context.close()
})

// GitHub #66: one question carries a hint, feedback per option, background, key takeaway, a spoken
// intro, a spoken explanation per option and a dialog (seed: fixture demo-q1 in the optional pool
// "Question media demo", bundles v1 + v2: 0.2–0.8 s sine tones, invented texts). learner3 answers
// it in Training, wrong on purpose, so that the feedback for the chosen option shows.
// 5.16.0 (Andre 18.11., points 3–7): the question waits for the intro (hidden, no button: hover,
// keyboard focus or a tap uncover it), "Play automatically" and "Sounds" per user, a tone right
// after answering ("Pling" sine / "Zonk" square, Web Audio), and no gliding with reduced motion.
// The tones are counted by a probe on OscillatorNode.start, nothing is heard.
test('learner practises the media question: hint, intro, feedback, dialog', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const media = seed.media
	const rec = new Recorder('learner3')
	const { context, page } = await login(browser, 'learner3')
	rec.attach(page)
	await context.addInitScript(() => {
		window.__personaTones = []
		const proto = window.OscillatorNode && window.OscillatorNode.prototype
		if (proto && !proto.__personaProbe) {
			const start = proto.start
			proto.start = function (...args) { window.__personaTones.push(this.type); return start.apply(this, args) }
			proto.__personaProbe = true
		}
	})
	const prefsCall = async (body) => {
		const res = await fetch(`${APP}/api/settings/personal/question-media`, {
			method: 'PUT',
			headers: { Authorization: basic('learner3'), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		})
		if (!res.ok) throw new Error(`PUT question-media → ${res.status}`)
	}
	const prefsNow = async () => {
		const res = await fetch(`${APP}/api/settings/personal`, { headers: { Authorization: basic('learner3'), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
		const body = await res.json().catch(() => ({}))
		return { autoplay: body.question_media_autoplay, sounds: body.answer_sounds }
	}
	// the defaults (off, sounds follow autoplay), also after an earlier run on the same instance
	await prefsCall({ autoplay: false, sounds: 'auto' })
	const card = () => page.locator('.training-active .question-card').first()
	const conceal = () => card().locator('.qconceal').first()
	const player = () => card().locator('.qmedia').first()
	const switchFor = (label) => player().locator('.qmedia__switch').filter({ hasText: label }).locator('input[role=switch]').first()
	const tones = () => page.evaluate(() => [...(window.__personaTones || [])]).catch(() => [])
	const isHidden = () => conceal().evaluate((el) => el.classList.contains('qconceal--hidden')).catch(() => false)
	const startMediaQuestion = async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Training' }).first().click()
		await settle(page)
		await page.locator('#app-content .pool-item').filter({ hasText: media.poolName }).first().click()
		await settle(page)
		await page.getByRole('button', { name: 'Start Training' }).first().click()
		// the pointer stays where "Start Training" was; hovering the card would uncover the question
		await page.mouse.move(0, 0)
		await card().locator('.question-text').first().waitFor({ state: 'attached', timeout: 15000 })
		const text = (await card().locator('.question-text').first().textContent()).trim()
		if (text !== media.question) throw new Error(`Training shows "${text}", not the media question`)
		await player().waitFor({ timeout: 10000 })
	}
	const answerWrong = async () => {
		const pick = pickAnswerIndex(await card().locator('.answer-btn').allTextContents(), media.wrongAnswer)
		if (pick === -1) throw new Error(`no answer button "${media.wrongAnswer}"`)
		await card().locator('.answer-btn').nth(pick).click()
		await card().locator('.answer-feedback').first().waitFor({ timeout: 15000 })
		await settle(page)
	}
	// GitHub #79: a learner's pool rows in the course are whole buttons; the name inside has no block.
	await station(page, rec, 'Learning space › Training: hover and focus on a pool row (learner) (#79)', async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Training' }).first().click()
		await settle(page)
		const row = page.locator('#app-content .pool-item').filter({ hasText: media.poolName }).first()
		const name = row.locator('.pool-name').first()
		await row.hover()
		await page.waitForTimeout(300)
		const transparent = (c) => c === 'transparent' || /^rgba\(\d+, \d+, \d+, 0\)$/.test(c)
		const hovered = await name.evaluate((el) => getComputedStyle(el).backgroundColor)
		if (!transparent(hovered)) rec.add('hover', `Training pool row: the pool name has background ${hovered} on hover (square block, #79)`)
		await page.mouse.move(0, 0)
		await page.keyboard.press('Shift')
		await row.focus()
		const focused = await name.evaluate((el) => getComputedStyle(el).backgroundColor)
		if (!transparent(focused)) rec.add('hover', `Training pool row: the pool name has background ${focused} on focus (square block, #79)`)
		await axeStates(page, rec, row, 'the Training pool row')
	})
	await station(page, rec, 'Question card with intro: the question waits for the intro, autoplay off (#66)', async () => {
		await startMediaQuestion()
		const order = await page.evaluate(() => {
			const c = document.querySelector('.training-active .question-card')
			const p = c?.querySelector('.qmedia')
			const q = c?.querySelector('.qconceal')
			return p && q ? !!(p.compareDocumentPosition(q) & Node.DOCUMENT_POSITION_FOLLOWING) : null
		})
		if (order !== true) rec.add('media', 'the intro player does not come before the question in the card (Andre 18.11. point 2)')
		const auto = switchFor('Play automatically')
		const sounds = switchFor('Sounds')
		if (!(await auto.isVisible().catch(() => false))) rec.add('media', 'no "Play automatically" switch in the player')
		else if (await auto.isChecked()) rec.add('media', '"Play automatically" is on by default (WCAG 1.4.2: off)')
		if (!(await sounds.isVisible().catch(() => false))) rec.add('media', 'no "Sounds" switch in the player')
		else if (await sounds.isChecked()) rec.add('media', '"Sounds" is on although it follows "Play automatically", which is off')
		await page.waitForTimeout(2500) // nothing plays by itself, so the question must still wait
		if (!(await isHidden())) { rec.add('media', 'the question is shown before the intro was heard (autoplay off, no hover, no focus)'); return }
		const el = conceal()
		// name: aria-label, else the text of the aria-labelledby targets (what a screen reader announces)
		const attrs = await el.evaluate((n) => ({
			role: n.getAttribute('role'), expanded: n.getAttribute('aria-expanded'), tabindex: n.getAttribute('tabindex'),
			label: n.getAttribute('aria-label') ?? (n.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean).map((id) => document.getElementById(id)?.textContent.trim() || '').join(' '),
		}))
		if (attrs.role !== 'button' || attrs.expanded !== 'false' || attrs.tabindex !== '0') rec.add('a11y-flow', `the hidden question is not a named Tab stop: ${JSON.stringify(attrs)}`)
		const veil = (await el.locator('.qconceal__veil').textContent().catch(() => '')).trim()
		if (veil !== 'The question appears after the intro – or right away when you point or tap here.') rec.add('media', `the hidden question says "${veil}"`)
		// WCAG 2.5.3 (axe label-content-name-mismatch): the text on the area is part of its name
		if (!veil || !attrs.label.includes(veil)) rec.add('a11y-flow', `the hidden question is named "${attrs.label}", which does not contain its visible text "${veil}"`)
		const reach = await el.evaluate((n) => {
			const content = n.querySelector('.qconceal__content')
			const answers = [...n.querySelectorAll('.answer-btn')]
			return { inert: !!content?.inert, answers: answers.length, opacity: Number(getComputedStyle(content).opacity) }
		})
		if (!reach.inert) rec.add('a11y-flow', 'the hidden question and its answers are not inert (Tab or a screen reader reach them)')
		if (!reach.answers) rec.add('media', 'no answers in the hidden question')
		if (reach.opacity > 0.05) rec.add('media', `the hidden question is readable (opacity ${reach.opacity})`)
		const layout = await page.evaluate(() => {
			const q = document.querySelector('.training-active .qconceal')
			return q ? q.getBoundingClientRect().height : 0
		})
		;(rec.current.notes ||= []).push(`hidden question area: ${Math.round(layout)} px high`)
		if (layout < 80) rec.add('media', `the hidden question keeps no space (${Math.round(layout)} px), uncovering it moves the page`)
		await page.screenshot({ path: path.join(SHOTS, 'media-question-hidden.png') })
	})
	await station(page, rec, 'Question card with intro: keyboard focus uncovers the question (#66)', async () => {
		if (!(await isHidden())) throw new Error('the question is not hidden (previous station)')
		await page.keyboard.press('Shift')
		let reached = false
		for (let i = 0; i < 40 && !reached; i++) {
			await page.keyboard.press('Tab')
			reached = await page.evaluate(() => !!document.activeElement?.classList?.contains('qconceal'))
			if (!reached && !(await isHidden())) break // something else uncovered it
		}
		if (!reached) { rec.add('a11y-flow', 'Tab never reaches the hidden question (WCAG 2.1.1)'); return }
		await page.waitForTimeout(700) // the glide in
		if (await isHidden()) { rec.add('a11y-flow', 'the keyboard focus on the hidden question does not uncover it'); return }
		const shown = await conceal().evaluate((n) => {
			const content = n.querySelector('.qconceal__content')
			return { inert: !!content?.inert, opacity: Number(getComputedStyle(content).opacity), expanded: n.getAttribute('aria-expanded'), role: n.getAttribute('role') }
		})
		if (shown.inert || shown.opacity < 0.95) rec.add('a11y-flow', `uncovered by focus, the question is still out of reach: ${JSON.stringify(shown)}`)
		if (shown.role === 'button') rec.add('a11y-flow', 'the uncovered question is still announced as a button')
		await page.keyboard.press('Tab')
		const next = await page.evaluate(() => document.activeElement?.closest?.('.qconceal__content') ? (document.activeElement.className || document.activeElement.tagName) : null)
		if (!next) rec.add('a11y-flow', 'the Tab after uncovering does not go into the question (hint or answers)')
	})
	await station(page, rec, 'Training: media question before answering (hint, intro)', async () => {
		const hint = card().locator('.question-hint')
		if (!(await hint.isVisible().catch(() => false))) rec.add('media', 'no hint before answering')
		else {
			await hint.locator('button').first().click()
			await page.waitForTimeout(300)
			if (!(await hint.locator('.question-hint__text').isVisible().catch(() => false))) rec.add('media', 'the hint does not open')
		}
		if (await player().isVisible().catch(() => false)) {
			const title = (await player().locator('.qmedia__title').textContent().catch(() => '')).trim()
			if (title !== 'Intro') rec.add('media', `player title before answering is "${title}", not "Intro"`)
			const play = player().locator('.qmedia__button--primary')
			if (await play.isDisabled().catch(() => true)) rec.add('media', 'the intro cannot be played (Play disabled)')
			else {
				await play.click()
				await page.waitForTimeout(1500)
				const note = (await player().locator('.qmedia__note').textContent().catch(() => '')).trim()
				if (note) rec.add('media', `intro audio: ${note}`)
				if (await player().locator('.qmedia__button--primary').getAttribute('aria-label') === 'Pause') await play.click().catch(() => {}) // pause
			}
		} else rec.add('media', 'no intro player before answering')
	})
	await station(page, rec, 'Training: media question answered wrong (feedback, option voice, dialog, no tone)', async () => {
		await answerWrong()
		const marked = await card().locator('.answer-btn-review.answer-wrong-selected').first().textContent().catch(() => '')
		if (!marked.includes(media.wrongAnswer)) rec.add('media', `the chosen wrong option is not marked red ("${marked.trim()}")`)
		if (!(await card().locator('.answer-btn-review.answer-correct').count())) rec.add('media', 'the right option is not marked green')
		const feedback = card().locator('.rich-feedback')
		if (!(await feedback.isVisible().catch(() => false))) rec.add('media', 'no feedback per option after answering')
		else {
			const chosen = (await feedback.locator('.rich-feedback__option--wrong').first().textContent().catch(() => '')).replace(/\s+/g, ' ')
			if (!chosen.includes(media.wrongAnswer)) rec.add('media', `the wrong option's feedback does not name "${media.wrongAnswer}": ${chosen.slice(0, 120)}`)
			if (!(await feedback.locator('.rich-feedback__takeaway').isVisible().catch(() => false))) rec.add('media', 'no key takeaway after answering')
		}
		if (await card().locator('.question-hint').isVisible().catch(() => false)) rec.add('media', 'the hint is still shown after answering')
		const heard = await tones()
		if (heard.length) rec.add('media', `a tone after answering although "Sounds" is off (${heard.join(', ')})`)
		if (!(await player().isVisible().catch(() => false))) { rec.add('media', 'no player after answering'); return }
		const title = async () => (await player().locator('.qmedia__title').textContent().catch(() => '')).trim()
		if (await title() !== 'About your answer') rec.add('media', `player title after answering is "${await title()}", not "About your answer" (the chosen option has a voice, bundle v2)`)
		const next = player().getByRole('button', { name: /Next line/ })
		await next.click().catch(() => rec.add('media', 'no "Next line" for the option voice'))
		await page.waitForTimeout(400)
		const optionCaption = (await player().locator('.qmedia__slide, .qmedia__caption').first().textContent().catch(() => '')).trim()
		if (!/Option A, red, is wrong/.test(optionCaption)) rec.add('media', `the option voice shows "${optionCaption.slice(0, 120)}", not the chosen option's explanation`)
		await next.click().catch(() => {})
		await page.waitForTimeout(400)
		if (await title() !== 'Debrief') rec.add('media', `stepping past the option voice leads to "${await title()}", not "Debrief"`)
		// Usable without sound: step to the second line and check a caption shows.
		await next.click().catch(() => rec.add('media', 'no "Next line" in the dialog'))
		await next.click().catch(() => {})
		await page.waitForTimeout(400)
		const caption = (await player().locator('.qmedia__caption').textContent().catch(() => '')).trim()
		if (!caption) rec.add('media', 'stepping through the dialog shows no caption')
		await player().locator('.qmedia__transcript summary').click().catch(() => {})
		await page.waitForTimeout(300)
	})
	await station(page, rec, 'Question card with intro: autoplay and tones on, remembered (#66)', async () => {
		const auto = switchFor('Play automatically')
		const since = Date.now()
		await auto.click()
		if (!(await waitUntil(async () => rec.requests.some((r) => r.t >= since && r.method === 'PUT' && /question-media$/.test(r.url) && r.status < 400), 6000))) rec.add('media', '"Play automatically" switched on without a successful save')
		if (!(await switchFor('Sounds').isChecked())) rec.add('media', '"Sounds" does not follow "Play automatically" (default "auto")')
		const saved = await prefsNow()
		if (saved.autoplay !== 'yes') rec.add('media', `the server keeps autoplay "${saved.autoplay}" after switching it on`)
		await page.evaluate(() => { window.__personaTones = [] })
		await startMediaQuestion() // a page load: the switch comes from the server
		if (!(await switchFor('Play automatically').isChecked())) rec.add('media', '"Play automatically" is off again after reloading the page (not remembered)')
		// the intro (0.6 s) plays by itself, or the browser refuses and the question shows at once
		if (!(await waitUntil(async () => !(await isHidden()), 8000))) rec.add('media', 'with "Play automatically" on, the question is still hidden 8 s after it was shown')
		const note = (await player().locator('.qmedia__note').textContent().catch(() => '')).trim()
		if (note) rec.add('media', `autoplay: "${note}" (a refused autoplay must fall back silently)`)
		await answerWrong()
		if (!(await waitUntil(async () => (await tones()).length > 0, 3000))) rec.add('media', 'no tone after a wrong answer with "Sounds" on')
		else {
			const heard = await tones()
			if (!heard.includes('square')) rec.add('media', `the tone after a wrong answer is not the "Zonk" (oscillators: ${heard.join(', ')})`)
			if (heard.includes('sine')) rec.add('media', `the "Pling" sounds after a wrong answer (oscillators: ${heard.join(', ')})`)
		}
		if (!(await switchFor('Sounds').isChecked())) rec.add('media', 'after answering, "Sounds" no longer shows on')
		await page.screenshot({ path: path.join(SHOTS, 'media-question-answered-sounds-on.png') })
	})
	await station(page, rec, 'Question card with intro: reduced motion, phone width, hover uncovers (#66)', async () => {
		await page.emulateMedia({ reducedMotion: 'reduce' })
		try {
			await prefsCall({ autoplay: false, sounds: 'auto' })
			await startMediaQuestion() // at desktop width: the course tabs collapse on a phone
			await page.setViewportSize({ width: 390, height: 844 })
			await page.mouse.move(0, 0)
			await page.waitForTimeout(400)
			if (!(await isHidden())) { rec.add('media', 'with autoplay off again, the question is not hidden'); return }
			const still = await page.evaluate(() => ({
				conceal: !!document.querySelector('.training-active .qconceal--still'),
				player: !!document.querySelector('.training-active .qmedia--still'),
				transform: getComputedStyle(document.querySelector('.training-active .qconceal__content')).transform,
			}))
			if (!still.conceal || !still.player) rec.add('media', `reduced motion: no calm mode (${JSON.stringify(still)})`)
			if (still.transform && still.transform !== 'none') rec.add('media', `reduced motion: the hidden question is still moved (${still.transform})`)
			const overflow = await page.evaluate(() => {
				const c = document.querySelector('.training-active .question-card')
				return { card: c ? c.scrollWidth - c.clientWidth : 0, page: document.documentElement.scrollWidth - window.innerWidth }
			})
			if (overflow.card > 1 || overflow.page > 1) rec.add('layout', `phone width: the question card runs over (${JSON.stringify(overflow)})`)
			await conceal().hover()
			if (!(await waitUntil(async () => !(await isHidden()), 2000))) rec.add('media', 'hovering the hidden question does not uncover it')
			const duration = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.training-active .qconceal__content')).transitionDuration) || 0)
			if (duration > 0.25) rec.add('media', `reduced motion: the question still glides in (${duration}s)`)
			await page.mouse.move(0, 0)
			await page.screenshot({ path: path.join(SHOTS, 'media-question-reduced-motion-phone.png') })
		} finally {
			await page.emulateMedia({ reducedMotion: null })
			await page.setViewportSize({ width: 1400, height: 900 })
		}
	})
	await station(page, rec, 'Question card with intro: "Sounds" on, right answer gives the "Pling" (#66)', async () => {
		await prefsCall({ autoplay: false, sounds: 'yes' })
		await startMediaQuestion()
		if (!(await switchFor('Sounds').isChecked())) rec.add('media', '"Sounds" chosen on, the switch shows off')
		if (await switchFor('Play automatically').isChecked()) rec.add('media', '"Play automatically" shows on although it is off')
		await conceal().hover()
		await waitUntil(async () => !(await isHidden()), 2000)
		await page.evaluate(() => { window.__personaTones = [] })
		const right = seed.answers[media.question]
		const pick = pickAnswerIndex(await card().locator('.answer-btn').allTextContents(), right)
		if (pick === -1) throw new Error(`no answer button "${right}"`)
		await card().locator('.answer-btn').nth(pick).click()
		await card().locator('.answer-feedback').first().waitFor({ timeout: 15000 })
		if (!(await waitUntil(async () => (await tones()).length > 0, 3000))) rec.add('media', 'no tone after a right answer with "Sounds" on')
		else {
			const heard = await tones()
			if (!heard.includes('sine')) rec.add('media', `the tone after a right answer is not the "Pling" (oscillators: ${heard.join(', ')})`)
			if (heard.includes('square') || heard.includes('sawtooth')) rec.add('media', `the "Zonk" sounds after a right answer (oscillators: ${heard.join(', ')})`)
		}
		const marked = await card().locator('.answer-btn-review.answer-correct.answer-user-selected').count()
		if (!marked) rec.add('media', 'the chosen right option is not marked green')
		if (await card().locator('.answer-btn-review.answer-wrong-selected').count()) rec.add('media', 'a right answer marks an option red')
	})
	// Dark mode: the hidden area (dashed frame, faint veil text) and the answered card
	await station(page, rec, 'Question card with intro: dark mode, hidden and answered (#66)', async () => {
		await page.emulateMedia({ colorScheme: 'dark' })
		try {
			await prefsCall({ autoplay: false, sounds: 'auto' })
			await startMediaQuestion()
			await page.waitForTimeout(400)
			if (!(await isHidden())) { rec.add('media', 'dark mode: the question is not hidden'); return }
			// axe skips the veil's text only if it is invisible; measure it at rest
			for (const f of await runAxe(page)) rec.add(f.kind, `dark, question hidden: ${f.detail}`)
			await page.screenshot({ path: path.join(SHOTS, 'media-question-hidden-dark.png') })
			await conceal().hover()
			await waitUntil(async () => !(await isHidden()), 2000)
			await page.mouse.move(0, 0)
			await answerWrong()
			const dark = await page.evaluate(() => window.matchMedia('(prefers-color-scheme: dark)').matches && getComputedStyle(document.body).backgroundColor)
			;(rec.current.notes ||= []).push(`dark mode body background: ${dark}`)
			for (const f of await runAxe(page)) rec.add(f.kind, `dark, answered: ${f.detail}`)
			await page.screenshot({ path: path.join(SHOTS, 'media-question-answered-dark.png') })
		} finally {
			await page.emulateMedia({ colorScheme: null })
		}
	})
	// Review of #66: every station above starts the media question afresh; nothing walked from one
	// question to the next. The flow pool (seed) has two questions with a spoken intro. After "Next
	// Question" the second must be held hidden from the first frame (no flash), the first one's
	// sound must have stopped, a mouse crossing the area on its way to the player must not uncover
	// it, and a pointer resting inside it (it was there for "Next Question", so it never "enters"
	// the area) uncovers it after a short rest.
	// The rest point used to be a fixed offset below the top of the first area, and in one of the
	// two shuffled orders it ended up outside the second (autonom-c9b §17.5). Measured cause: not the
	// card (the two hidden areas start 16 px apart relative to the card), but the page scrolling under
	// the resting pointer: focusing "Next Question" scrolled it into view, and the shorter second
	// question clamped the scroll again, by an amount that depends on which question came first.
	// The station therefore runs on a viewport tall enough for the whole card (nothing scrolls; the
	// scroll position is checked), fixes the order (the start response is reordered; answers go by
	// question id), measures both hidden areas once relative to the card (calibration, pointer
	// parked), and walks BOTH orders with the pointer resting in the middle of the band where the
	// first answered area and the second hidden area overlap.
	await station(page, rec, 'Question card with intro: from the first media question to the second (#66)', async () => {
		const flow = media.flow
		if (!flow) throw new Error('seed has no media flow pool')
		if (flow.questions.length !== 2) throw new Error(`the flow pool has ${flow.questions.length} questions, the station walks two`)
		await prefsCall({ autoplay: false, sounds: 'auto' })
		await page.setViewportSize({ width: 1400, height: 2000 })
		try {
			const note = (text) => (rec.current.notes ||= []).push(text)
			const questionText = async () => (await card().locator('.question-text').first().textContent()).trim()
			// starts Training of the flow pool with the questions in `order` (texts)
			const startFlow = async (order) => {
				const reorder = async (route) => {
					const response = await route.fetch()
					const json = await response.json()
					if (Array.isArray(json.questions)) json.questions.sort((a, b) => order.indexOf(String(a.text).trim()) - order.indexOf(String(b.text).trim()))
					await route.fulfill({ response, json })
				}
				await page.route('**/apps/learning/api/training/start', reorder)
				try {
					await openMegaTab(page, 'Learning space')
					await page.locator('.lernraum-subnav button').filter({ hasText: 'Training' }).first().click()
					await settle(page)
					await page.locator('#app-content .pool-item').filter({ hasText: flow.poolName }).first().click()
					await settle(page)
					await page.getByRole('button', { name: 'Start Training' }).first().click()
					await page.mouse.move(0, 0)
					await card().locator('.question-text').first().waitFor({ state: 'attached', timeout: 15000 })
				} finally {
					await page.unroute('**/apps/learning/api/training/start', reorder)
				}
				const first = await questionText()
				if (first !== order[0]) throw new Error(`Training of the flow pool shows "${first}", expected "${order[0]}"`)
				await player().waitFor({ timeout: 10000 })
				return first
			}
			// the hidden area relative to the card: survives scrolling between two measurements
			const relativeArea = async () => {
				const [c, a] = [await card().boundingBox(), await conceal().boundingBox()]
				if (!c || !a) throw new Error('flow: no box for the card or the hidden question')
				return { top: a.y - c.y, height: a.height }
			}
			const nextByKeyboard = async (first) => {
				await card().locator('.next-btn').first().focus()
				await page.keyboard.press('Enter')
				await card().locator('.question-text').filter({ hasNotText: first }).first().waitFor({ state: 'attached', timeout: 15000 })
				await page.waitForTimeout(1000)
			}

			// calibration: both hidden areas, the pointer away from the card
			const geometry = {}
			{
				const first = await startFlow(flow.questions)
				if (!(await waitUntil(isHidden, 3000))) throw new Error('flow calibration: the first question is not held hidden')
				geometry[first] = await relativeArea()
				await conceal().focus()
				if (!(await waitUntil(async () => !(await isHidden()), 2000))) throw new Error('flow calibration: focus does not uncover the first question')
				await card().locator('.answer-btn').first().click()
				await card().locator('.answer-feedback').first().waitFor({ timeout: 15000 })
				await page.mouse.move(0, 0)
				await settle(page, 300)
				await nextByKeyboard(first)
				const second = await questionText()
				if (!(await waitUntil(isHidden, 3000))) throw new Error('flow calibration: the second question is not held hidden')
				geometry[second] = await relativeArea()
				note(`flow: hidden areas relative to the card ${JSON.stringify(geometry)}`)
			}

			const walk = async (order) => {
				const first = await startFlow(order)
				const label = `flow ${first.slice(0, 20)}… first`
				if (!(await waitUntil(isHidden, 3000))) rec.add('media', `${label}: the first question is not held hidden for its intro`)
				// a quick pass over the hidden area towards the player above it must not uncover it
				const firstBox = await conceal().boundingBox()
				if (!firstBox) throw new Error('flow: no box for the hidden question')
				await page.mouse.move(firstBox.x + firstBox.width / 2, firstBox.y + firstBox.height + 30)
				await page.mouse.move(firstBox.x + firstBox.width / 2, firstBox.y - 30, { steps: 4 })
				await page.waitForTimeout(900)
				if (!(await isHidden())) rec.add('media', `${label}: a mouse crossing the hidden question uncovers it (no hover intent)`)
				await conceal().hover()
				if (!(await waitUntil(async () => !(await isHidden()), 2000))) { rec.add('media', `${label}: hovering the first hidden question does not uncover it`); return }
				await card().locator('.answer-btn').first().click()
				await card().locator('.answer-feedback').first().waitFor({ timeout: 15000 })
				await settle(page, 300)
				// the explanation after answering plays (a click is a gesture); "Next Question" follows at
				// once, so its sound is still running when the next question comes
				let played = false
				const play = player().locator('.qmedia__button--primary')
				if (!(await play.isDisabled().catch(() => true))) {
					await play.click()
					played = await waitUntil(() => page.evaluate(() => [...document.querySelectorAll('.training-active .qmedia audio')].some((el) => !el.paused)), 1500, 50)
					note(`${label}: sound of the first question playing at "Next Question": ${played}`)
				}
				// "Next Question" gets the keyboard focus first (focus may scroll); then the pointer comes to
				// rest in the band where the answered area (same element) and the second question's hidden
				// area overlap, in its middle, and stays there through the key press.
				await card().locator('.next-btn').first().focus()
				const [a, b] = [geometry[order[0]], geometry[order[1]]]
				const answeredBox = await conceal().boundingBox()
				const cardBox = await card().boundingBox()
				const top = Math.max(a.top, b.top)
				const bottom = Math.min(answeredBox.y - cardBox.y + answeredBox.height, b.top + b.height)
				if (bottom - top < 40) throw new Error(`flow: the answered area and the second hidden area overlap by ${bottom - top} px only ${JSON.stringify({ a, b, answeredBox, cardBox })}`)
				const rest = { x: answeredBox.x + answeredBox.width / 2, y: cardBox.y + (top + bottom) / 2 }
				const scrollBefore = await page.evaluate(() => window.scrollY)
				note(`${label}: pointer rests at ${JSON.stringify(rest)} (overlap ${Math.round(top)}–${Math.round(bottom)} px below the card top)`)
				await page.mouse.move(rest.x, rest.y)
				// From here every change of the card, and every frame for 900 ms after the press, is
				// recorded: was the second question ever visible (also fading out) before it was uncovered?
				await page.evaluate((firstText) => {
					const log = window.__personaFlow = []
					window.__personaFlowArm = () => {
						const end = performance.now() + 900
						const frame = () => { look(); if (performance.now() < end) requestAnimationFrame(frame) }
						requestAnimationFrame(frame)
					}
					const look = () => {
						const c = document.querySelector('.training-active .question-card')
						const q = c?.querySelector('.qconceal')
						const text = (c?.querySelector('.question-text')?.textContent || '').trim()
						if (!q || !text || text === firstText) return
						const content = q.querySelector('.qconceal__content')
						log.push({ hidden: q.classList.contains('qconceal--hidden'), opacity: content ? Number(getComputedStyle(content).opacity) : 1 })
					}
					window.__personaFlowObserver = new MutationObserver(look)
					window.__personaFlowObserver.observe(document.querySelector('.training-active') || document.body, { subtree: true, childList: true, attributes: true, characterData: true })
				}, first)
				if (!(await card().locator('.next-btn').first().evaluate((el) => el === document.activeElement))) throw new Error(`${label}: "Next Question" lost the focus`)
				await page.evaluate(() => window.__personaFlowArm())
				await page.keyboard.press('Enter')
				await card().locator('.question-text').filter({ hasNotText: first }).first().waitFor({ state: 'attached', timeout: 15000 })
				await page.waitForTimeout(1000)
				const second = await questionText()
				if (second !== order[1]) throw new Error(`after "Next Question" Training shows "${second}"`)
				const flash = await page.evaluate(() => (window.__personaFlow || []).find((s) => s.opacity > 0.05) || null)
				await page.evaluate(() => window.__personaFlowObserver?.disconnect())
				if (flash) rec.add('media', `${label}: the second question shows before its intro (${JSON.stringify(flash)})`)
				if (!(await isHidden())) { rec.add('media', `${label}: the second question is not held hidden for its intro`); return }
				if (played) {
					const still = await page.evaluate(() => [...document.querySelectorAll('.training-active audio')].filter((el) => !el.paused).length)
					if (still) rec.add('media', `${label}: the first question's sound plays on under the second (${still} playing)`)
				}
				const box = await conceal().boundingBox()
				const inside = box && rest.x > box.x && rest.x < box.x + box.width && rest.y > box.y && rest.y < box.y + box.height
				const scrollAfter = await page.evaluate(() => window.scrollY)
				note(`${label}: second hidden area ${JSON.stringify(box)}, scroll ${scrollBefore} → ${scrollAfter}`)
				if (!inside) throw new Error(`${label}: the resting pointer ${JSON.stringify(rest)} is not inside the second hidden question ${JSON.stringify(box)}`)
				// the pointer moves a little where it already is: no mouseenter, only movement inside
				await page.mouse.move(rest.x + 6, rest.y + 4, { steps: 2 })
				await page.waitForTimeout(150)
				if (!(await isHidden())) note(`${label}: uncovered within 150 ms of moving (no rest)`)
				await page.mouse.move(rest.x + 2, rest.y + 6)
				if (!(await waitUntil(async () => !(await isHidden()), 2500, 100))) rec.add('media', `${label}: a pointer resting inside the second hidden question does not uncover it (it never "enters")`)
				await page.mouse.move(0, 0)
			}

			await walk([flow.questions[0], flow.questions[1]])
			await walk([flow.questions[1], flow.questions[0]])
			await page.screenshot({ path: path.join(SHOTS, 'media-question-second.png') })
		} finally {
			await page.setViewportSize({ width: 1400, height: 900 })
		}
	})
	await prefsCall({ autoplay: false, sounds: 'auto' }).catch(() => {})
	flush()
	await context.close()
})

test('instructor: create-course dialog, certification, compliance report, CSV and ZIP', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const rec = new Recorder('instructor')
	const { context, page } = await login(browser, 'instructor')
	rec.attach(page)
	await station(page, rec, 'Create course dialog (cancelled)', async () => {
		await openTopTab(page, 'Courses')
		await page.getByRole('button', { name: /create course/i }).first().click()
		await page.waitForTimeout(600)
		const title = page.locator('[role=dialog] input, .modal-container input').first()
		await title.fill('Annual safety training 2027')
		await page.screenshot({ path: path.join(SHOTS, 'guide-create-course.png') })
		await closeOverlays(page)
	})
	// GitHub #79: the course title is a button; Nextcloud's core button style painted a square
	// block behind it on hover and focus. The card reacts itself, the title stays transparent.
	await station(page, rec, 'Course list: hover and focus on a course title', async () => {
		await openTopTab(page, 'Courses')
		const title = page.locator('.course-card .course-card-open', { hasText: COURSE_TITLE }).first()
		await title.hover()
		await page.waitForTimeout(300)
		const background = () => title.evaluate((el) => getComputedStyle(el).backgroundColor)
		const transparent = (c) => c === 'transparent' || /^rgba\(\d+, \d+, \d+, 0\)$/.test(c)
		const hovered = await background()
		if (!transparent(hovered)) rec.add('hover', `course title has background ${hovered} on hover (square block, #79)`)
		await page.screenshot({ path: path.join(SHOTS, 'course-title-hover.png') })
		await title.focus()
		const focused = await background()
		if (!transparent(focused)) rec.add('hover', `course title has background ${focused} on focus (square block, #79)`)
	})
	// GitHub #79 (reopened): the same title button sits on the Dashboard course cards and on the pool
	// cards of My pools and Pools in my courses; 5.14.0 cleared the core button background only in
	// the course list. Every card title is hovered and focused here; a card that is not there is a
	// finding, not a pass.
	const openDashboard = async () => {
		await openTopTab(page, 'Courses')
		await page.locator('.course-sub-nav [role=tab]').filter({ hasText: 'Dashboard' }).first().click()
		await page.locator('.dashboard-course-card').first().waitFor({ timeout: 15000 })
		await settle(page, 300)
	}
	const titleHasNoBlock = async (title, where) => {
		await title.hover() // throws (station-error) when the card is missing
		await page.waitForTimeout(300)
		const background = () => title.evaluate((el) => getComputedStyle(el).backgroundColor)
		const transparent = (c) => c === 'transparent' || /^rgba\(\d+, \d+, \d+, 0\)$/.test(c)
		const hovered = await background()
		if (!transparent(hovered)) rec.add('hover', `${where}: card title has background ${hovered} on hover (square block, #79)`)
		await title.focus()
		const focused = await background()
		if (!transparent(focused)) rec.add('hover', `${where}: card title has background ${focused} on focus (square block, #79)`)
		await page.mouse.move(0, 0)
	}
	await station(page, rec, 'Dashboard: hover and focus on a course card title', async () => {
		await openDashboard()
		const title = page.locator('.dashboard-course-card .card-open', { hasText: COURSE_TITLE }).first()
		await titleHasNoBlock(title, 'Dashboard')
		await page.screenshot({ path: path.join(SHOTS, 'dashboard-title-hover.png') })
	})
	// GitHub #79 (CSV): the Dashboard's class summary export. Without gamification the game columns
	// (XP, level, streak, badges, duels, swarm) are left out; with it they stay.
	await station(page, rec, 'Dashboard: class summary CSV', async () => {
		await openDashboard()
		const csv = await page.evaluate(async (courseId) => {
			const res = await fetch(OC.generateUrl(`/apps/learning/api/courses/${courseId}/summary/export/csv`), { headers: { requesttoken: OC.requestToken } })
			return { status: res.status, text: await res.text() }
		}, seed.courseId)
		if (csv.status !== 200) {
			rec.add('download', `class summary CSV answered ${csv.status}`)
			return
		}
		const header = csv.text.replace(/^\uFEFF/, '').split('\n')[0]
		const game = ['XP', 'Level', 'Streak', 'Badges', 'Duels won', 'Swarm Contributions'].filter((h) => header.split(',').some((c) => c.replace(/"/g, '') === h))
		if (state.GAMIFICATION !== 'on' && game.length) rec.add('csv', `class summary CSV without gamification still has ${game.join(', ')} (#79)`)
		if (state.GAMIFICATION === 'on' && game.length !== 6) rec.add('csv', `class summary CSV with gamification has only ${game.join(', ') || 'no'} game columns`)
		fs.writeFileSync(path.join(DOWNLOADS, 'class-summary.csv'), csv.text)
	})
	await station(page, rec, 'Pools: hover and focus on a pool card title (My pools, Pools in my courses)', async () => {
		await openTopTab(page, 'Pools')
		const tabs = page.locator('.pool-tabs [role=tab]')
		await tabs.filter({ hasText: 'My Pools' }).first().click()
		await settle(page, 300)
		await titleHasNoBlock(page.locator('.pool-card .pool-card-open', { hasText: 'Fire safety' }).first(), 'My pools')
		const courseTab = tabs.filter({ hasText: 'Pools in my courses' }).first()
		if (!(await courseTab.isVisible().catch(() => false))) {
			rec.add('station-error', 'no "Pools in my courses" tab for the instructor of the seeded course')
			return
		}
		await courseTab.click()
		await settle(page, 300)
		await titleHasNoBlock(page.locator('.course-pool-card .pool-card-open').first(), 'Pools in my courses')
		await page.screenshot({ path: path.join(SHOTS, 'pool-title-hover.png') })
	})
	// GitHub #79 (18.11. 11:19): the pool rows in a course's Pools tab carry the same title button.
	await station(page, rec, 'Learning space › Pools: hover and focus on a pool title', async () => {
		await openMegaTab(page, 'Learning space')
		const poolsTab = page.locator('.lernraum-subnav button').filter({ hasText: /^\s*Pools\s*$/ }).first()
		if (await poolsTab.isVisible().catch(() => false)) await poolsTab.click()
		await settle(page, 300)
		await titleHasNoBlock(page.locator('.pool-item .pool-item-open').first(), 'Course pools')
		await page.screenshot({ path: path.join(SHOTS, 'course-pool-title-hover.png') })
	})
	// GitHub #88: in Pool rules the "Specific question IDs" field was 130px wide (Nextcloud's core
	// textarea width); every field of the dialog must span the same column.
	await station(page, rec, 'Learning space › Pools › Pool rules: field widths', async () => {
		await openMegaTab(page, 'Learning space')
		const poolsTab = page.locator('.lernraum-subnav button').filter({ hasText: /^\s*Pools\s*$/ }).first()
		if (await poolsTab.isVisible().catch(() => false)) await poolsTab.click()
		await settle(page, 300)
		await page.locator('.pool-rules-btn').first().click()
		await page.locator('#pool-rule-question-ids').waitFor({ timeout: 10000 })
		await page.waitForTimeout(300)
		// On NC 33 the core 130px rule hits the selects too, so "as wide as the others" alone would
		// pass with all three at 130px: each field must also fill its .rules-field column.
		const sizes = await page.evaluate(() => Object.fromEntries(['pool-rule-question-ids', 'pool-rule-exam', 'pool-rule-chapter'].map((id) => {
			const el = document.getElementById(id)
			const column = el?.closest('.rules-field')
			return [id, { w: Math.round(el?.getBoundingClientRect().width || 0), column: Math.round(column?.getBoundingClientRect().width || 0) }]
		})))
		const widths = Object.fromEntries(Object.entries(sizes).map(([id, s]) => [id, s.w]))
		const reference = Math.max(widths['pool-rule-exam'], widths['pool-rule-chapter'])
		for (const [id, { w, column }] of Object.entries(sizes)) {
			if (!w) rec.add('layout', `Pool rules: #${id} not rendered`)
			else if (Math.abs(w - reference) > 2) rec.add('layout', `Pool rules: #${id} is ${w}px wide, the widest field ${reference}px (#88)`)
			else if (w < column - 2) rec.add('layout', `Pool rules: #${id} is ${w}px wide in a ${column}px column (#88)`)
		}
		rec.current.widths = widths
		await page.screenshot({ path: path.join(SHOTS, 'pool-rules-fields.png') })
		await closeOverlays(page)
	})
	// GitHub #75: the "Required" column in the instructor's Learning materials. Seeded: two of the
	// three files required. The station reads that back, then ticks the third through the column
	// (the write path) and takes it back with the confirmation accepted; both must survive a reload.
	const mats = seed.materials
	const enterMaterials = async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Learning materials' }).first().click()
		await settle(page)
	}
	const docRow = (file) => page.locator('.material-table tbody tr').filter({ hasText: file }).first()
	const requiredBox = (file) => docRow(file).locator('input[type=checkbox][data-test^="required-doc-"]')
	const isTicked = (file) => requiredBox(file).isChecked().catch(() => null)
	await station(page, rec, 'Learning materials (instructor): Required column', async () => {
		await enterMaterials()
		if (!(await page.locator('[data-test="required-all"]').isVisible().catch(() => false))) rec.add('materials', 'no "Required" head checkbox in the file list')
		for (const file of mats.required) if ((await isTicked(file)) !== true) rec.add('materials', `"${file}" is required but its Required box is not ticked`)
		for (const file of mats.optional) if ((await isTicked(file)) !== false) rec.add('materials', `"${file}" is optional but its Required box is ${await isTicked(file)}`)
		// @nextcloud/vue 9 puts the attributes of a checkbox/radio switch on its <input>
		const gate = await page.locator('input[data-test="required-content-gate"]').first().isChecked().catch(() => null)
		if (gate !== mats.examGated) rec.add('materials', `"Exam only after all required content is completed" is ${gate}, the course has ${mats.examGated}`)
		await page.screenshot({ path: path.join(SHOTS, 'guide-instructor-materials.png'), fullPage: true })
		await axeStates(page, rec, page.locator('[data-test="required-all"]'), 'the "Required" head checkbox')
		await axeStates(page, rec, docRow(mats.required[0]).locator('.reconfirm-btn'), '"Ask everyone to confirm again"')
	})
	await station(page, rec, 'Learning materials (instructor): tick and untick an optional file', async () => {
		const file = mats.optional[0]
		await enterMaterials()
		await requiredBox(file).click()
		await page.waitForTimeout(800)
		await enterMaterials()
		if ((await isTicked(file)) !== true) rec.add('materials', `"${file}" ticked as required, but not ticked after a reload`)
		page.__acceptDialogs = true // "… becomes optional … Continue?"
		await requiredBox(file).click()
		await page.waitForTimeout(800)
		page.__acceptDialogs = false
		await enterMaterials()
		if ((await isTicked(file)) !== false) rec.add('materials', `"${file}" made optional again, but still ticked after a reload`)
	})
	await station(page, rec, 'Administration › Course rules › Certification', async () => {
		// "Certificate design" asks for its logo choice after it mounted (CertLayoutSettings#loadLogos).
		// The logo check below waits for that answer, not for a fixed time: under load (the C9 run,
		// parallel to Integration and Lifecycle) it judged the list before the answer was in.
		const logosAnswer = page.waitForResponse(
			(r) => r.request().method() === 'GET' && /\/apps\/learning\/api\/courses\/\d+\/cert-layout\/logos(\?|$)/.test(r.url()),
			{ timeout: 30000 },
		).catch(() => null)
		await openMegaTab(page, 'Administration')
		const heading = page.getByText('Certification', { exact: true }).first()
		await heading.scrollIntoViewIfNeeded()
		await page.waitForTimeout(400)
		await page.screenshot({ path: path.join(SHOTS, 'guide-certification.png') })
		await page.screenshot({ path: path.join(SHOTS, 'guide-course-rules-full.png'), fullPage: true })
		// GitHub #73: in "Certificate design" every label stands above its input, and the input
		// takes the width of its field like the other course rules.
		const fields = await page.locator('.cert-layout').evaluate((root) => [...root.querySelectorAll('label[for]')].map((label) => {
			const control = document.getElementById(label.htmlFor)
			if (!control) return { label: label.textContent.trim(), missing: true }
			// radios and switches (logo choice, #78) have their label beside them by design
			if (control.matches('input[type=checkbox], input[type=radio]')) return null
			const l = label.getBoundingClientRect()
			// a slider shares its row with the value it shows (#93 "Background strength"): the row counts
			const c = (control.matches('input[type=range]') ? control.parentElement : control).getBoundingClientRect()
			const field = label.parentElement.getBoundingClientRect()
			return { label: label.textContent.trim(), above: l.bottom <= c.top + 1, width: c.width, fieldWidth: field.width }
		}).filter(Boolean))
		if (!fields.length) rec.add('layout', 'no labelled field in "Certificate design" (.cert-layout)')
		for (const f of fields) {
			if (f.missing) rec.add('layout', `"${f.label}": the label points to no input`)
			else if (!f.above) rec.add('layout', `"${f.label}": the input stands beside its label, not below it (#73)`)
			else if (f.width < Math.min(f.fieldWidth, 640) * 0.9) rec.add('layout', `"${f.label}": the input is ${Math.round(f.width)} px wide in a ${Math.round(f.fieldWidth)} px field (#73)`)
		}
		// GitHub #72: the image limit is visible before anything is picked, next to the pick buttons.
		const limit = page.locator('.cert-layout-signoff').getByText(/1 MB/).first()
		if (!(await limit.isVisible().catch(() => false))) rec.add('layout', '"Stamp and signature" does not show the 1 MB limit (#72)')
		const described = await page.locator('[data-test="cert-layout-pick-stamp"]').getAttribute('aria-describedby').catch(() => null)
		if (!described || !described.includes('error')) rec.add('a11y', `stamp pick button: aria-describedby="${described}" names no error region (#72)`)
		// GitHub #77/#78: the seeded organisation name and library logo are what the form shows
		const issuer = await page.locator('[data-test="cert-layout-issuer-name"]').inputValue().catch(() => null)
		if (issuer !== seed.certificate.issuerName) rec.add('certificate-design', `"Organisation name on the certificate" shows "${issuer}", the course has "${seed.certificate.issuerName}"`)
		// @nextcloud/vue 9 puts the attributes of a radio switch on its <input>
		const libraryRadio = page.locator(`input[data-test="cert-layout-logo-${seed.certificate.logoId}"]`)
		const libraryOption = page.locator('[data-test="cert-layout-logo"] .checkbox-radio-switch').filter({ hasText: seed.certificate.logoName })
		if (!(await logosAnswer)) rec.add('certificate-design', '"Certificate design" never asked for its logos (no answer from cert-layout/logos within 30 s)')
		// the answer is in; give Vue the render of the list
		await libraryRadio.waitFor({ state: 'attached', timeout: 5000 }).catch(() => {})
		if (!(await libraryRadio.count())) rec.add('certificate-design', `the library logo "${seed.certificate.logoName}" is not offered in "Certificate design"`)
		else if (!(await libraryRadio.isChecked().catch(() => false))) rec.add('certificate-design', `the library logo "${seed.certificate.logoName}" the course uses is not the selected one`)
		await page.locator('[data-test="cert-layout-org"]').scrollIntoViewIfNeeded().catch(() => {})
		await page.screenshot({ path: path.join(SHOTS, 'guide-certificate-design-org.png') })
		await axeStates(page, rec, libraryOption, 'library logo choice', libraryRadio)
		await axeStates(page, rec, page.locator('[data-test="cert-layout-issuer-name"]'), '"Organisation name on the certificate"')
	})
	const enterSummary = async () => {
		await openMegaTab(page, 'Participants')
		const subs = await subTabLabels(page)
		for (const sub of subs) {
			await clickSubTab(page, sub)
			if (await page.locator('.cert-report-section').isVisible().catch(() => false)) return
		}
		throw new Error(`no compliance report in any Participants sub-tab (${subs.map((s) => s.label).join(', ')})`)
	}
	await station(page, rec, 'Compliance report', async () => {
		await enterSummary()
		await page.locator('.cert-report-section').scrollIntoViewIfNeeded()
		await page.screenshot({ path: path.join(SHOTS, 'guide-compliance-report.png') })
		const text = await page.locator('.cert-report-section').textContent()
		if (!text.includes('Learner 1')) rec.add('compliance', 'the report does not list Learner 1, who passed')
	})
	await station(page, rec, 'Compliance report: CSV', async () => {
		const dl = await download(page, () => page.locator('.cert-report-header-actions').getByRole('button', { name: /csv/i }).click(), 'compliance')
		const csv = fs.readFileSync(dl.file, 'utf8')
		if (csv.trim().split('\n').length < 2) rec.add('download', `compliance CSV has no data rows (${dl.size} bytes)`)
		if (!/learner1|Learner 1/.test(csv)) rec.add('download', 'compliance CSV does not contain Learner 1')
	})
	await station(page, rec, 'Compliance report: ZIP of certificates', async () => {
		const dl = await download(page, () => page.locator('.cert-report-zip').click(), 'certificates')
		if (dl.head.subarray(0, 2).toString('latin1') !== 'PK') rec.add('download', `certificate ZIP is not a ZIP (${dl.suggested}, ${dl.size} bytes)`)
	})
	flush()
	await context.close()
})

// GitHub #78 and #59 on the administrator's settings page: the certificate logo library (seeded
// with the course's logo) and who is told of technical tickets (chosen here, through the picker).
test('admin: certificate logo library, ticket recipients, the notice of a technical ticket', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const rec = new Recorder('admin')
	const { context, page } = await login(browser, 'admin')
	rec.attach(page)
	const enterAdmin = async () => { await page.goto(`${BASE}/index.php/settings/admin/learning`); await settle(page, 1200) }
	const cert = seed.certificate
	const library = () => page.locator('[data-test="cert-logo-library"]')
	await station(page, rec, 'Admin settings › Logos for certificates', async () => {
		await enterAdmin()
		await library().scrollIntoViewIfNeeded()
		const entry = page.locator(`[data-test="cert-logo-${cert.logoId}"]`)
		if (!(await entry.isVisible().catch(() => false))) rec.add('logo-library', `the seeded logo "${cert.logoName}" is not in the library`)
		const usage = (await page.locator(`[data-test="cert-logo-usage-${cert.logoId}"]`).textContent().catch(() => '')).trim()
		if (!/\b1\b/.test(usage)) rec.add('logo-library', `the logo the course uses says "${usage}" (expected: used by 1 course)`)
		await page.screenshot({ path: path.join(SHOTS, 'guide-admin-logo-library.png') })
		await axeStates(page, rec, page.locator('[data-test="cert-logo-pick"]'), '"Upload logo …"')
	})
	await station(page, rec, REFUSED_LOGO_STATION, async () => {
		await enterAdmin()
		await library().scrollIntoViewIfNeeded()
		const before = await library().locator('[data-test^="cert-logo-name-"]').count()
		await page.locator('[data-test="cert-logo-new-name"]').fill('Refused vector logo')
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'
		const since = Date.now()
		await page.locator('[data-test="cert-logo-file"]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: Buffer.from(svg) })
		await page.waitForTimeout(1500)
		await checkToasts(page, rec, since, 'upload an SVG named .png')
		const error = (await page.locator('[data-test="cert-logo-new-error"]').textContent().catch(() => '')).trim()
		if (!error) rec.add('logo-library', 'an SVG named logo.png was not refused next to the file field')
		const after = await library().locator('[data-test^="cert-logo-name-"]').count()
		if (after !== before) rec.add('logo-library', `the library had ${before} logos and has ${after} after a refused upload`)
		await page.screenshot({ path: path.join(SHOTS, 'admin-logo-refused.png') })
	})
	// ZC9: a phone photo as logo. A 30 × 10 JPEG (red corner top left) with an EXIF segment that says
	// "rotate 90°" (orientation 6) and carries a GPS position. The library keeps a re-encoded copy:
	// no EXIF, no GPS, the pixels already upright (10 × 30). GD in the Nextcloud container draws it
	// and reads the stored copy back, so the sweep needs no image library of its own.
	await station(page, rec, 'Admin settings › Logos: a photo with EXIF is stored upright, without EXIF and GPS (ZC9)', async () => {
		const php = (code, input) => execFileSync('docker', ['exec', '-i', state.NC, 'php', '-r', code], { input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim()
		const jpeg = Buffer.from(php('$i=imagecreatetruecolor(30,10);imagefilledrectangle($i,0,0,29,9,imagecolorallocate($i,20,40,220));imagefilledrectangle($i,0,0,5,5,imagecolorallocate($i,230,20,20));ob_start();imagejpeg($i,null,95);echo base64_encode(ob_get_clean());', ''), 'base64')
		const u16 = (n) => Buffer.from([n >> 8, n & 0xff])
		const u32 = (n) => Buffer.from([n >>> 24, (n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff])
		const tiff = Buffer.concat([Buffer.from('MM'), u16(42), u32(8), u16(1), u16(0x0112), u16(3), u32(1), u16(6), u16(0), u32(0), Buffer.from('GPSLatitude=52.5200N;GPSLongitude=13.4050E;Camera=PersonaPhone')])
		const payload = Buffer.concat([Buffer.from('Exif\0\0', 'binary'), tiff])
		const upload = Buffer.concat([jpeg.subarray(0, 2), Buffer.from([0xff, 0xe1]), u16(payload.length + 2), payload, jpeg.subarray(2)])
		const NAME = 'Phone photo logo'
		const adminApi = (method, p) => fetch(`${APP}/api/settings/admin/cert-logos${p}`, { method, headers: { Authorization: basic('admin'), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
		await enterAdmin()
		await library().scrollIntoViewIfNeeded()
		await page.locator('[data-test="cert-logo-new-name"]').fill(NAME)
		const since = Date.now()
		await page.locator('[data-test="cert-logo-file"]').setInputFiles({ name: 'IMG_0001.jpg', mimeType: 'image/jpeg', buffer: upload })
		await page.waitForTimeout(1500)
		await checkToasts(page, rec, since, 'upload a JPEG photo with EXIF')
		const error = (await page.locator('[data-test="cert-logo-new-error"]').textContent().catch(() => '')).trim()
		if (error) { rec.add('logo-library', `the photo with EXIF was refused: "${error}"`); return }
		const list = await (await adminApi('GET', '')).json().catch(() => null)
		const entry = (list?.ocs?.data?.logos || list?.logos || []).find((l) => l.name === NAME)
		if (!entry) { rec.add('logo-library', `the uploaded photo "${NAME}" is not in the library`); return }
		try {
			const res = await adminApi('GET', `/${entry.id}/image`)
			const stored = Buffer.from(await res.arrayBuffer())
			if (!res.ok) { rec.add('logo-library', `the stored photo answers ${res.status}`); return }
			if (stored.includes(Buffer.from('Exif')) || stored.includes(Buffer.from('GPSLatitude')) || stored.includes(Buffer.from('PersonaPhone'))) rec.add('logo-library', 'the stored logo still carries the EXIF segment or the GPS position (ZC9)')
			const facts = JSON.parse(php('$b=stream_get_contents(STDIN);$i=@imagecreatefromstring($b);if(!$i){echo "{}";exit;}$c=imagecolorat($i,7,2);echo json_encode(["w"=>imagesx($i),"h"=>imagesy($i),"r"=>($c>>16)&255,"b"=>$c&255]);', stored) || '{}')
			;(rec.current.notes ||= []).push(`upload ${upload.length} B, stored ${stored.length} B ${res.headers.get('content-type')}, ${JSON.stringify(facts)}`)
			if (!facts.w) rec.add('logo-library', 'the stored logo is no readable image')
			else if (facts.w !== 10 || facts.h !== 30) rec.add('logo-library', `the stored logo is ${facts.w} × ${facts.h}, not upright (10 × 30): the EXIF rotation was not applied (ZC9)`)
			else if (!(facts.r > 150 && facts.b < 110)) rec.add('logo-library', 'the stored logo is upright, but the red corner is not where the photo shows it (ZC9)')
			// what the administrator sees: the thumbnail stands upright too
			const thumb = page.locator(`[data-test="cert-logo-${entry.id}"] img`).first()
			await thumb.scrollIntoViewIfNeeded().catch(() => {})
			await waitUntil(() => thumb.evaluate((img) => img.complete && img.naturalWidth > 0).catch(() => false), 6000)
			const natural = await thumb.evaluate((img) => (img.complete ? { w: img.naturalWidth, h: img.naturalHeight } : null)).catch(() => null)
			if (!natural || !natural.w) rec.add('logo-library', 'the thumbnail of the uploaded photo does not load')
			else if (natural.w >= natural.h) rec.add('logo-library', `the thumbnail of the uploaded photo lies on its side (${natural.w} × ${natural.h})`)
			await page.screenshot({ path: path.join(SHOTS, 'admin-logo-photo-exif.png') })
		} finally {
			await adminApi('DELETE', `/${entry.id}`).catch(() => {})
		}
	})
	const recipients = () => page.locator('.ticket-recipients')
	await station(page, rec, 'Admin settings › Ticket recipients: choose an administrator', async () => {
		await enterAdmin()
		await recipients().scrollIntoViewIfNeeded()
		if (!(await page.locator('.ticket-recipients-none').isVisible().catch(() => false))) rec.add('tickets', 'nobody is chosen yet, but there is no "nobody is notified" note')
		const search = recipients().locator('input').first()
		await search.click()
		await search.fill('admin')
		const option = page.locator('.vs__dropdown-option, [role=option]').filter({ hasText: /admin/i }).first()
		await option.waitFor({ timeout: 10000 })
		await option.click()
		const since = Date.now()
		await recipients().getByRole('button', { name: 'Save recipients' }).click()
		await page.waitForTimeout(1000)
		await checkToasts(page, rec, since, 'Save recipients')
		if (!(await recipients().getByText('Recipients saved.').isVisible().catch(() => false))) rec.add('tickets', 'no "Recipients saved." after saving')
		await enterAdmin() // read back
		await recipients().scrollIntoViewIfNeeded()
		if (await page.locator('.ticket-recipients-none').isVisible().catch(() => false)) rec.add('tickets', 'after saving and reloading, the page still says nobody is notified')
		const chosen = (await recipients().textContent().catch(() => '')).replace(/\s+/g, ' ')
		if (!/admin/i.test(chosen)) rec.add('tickets', 'after a reload the picker does not show the chosen administrator')
		await page.screenshot({ path: path.join(SHOTS, 'guide-admin-ticket-recipients.png') })
		await axeStates(page, rec, recipients().getByRole('button', { name: 'Save recipients' }), '"Save recipients"')
	})
	await station(page, rec, 'Technical ticket from a learner: the chosen administrator is notified', async () => {
		const subject = 'The material link does not open'
		const res = await fetch(`${APP}/api/support-tickets`, {
			method: 'POST',
			headers: { Authorization: 'Basic ' + Buffer.from(`learner2:${PASSWORDS.learner2}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify({ subject, message: 'Clicking the evacuation plan shows nothing on my laptop.', category: 'technical', context: { courseId: seed.courseId } }),
		})
		if (!res.ok) throw new Error(`learner2 could not file a technical ticket: ${res.status}`)
		const list = await page.request.get(`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`, { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })
		if (!list.ok()) rec.add('tickets', `the administrator's notifications could not be read: ${list.status()}`)
		else {
			const items = ((await list.json())?.ocs?.data || []).filter((n) => n.app === 'learning')
			if (!items.length) rec.add('tickets', 'the chosen administrator got no notification of the new technical ticket')
			else (rec.current.notes ||= []).push(`notification: ${items[0].subject}`)
		}
		await enterAdmin()
		const card = page.locator('.ticket-card').filter({ hasText: subject }).first()
		if (!(await card.isVisible().catch(() => false))) rec.add('tickets', `the ticket "${subject}" is not in the admin inbox`)
		else {
			await card.scrollIntoViewIfNeeded()
			if (!(await card.locator('.ticket-manage button').count())) rec.add('tickets', 'the ticket has no archive / delete buttons')
		}
		await page.screenshot({ path: path.join(SHOTS, 'guide-admin-ticket-inbox.png') })
	})
	flush()
	await context.close()
})

// GitHub #82: links that outlived their target, for every role. Each station opens the link
// directly (as a notification, a search result or a bookmark would) and checks: a notice is there,
// screen readers get it (role="alert"), it never names the course, and its button leads on. A blank
// page, an uncaught error or a 500 is a finding through the station's own checks.
const DEAD = DEAD_LINK_STATION_PREFIX
async function expectDeadLinkNotice(page, rec, { title, button, landsOn }) {
	const box = page.locator('[data-test="dead-link"]')
	if (!(await box.count())) {
		rec.add('dead-link', `no notice for a dead link (expected "${title}")`)
		return
	}
	if ((await box.first().getAttribute('role')) !== 'alert') rec.add('dead-link', 'the notice is not announced (role="alert" missing)')
	const text = (await box.first().innerText()).replace(/\s+/g, ' ')
	if (!text.includes(title)) rec.add('dead-link', `notice says "${text.slice(0, 160)}", expected "${title}"`)
	for (const name of ['Deleted course', 'Instructors only', 'Safety training 2025']) {
		if (text.includes(name)) rec.add('dead-link', `the notice names the course "${name}"`)
	}
	// the notice itself gets the page checks (German, empty area, axe); the station's own checks
	// afterwards see where its button led
	await checkPage(page, rec).catch((e) => rec.add('station-error', 'page check on the notice failed: ' + e.message))
	await shoot(page, rec, rec.current.station + ' notice')
	await box.first().getByRole('button', { name: button }).click()
	await settle(page)
	if (!landsOn.test(new URL(page.url()).pathname + new URL(page.url()).search)) rec.add('dead-link', `"${button}" led to ${shortUrl(page.url())}`)
	if (await page.locator('[data-test="dead-link"]').count()) rec.add('dead-link', `the notice is still there after "${button}"`)
}

for (const [persona, opts] of [['learner1', {}], ['instructor', {}], ['admin', {}], ['instructor-studentview', { studentView: true }]]) {
	test(`dead links: ${persona}`, async ({ browser }) => {
		test.setTimeout(10 * 60_000)
		const d = seed.deadLinks
		const rec = new Recorder(persona, opts)
		const { context, page } = await login(browser, persona, opts)
		rec.attach(page)
		const user = persona.replace(/-.*/, '')
		const back = { title: 'This course is not available', button: 'Back to my courses', landsOn: /\/courses$/ }

		await station(page, rec, `${DEAD}deleted course (certificate notification)`, async () => {
			await page.goto(`${APP}/courses/${d.deletedCourseId}/summary`)
			await settle(page)
			await expectDeadLinkNotice(page, rec, back)
		})
		await station(page, rec, `${DEAD}course id that never existed`, async () => {
			await page.goto(`${APP}/courses/abc`)
			await settle(page)
			await expectDeadLinkNotice(page, rec, back)
		})
		if (user !== seed.poolOwner) {
			await station(page, rec, `${DEAD}course without access`, async () => {
				await page.goto(`${APP}/courses/${d.privateCourseId}`)
				await settle(page)
				await expectDeadLinkNotice(page, rec, back)
			})
		}
		if (user === 'learner1' || user === seed.poolOwner) {
			await station(page, rec, `${DEAD}archived course`, async () => {
				await page.goto(`${APP}/courses/${d.archivedCourseId}`)
				await settle(page)
				const note = page.locator('[data-test="course-archived"]')
				if (!(await note.count())) rec.add('dead-link', 'an archived course does not say it is archived')
				else if (!/This course is archived/.test(await note.first().innerText())) rec.add('dead-link', `archive note: "${(await note.first().innerText()).slice(0, 160)}"`)
				if (await page.locator('[data-test="dead-link"]').count()) rec.add('dead-link', 'an archived course is shown as unavailable')
			})
		}
		await station(page, rec, `${DEAD}pool that never existed (search result)`, async () => {
			await page.goto(`${APP}/pools?poolId=${d.unknownPoolId}`)
			await settle(page)
			await expectDeadLinkNotice(page, rec, { title: 'This pool is not available', button: 'Back to the pools', landsOn: /\/pools$/ })
		})
		// review #82: a pool id that is no number named no pool and showed the plain list
		await station(page, rec, `${DEAD}pool id that is no number`, async () => {
			await page.goto(`${APP}/pools?poolId=abc`)
			await settle(page)
			await expectDeadLinkNotice(page, rec, { title: 'This pool is not available', button: 'Back to the pools', landsOn: /\/pools$/ })
		})
		// review #82: an address the app does not know went to the start view without a word. The
		// server hands the app every path under /courses/ (appinfo/routes.php); other top-level paths
		// get Nextcloud's own 404 page and never reach the app.
		await station(page, rec, `${DEAD}address the app does not know`, async () => {
			await page.goto(`${APP}/courses/${seed.courseId}/summary/old`)
			await settle(page)
			const note = page.locator('[data-test="unknown-link"]')
			if (!(await note.count())) rec.add('dead-link', 'an unknown address opens the start view without a note')
			else {
				if ((await note.first().getAttribute('role')) !== 'status') rec.add('dead-link', 'the unknown-address note is not announced (role="status" missing)')
				if (!/This address does not exist in Learning/.test(await note.first().innerText())) rec.add('dead-link', `unknown-address note: "${(await note.first().innerText()).slice(0, 160)}"`)
			}
			if (!/\/(dashboard|courses)$/.test(new URL(page.url()).pathname)) rec.add('dead-link', `an unknown address led to ${shortUrl(page.url())}, not to the start view`)
		})
		// review #82: the instructors' requests (ticket_created notification) for someone who is no
		// instructor there (a learner, the student view) switched to the feed without a word
		if (user !== 'admin') {
			await station(page, rec, `${DEAD}course area this persona does not have (requests)`, async () => {
				await page.goto(`${APP}/courses/${seed.courseId}/requests`)
				await settle(page)
				const note = page.locator('[data-test="tab-unavailable"]')
				const instructorView = user === seed.poolOwner && !opts.studentView
				if (instructorView) {
					if (await note.count()) rec.add('dead-link', 'the instructor\'s own requests tab is reported as not available')
				} else if (!(await note.count())) rec.add('dead-link', 'a course area this persona does not have opens another one without a note')
				else if ((await note.first().getAttribute('role')) !== 'status') rec.add('dead-link', 'the course-area note is not announced (role="status" missing)')
			})
		}
		await station(page, rec, `${DEAD}adventure link (gamification ${seed.gamification ? 'on' : 'off'})`, async () => {
			await page.goto(`${APP}/?learningView=abenteuer`)
			await settle(page)
			if (seed.gamification) {
				if (await page.locator('[data-test="dead-link"]').count()) rec.add('dead-link', 'the adventure link is refused although gamification is on')
			} else {
				await expectDeadLinkNotice(page, rec, { title: 'The adventure is switched off here', button: 'Back to the pools', landsOn: /\/pools$/ })
			}
		})
		// The focus tags on the campaign cards showed the raw ids of the campaign files
		// (network_fundamentals, incident-response); now readable labels in the reader's language.
		if (seed.gamification) {
			await station(page, rec, 'Adventure: the focus tags are readable labels', async () => {
				await page.goto(`${APP}/?learningView=abenteuer`)
				await settle(page)
				const tags = page.locator('.ab-focus-tag')
				if (!(await tags.first().isVisible({ timeout: 15000 }).catch(() => false))) { rec.add('adventure', 'no campaign card with focus tags is shown'); return }
				const texts = [...new Set((await tags.allTextContents()).map((s) => s.trim()))]
				;(rec.current.notes ||= []).push(`focus tags: ${texts.join(' | ')}`)
				for (const text of texts) {
					if (/_/.test(text) || /^[a-z]+(-[a-z]+)+$/.test(text)) rec.add('adventure', `a focus tag is shown as its raw id: "${text}"`)
				}
				// the English interface: every tag is an English catalogue value (the server's campaign list
				// decides which tags appear, so no particular one is expected)
				const english = new Set(Object.values(JSON.parse(fs.readFileSync(path.join(__dirname, '../../app/l10n/en.json'), 'utf8')).translations))
				const notEnglish = texts.filter((text) => !english.has(text))
				if (notEnglish.length) rec.add('german', `focus tags that are no English label: ${notEnglish.join(' | ')}`)
			})
		}
		flush()
		await context.close()
	})
}

// GitHub #91: the public verification page, opened from outside as the QR code would (no session).
// Nextcloud's public layout clips #content to the window, and the theming background showed through
// fixed text colours (blue on blue, white on white). Checked: the page scrolls to its last element
// on a phone and on a desktop, its container is opaque, and axe finds no contrast problem in light
// and dark, each with a dark and a light custom primary colour (plain theming background in that
// colour, the reporter's case). Dark and primary are proven to have reached the page, so a theme
// that silently stayed the same cannot pass. Theming is restored afterwards.
test('public: certificate verification page, light and dark, dark and light primary colour', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const rec = new Recorder('public')
	const occ = (...args) => execFileSync('docker', ['exec', '-u', 'www-data', '-w', '/var/www/html', state.NC, 'php', 'occ', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
	const theming = (key) => { try { return occ('config:app:get', 'theming', key) } catch { return null } }
	const before = { primary_color: theming('primary_color'), background_color: theming('background_color'), backgroundMime: theming('backgroundMime') }
	let verifyUrl = ''
	try { verifyUrl = fs.readFileSync(VERIFY_URL_FILE, 'utf8').trim() } catch { /* reported below */ }
	const unknownUrl = `${APP}/verify/00000000-0000-4000-8000-000000000000`
	const pages = [['certificate', verifyUrl], ['unknown id', unknownUrl]]
	const variants = [
		{ scheme: 'light', primary: '#0b2545' },
		{ scheme: 'dark', primary: '#0b2545' },
		{ scheme: 'light', primary: '#ffd60a' },
		{ scheme: 'dark', primary: '#ffd60a' },
	]
	const seen = { main: {}, primary: {} }
	try {
		for (const v of variants) {
			occ('theming:config', 'primary_color', v.primary)
			occ('theming:config', 'background', 'backgroundColor')
			occ('theming:config', 'background_color', v.primary)
			const context = await browser.newContext({ locale: 'en-GB', colorScheme: v.scheme, viewport: { width: 1400, height: 900 } })
			const page = await context.newPage()
			rec.attach(page)
			for (const [what, url] of pages) {
				await station(page, rec, `Public verification (${what}): ${v.scheme}, primary ${v.primary}`, async () => {
					if (!url) {
						rec.add('station-error', 'no verification address from the learner\'s certificate (learner test did not get one)')
						return
					}
					const res = await page.goto(url.startsWith('http') ? url : `${BASE}${url}`)
					if (!res || res.status() !== 200) rec.add('http', `verification page answered ${res ? res.status() : 'nothing'}`)
					await settle(page)
					const look = await page.evaluate(() => {
						const css = getComputedStyle(document.body)
						const box = document.querySelector('.lrn-verify-page')
						return {
							main: css.getPropertyValue('--color-main-background').trim(),
							primary: css.getPropertyValue('--color-primary-element').trim(),
							container: box ? getComputedStyle(box).backgroundColor : null,
						}
					})
					;(seen.main[v.scheme] ||= new Set()).add(look.main)
					;(seen.primary[v.primary] ||= new Set()).add(look.primary)
					if (!look.container) rec.add('layout', 'no .lrn-verify-page container')
					else if (look.container === 'transparent' || /rgba\(\d+, \d+, \d+, (0|0?\.\d+)\)/.test(look.container)) rec.add('contrast', `page container is not opaque (${look.container}): the theming background shows through (#91)`)
					// Scroll to the end on a phone and on a desktop: the last element must be reachable
					// and not covered by the fixed public footer.
					for (const vp of [{ width: 390, height: 700 }, { width: 1400, height: 900 }]) {
						await page.setViewportSize(vp)
						await page.waitForTimeout(200)
						const end = await page.evaluate(() => {
							const last = document.querySelector('.lrn-verify__how summary')
							if (!last) return { missing: true }
							// as a reader would: every ancestor that can scroll goes to its end
							for (let el = last.parentElement; el; el = el.parentElement) {
								if (/(auto|scroll)/.test(getComputedStyle(el).overflowY)) el.scrollTop = el.scrollHeight
							}
							window.scrollTo(0, document.documentElement.scrollHeight)
							const r = last.getBoundingClientRect()
							const hit = document.elementFromPoint(r.left + Math.min(20, r.width / 2), r.top + r.height / 2)
							return { bottom: Math.round(r.bottom), inner: window.innerHeight, reachable: !!hit && last.contains(hit) }
						})
						if (end.missing) rec.add('layout', 'verification page without its "How does this verification work?" section')
						else if (!end.reachable) rec.add('scroll', `${vp.width}×${vp.height}: the last section of the page cannot be reached by scrolling (bottom ${end.bottom}px, window ${end.inner}px) (#91)`)
						if (vp.width === 390) await page.screenshot({ path: path.join(SHOTS, `verify-${slug(what)}-${v.scheme}-${v.primary.slice(1)}-phone-end.png`) })
						await page.evaluate(() => {
							for (const el of document.querySelectorAll('*')) if (el.scrollTop) el.scrollTop = 0
							window.scrollTo(0, 0)
						})
					}
				})
			}
			flush()
			await context.close()
		}
		// Not a page station: the proof that the variants above really differed.
		if (!ONLY || ONLY.test('Public verification')) {
			rec.begin('Public verification: dark mode and primary colour reached the page')
			const [light] = [...(seen.main.light || [])]
			const [dark] = [...(seen.main.dark || [])]
			if (!light || !dark || light === dark) rec.add('detector', `dark mode did not reach the public page (main background light "${light}", dark "${dark}")`)
			const [p1] = [...(seen.primary[variants[0].primary] || [])]
			const [p2] = [...(seen.primary[variants[2].primary] || [])]
			if (!p1 || !p2 || p1 === p2) rec.add('detector', `the custom primary colour did not reach the public page ("${p1}" / "${p2}")`)
			rec.current.seen = { light, dark, primaryDark: p1, primaryLight: p2 }
		}
	} finally {
		for (const [key, value] of Object.entries(before)) {
			if (key === 'backgroundMime') {
				try { occ('theming:config', '--reset', 'background') } catch { /* already default */ }
				if (value) occ('config:app:set', 'theming', 'backgroundMime', '--value', value)
				continue
			}
			if (value) occ('theming:config', key, value)
			else { try { occ('theming:config', '--reset', key) } catch { /* not set */ } }
		}
	}
	flush()
})

// ---------------------------------------------------------------- 5.16.0 scenarios (C9)
// They run after the public verification test (it reads learner1's valid certificate) and before
// the generic sweeps (those then see the course as it is afterwards). Each persona gets its own
// browser context, so the instructor's and the learner's views alternate as in real life.
const basic = (user) => 'Basic ' + Buffer.from(`${user}:${PASSWORDS[user]}`).toString('base64')
const occRun = (...args) => execFileSync('docker', ['exec', '-u', 'www-data', '-w', '/var/www/html', state.NC, 'php', 'occ', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()

/** The Learning notifications of a user (Node fetch, so the page under test does not see it). */
async function learningNotifications(user) {
	const res = await fetch(`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`, { headers: { Authorization: basic(user), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
	if (!res.ok) return null
	return ((await res.json())?.ocs?.data || []).filter((n) => n.app === 'learning')
}

/** Check that exactly `count` (or at least one, count = null) notifications match, and in English. */
async function expectNotification(rec, user, pattern, what, count = null) {
	const items = await learningNotifications(user)
	if (!items) { rec.add('notification', `the notifications of ${user} could not be read`); return }
	const hits = items.filter((n) => pattern.test(n.subject || ''))
	if (!hits.length) rec.add('notification', `${user} got no notification ${what} (${items.map((n) => n.subject).join(' | ').slice(0, 200) || 'none'})`)
	else if (count !== null && hits.length !== count) rec.add('notification', `${user} got ${hits.length} notifications ${what}, expected ${count}`)
	for (const n of hits) if (GERMAN.test(`${n.subject} ${n.message || ''}`)) rec.add('german', `notification: ${n.subject} ${n.message || ''}`.slice(0, 200))
	;(rec.current.notes ||= []).push(...hits.map((n) => `notification for ${user}: ${n.subject}`))
}

async function enterLearningMaterials(page) {
	await openMegaTab(page, 'Learning space')
	await page.locator('.lernraum-subnav button').filter({ hasText: 'Learning materials' }).first().click()
	await settle(page)
}

async function enterExamTab(page) {
	await openMegaTab(page, 'Learning space')
	await page.locator('.lernraum-subnav button').filter({ hasText: 'Exam' }).first().click()
	await settle(page)
}

async function enterMembers(page) {
	await page.goto(`${APP}/courses/${seed.courseId}/members`)
	await settle(page)
	await page.locator('.member-item').first().waitFor({ timeout: 15000 })
	await settle(page, 300)
}
// GitHub #87 (review): the row shows the display name; the uid is the secondary text when the two differ.
const memberRow = (page, uid) => page.locator('.member-item').filter({ has: page.locator('.member-name, .member-uid', { hasText: new RegExp(`^${uid}$`) }) }).first()
const memberBadge = async (page, uid) => (await memberRow(page, uid).locator('[data-test="member-cycle-status"]').textContent().catch(() => '')).trim()

/** GitHub #84 / #87: the instructor opens a new cycle from the member list (confirm accepted). */
async function openNewCycle(page, rec, uid) {
	const button = memberRow(page, uid).locator('[data-test="member-new-cycle"]')
	if (!(await button.isVisible().catch(() => false))) {
		rec.add('cycle', `${uid}: no "Open a new certification cycle" in the member list`)
		return
	}
	const since = Date.now()
	page.__acceptDialogs = true
	try {
		await button.click()
		if (!(await waitUntil(async () => (await memberBadge(page, uid)) === 'New cycle open', 8000))) rec.add('cycle', `${uid}: after "Open a new certification cycle" the badge reads "${await memberBadge(page, uid)}", not "New cycle open"`)
		await checkToasts(page, rec, since, 'Open a new certification cycle')
	} finally {
		page.__acceptDialogs = false
	}
	await enterMembers(page) // read back
	if ((await memberBadge(page, uid)) !== 'New cycle open') rec.add('cycle', `${uid}: after a reload the badge reads "${await memberBadge(page, uid)}", not "New cycle open"`)
	if (await memberRow(page, uid).locator('[data-test="member-new-cycle"]').isVisible().catch(() => false)) rec.add('cycle', `${uid}: "Open a new certification cycle" is still offered with a cycle open`)
}

// ---------------------------------------------------------------- 5.17.0: certificate presentation (C10)
// GitHub #93 (backgrounds), #94 (PDF made in the browser), #95 (JSON credential behind "Technical
// details", hideable per course). Runs after the public verification test and before the C9
// scenarios (learner1's certificate is revoked there). The seed gave the course the dark library
// background "Night grid" at full strength before learner1 passed, so learner1's certificate carries
// it: the worst case for the text and the QR code. The PDF is one picture (no text layer), so "it
// holds the ID" is proven by the QR code: it must decode to exactly the card's verification address,
// and that address ends in the card's certificate ID. The presentation-copy line is proven by ink in
// the bottom margin (as app/tests/print/certificate-pdf.spec.cjs); its words are not read (no OCR).
// WebKit is not clicked here: scripts/pdf-gate.sh runs the PDF in WebKit at phone width.
const PDF_TMP = () => fs.mkdtempSync(path.join(process.env.TMPDIR || require('os').tmpdir(), 'persona-pdf-'))
const { PNG } = require('pngjs')

/** A w × h RGBA PNG drawn by pixel(x, y) → [r, g, b, a] (no image files in the sweep). */
function makeTestPng(w, h, pixel) {
	const zlib = require('zlib')
	const table = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
	const crc = (buf) => { let c = 0xffffffff; for (const x of buf) c = table[(c ^ x) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0 }
	const chunk = (type, data) => {
		const len = Buffer.alloc(4); len.writeUInt32BE(data.length)
		const td = Buffer.concat([Buffer.from(type, 'ascii'), data])
		const c = Buffer.alloc(4); c.writeUInt32BE(crc(td))
		return Buffer.concat([len, td, c])
	}
	const ihdr = Buffer.alloc(13)
	ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 6
	const raw = Buffer.alloc((w * 4 + 1) * h)
	for (let y = 0; y < h; y++) {
		for (let x = 0; x < w; x++) {
			const o = y * (w * 4 + 1) + 1 + x * 4
			const [r, g, b, a] = pixel(x, y)
			raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; raw[o + 3] = a
		}
	}
	return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}

/** QR codes zbarimg reads in a PNG file ([] when none). */
function qrCodes(pngFile) {
	try {
		return execFileSync('zbarimg', ['-q', '--raw', pngFile], { encoding: 'utf8' }).trim().split('\n').filter(Boolean)
	} catch (e) {
		if (e.code === 'ENOENT') throw new Error('zbarimg is not installed (zbar-tools): the QR code cannot be checked', { cause: e })
		return [] // exit 4: no code found
	}
}

/** Pixels close to a colour in a decoded PNG. */
function pixelsNear(png, [r, g, b], tolerance = 40) {
	let count = 0
	for (let i = 0; i < png.data.length; i += 4) {
		if (Math.abs(png.data[i] - r) < tolerance && Math.abs(png.data[i + 1] - g) < tolerance && Math.abs(png.data[i + 2] - b) < tolerance) count++
	}
	return count
}

/** Non-white pixels between two heights (fractions of the page). */
function inkCount(png, top, bottom) {
	let count = 0
	for (let y = Math.floor(top * png.height); y < Math.floor(bottom * png.height); y++) {
		for (let x = 0; x < png.width; x++) {
			const i = (png.width * y + x) << 2
			if (png.data[i] < 200 || png.data[i + 1] < 200 || png.data[i + 2] < 200) count++
		}
	}
	return count
}

/** One PDF file: pages, size, pictures, QR codes and the raster at 200 dpi. */
function pdfFacts(file) {
	const dir = PDF_TMP()
	try {
		const info = execFileSync('pdfinfo', [file], { encoding: 'utf8' })
		const size = info.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/) || []
		const images = execFileSync('pdfimages', ['-list', file], { encoding: 'utf8' }).trim().split('\n').slice(2).map((l) => l.trim().split(/\s+/))
		execFileSync('pdftoppm', ['-r', '200', '-png', '-singlefile', file, path.join(dir, 'page')])
		const pagePng = path.join(dir, 'page.png')
		return {
			pages: Number((info.match(/Pages:\s+(\d+)/) || [])[1]),
			widthPt: Math.round(Number(size[1])), heightPt: Math.round(Number(size[2])),
			images, qr: qrCodes(pagePng), png: PNG.sync.read(fs.readFileSync(pagePng)),
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

/** The QR codes in a screenshot of one element (on screen, with whatever lies behind it). */
async function qrOnScreen(locator) {
	const dir = PDF_TMP()
	try {
		const file = path.join(dir, 'qr.png')
		await locator.screenshot({ path: file })
		return qrCodes(file)
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

/**
 * GitHub #93: axe files text over a background picture as "incomplete" (it cannot know the
 * picture), so its silence proves nothing here. This measures it: every text on the card against
 * what is really behind it — the plate (88 % white) laid over the darkest and the lightest colour
 * of the background picture, or the picture itself where the text is outside the plate. The lower
 * of the two ratios counts; 4.5:1, or 3:1 for large text (WCAG 1.4.3).
 */
async function cardContrast(page, cardSelector) {
	return page.evaluate(async (cardSelector) => {
		const card = [...document.querySelectorAll(cardSelector)].find((el) => el.getClientRects().length)
		if (!card) return { error: `no visible ${cardSelector}` }
		const rgba = (s) => { const m = String(s).match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1] }
		const over = (top, below) => [0, 1, 2].map((i) => top[i] * top[3] + below[i] * (1 - top[3]))
		const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]) }
		const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05) }
		// the darkest and lightest colour of the background picture (or of the built-in's layer)
		let extremes = null
		const img = card.querySelector('.certificate-bg-image')
		if (img) {
			if (!img.complete || !img.naturalWidth) await img.decode().catch(() => {})
			if (!img.naturalWidth) return { error: 'the background picture did not load' }
			const canvas = document.createElement('canvas')
			canvas.width = 160; canvas.height = Math.max(1, Math.round(160 * img.naturalHeight / img.naturalWidth))
			const ctx = canvas.getContext('2d')
			ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
			const d = ctx.getImageData(0, 0, canvas.width, canvas.height).data
			let dark = null; let light = null
			for (let i = 0; i < d.length; i += 4) {
				const c = [d[i], d[i + 1], d[i + 2]]
				if (!dark || lum(c) < lum(dark)) dark = c
				if (!light || lum(c) > lum(light)) light = c
			}
			extremes = [dark, light]
		}
		const veil = card.querySelector('.certificate-bg-veil')
		const veilOpacity = veil ? Number(getComputedStyle(veil).opacity) : 0
		const cardBg = rgba(getComputedStyle(card).backgroundColor) || [255, 255, 255, 1]
		const ground = extremes
			? extremes.map((c) => (veilOpacity ? over([255, 255, 255, veilOpacity], c) : c))
			: [over(cardBg, [255, 255, 255])]
		const plateEl = card.querySelector('.certificate-bg-plate')
		const plateColour = plateEl ? rgba(getComputedStyle(plateEl).backgroundColor) : null
		const plateRect = plateEl ? plateEl.getBoundingClientRect() : null
		const failures = []
		let min = Infinity; let measured = 0
		const walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT)
		let node
		while ((node = walker.nextNode())) {
			if (!node.textContent.trim()) continue
			const el = node.parentElement
			if (!el || el.closest('[aria-hidden="true"]') || !el.getClientRects().length) continue
			const s = getComputedStyle(el)
			if (s.visibility === 'hidden' || Number(s.opacity) < 0.05) continue
			const r = el.getBoundingClientRect()
			const onPlate = plateRect && r.left >= plateRect.left - 1 && r.right <= plateRect.right + 1 && r.top >= plateRect.top - 1 && r.bottom <= plateRect.bottom + 1
			// what is behind the text: its own opaque box if it has one, else plate or picture
			let behind = ground
			for (let a = el; a && a !== card; a = a.parentElement) {
				const bg = rgba(getComputedStyle(a).backgroundColor)
				if (bg && bg[3] >= 0.99) { behind = [bg]; break }
			}
			if (behind === ground && onPlate && plateColour) behind = ground.map((g) => over(plateColour, g))
			const fg = rgba(s.color)
			const worst = Math.min(...behind.map((b) => ratio(fg[3] < 1 ? over(fg, b) : fg, b)))
			const size = parseFloat(s.fontSize); const bold = Number(s.fontWeight) >= 700
			const need = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5
			measured++
			min = Math.min(min, worst)
			if (worst < need) failures.push(`"${node.textContent.trim().slice(0, 40)}" ${worst.toFixed(2)}:1 (needs ${need}:1, ${s.color}${onPlate ? ' on the plate' : ' over the background'})`)
		}
		return { measured, min: Number.isFinite(min) ? Math.round(min * 100) / 100 : null, failures: failures.slice(0, 8), picture: !!img, plate: plateColour, extremes }
	}, cardSelector)
}

/** Open learner1's own certificate from Completion › "View certificate" (a dialog). */
async function openOwnCertificate(page) {
	await openMegaTab(page, 'Completion')
	await page.locator('.zeugnis-cert-action').first().click()
	await page.locator('.certificate-printable .certificate-card').first().waitFor({ timeout: 15000 })
	await page.evaluate(() => Promise.all([document.fonts.ready, ...Array.from(document.images).map((img) => img.decode().catch(() => {}))]))
	await settle(page, 400)
}

/** The card's verification address and certificate ID as shown. */
async function cardIdentity(page) {
	const text = async (sel) => (await page.locator(`.certificate-printable ${sel}`).first().textContent().catch(() => '')).trim()
	return { url: await text('.certificate-verify-url'), id: await text('.certificate-verify-id') }
}

/** GitHub #93: the background layer of the card is there and is the picture the course chose. */
async function expectBackground(page, rec, where) {
	const layer = page.locator('.certificate-printable [data-test="certificate-background"]').first()
	if (!(await layer.count())) { rec.add('background', `${where}: the certificate shows no background, the course chose "${seed.certificate.backgroundName}"`); return null }
	if ((await layer.getAttribute('aria-hidden')) !== 'true') rec.add('a11y', `${where}: the background layer is not aria-hidden (it is decoration)`)
	const img = page.locator('.certificate-printable [data-test="certificate-background-image"]').first()
	if (!(await img.count())) { rec.add('background', `${where}: the background is not the library picture`); return null }
	const loaded = await img.evaluate((el) => el.complete && el.naturalWidth > 0)
	if (!loaded) rec.add('background', `${where}: the background picture did not load`)
	return img.getAttribute('src')
}

/** GitHub #93: contrast of the card and a scannable QR code, plus a self-test of the measurement. */
async function expectReadableCard(page, rec, where) {
	const res = await cardContrast(page, '.certificate-printable .certificate-card')
	if (res.error) { rec.add('contrast', `${where}: ${res.error}`); return }
	if (!res.picture) rec.add('background', `${where}: the contrast was measured without a background picture`)
	if (res.measured < 6) rec.add('detector', `${where}: only ${res.measured} texts measured on the card`)
	for (const f of res.failures) rec.add('contrast', `${where}: ${f}`)
	;(rec.current.notes ||= []).push(`${where}: ${res.measured} texts, lowest ${res.min}:1, plate ${JSON.stringify(res.plate)}, picture extremes ${JSON.stringify(res.extremes)}`)
	// self-test: without the plate the dark picture must make the measurement fail
	await page.evaluate(() => document.querySelectorAll('.certificate-printable .certificate-bg-plate').forEach((p) => { p.dataset.personaPlate = p.style.background; p.style.background = 'transparent' }))
	const bare = await cardContrast(page, '.certificate-printable .certificate-card')
	await page.evaluate(() => document.querySelectorAll('.certificate-printable .certificate-bg-plate').forEach((p) => { p.style.background = p.dataset.personaPlate || ''; delete p.dataset.personaPlate }))
	if (!bare.failures?.length) rec.add('detector', `${where}: CONTRAST MEASUREMENT BROKEN: without the plate nothing failed (lowest ${bare.min}:1)`)
	const { url } = await cardIdentity(page)
	const qr = await qrOnScreen(page.locator('.certificate-printable .certificate-qr').first())
	if (qr.length !== 1 || qr[0] !== url) rec.add('qr', `${where}: the QR code on the card decodes to ${JSON.stringify(qr)}, the card says ${url}`)
}

/** GitHub #94: click "Download as PDF" and check the file: one A4 landscape page, its QR, its ID, the note. */
async function expectCertificatePdf(page, rec, where, name) {
	const button = page.locator('[data-test="certificate-pdf-button"]').first()
	if (!(await button.isVisible().catch(() => false))) { rec.add('pdf', `${where}: no "Download as PDF"`); return }
	const label = (await button.textContent()).trim()
	if (label !== 'Download as PDF') rec.add('pdf', `${where}: the PDF button reads "${label}"`)
	const box = await button.boundingBox()
	const vp = page.viewportSize()
	if (box && (box.x < 0 || box.x + box.width > vp.width + 1)) rec.add('layout', `${where}: "Download as PDF" sticks out of the ${vp.width} px screen (x ${Math.round(box.x)}, width ${Math.round(box.width)})`)
	const { url, id } = await cardIdentity(page)
	const dl = await download(page, () => button.click(), name)
	const status = page.locator('[data-test="certificate-pdf-status"]').first()
	await waitUntil(async () => (await status.textContent()).trim() === 'PDF downloaded', 10000)
	const said = (await status.textContent().catch(() => '')).trim()
	if (said !== 'PDF downloaded') rec.add('pdf', `${where}: after the download the status says "${said}"`)
	if (await page.locator('[data-test="certificate-pdf-error"]').count()) rec.add('pdf', `${where}: an error is shown after a successful PDF: ${(await page.locator('[data-test="certificate-pdf-error"]').first().textContent()).trim()}`)
	if ((await button.getAttribute('aria-busy')) !== 'false') rec.add('a11y', `${where}: the PDF button stays aria-busy after the download`)
	if (await page.locator('#learning-certificate-pdf-root').count()) rec.add('pdf', `${where}: the hidden sheet the PDF was drawn from is still in the page`)
	if (!/\.pdf$/i.test(dl.suggested)) rec.add('pdf', `${where}: the file is called "${dl.suggested}", not *.pdf`)
	if (dl.head.subarray(0, 5).toString('latin1') !== '%PDF-') { rec.add('pdf', `${where}: "${dl.suggested}" is not a PDF (starts with ${JSON.stringify(dl.head.subarray(0, 8).toString('latin1'))})`); return }
	const m = pdfFacts(dl.file)
	if (m.pages !== 1) rec.add('pdf', `${where}: the PDF has ${m.pages} pages`)
	if (m.widthPt !== 842 || m.heightPt !== 595) rec.add('pdf', `${where}: the PDF page is ${m.widthPt} × ${m.heightPt} pt, not A4 landscape (842 × 595)`)
	if (m.images.length !== 1 || Number(m.images[0][3]) < 2000) rec.add('pdf', `${where}: the PDF holds ${m.images.length} pictures (${m.images.map((i) => `${i[3]}×${i[4]}`).join(', ')}), expected one ≥ 2000 px wide`)
	if (m.qr.length !== 1 || m.qr[0] !== url) rec.add('pdf', `${where}: the QR code in the PDF decodes to ${JSON.stringify(m.qr)}, the card says ${url}`)
	if (!id || !url.endsWith(`/verify/${id}`)) rec.add('pdf', `${where}: the verification address ${url} does not end in the certificate ID "${id}"`)
	// the presentation-copy line sits in the bottom margin, below the card
	const note = inkCount(m.png, 0.955, 0.995)
	if (note < 200) rec.add('pdf', `${where}: no presentation-copy line in the bottom margin of the PDF (${note} ink pixels)`)
	// the dark library background is in the PDF too, not only on screen (navy of the seed picture)
	const navy = pixelsNear(m.png, [0x0b, 0x18, 0x30], 30)
	if (navy < 20000) rec.add('pdf', `${where}: the PDF does not show the certificate's background (${navy} background pixels)`)
	;(rec.current.notes ||= []).push(`${where}: ${dl.suggested}, ${dl.size} bytes, ${m.widthPt}×${m.heightPt} pt, picture ${m.images[0]?.[3]}×${m.images[0]?.[4]}, QR ${m.qr[0]}, note ink ${note}, background pixels ${navy}`)
}

test('certificate presentation: backgrounds, PDF, technical details (#93, #94, #95)', async ({ browser }) => {
	test.setTimeout(15 * 60_000)
	const cert = seed.certificate
	// ------------------------------------------------ admin: the background library (#93)
	const admin = await login(browser, 'admin')
	const adminRec = new Recorder('admin')
	adminRec.attach(admin.page)
	const ap = admin.page
	const enterAdmin = async () => {
		await ap.goto(`${BASE}/index.php/settings/admin/learning`)
		await settle(ap, 1200)
		await ap.locator('[data-test="cert-background-library"]').scrollIntoViewIfNeeded()
	}
	const entries = () => ap.locator('[data-test="cert-background-library"] [data-test^="cert-background-name-"]')
	const usageIds = () => ap.locator('[data-test^="cert-background-usage-"]').evaluateAll((list) => list.map((el) => el.dataset.test.replace('cert-background-usage-', '')))
	await station(ap, adminRec, 'Admin settings › Backgrounds for certificates (#93)', async () => {
		await enterAdmin()
		if (!(await ap.locator(`[data-test="cert-background-${cert.backgroundId}"]`).isVisible().catch(() => false))) adminRec.add('background-library', `the seeded background "${cert.backgroundName}" is not in the library`)
		const usage = (await ap.locator(`[data-test="cert-background-usage-${cert.backgroundId}"]`).textContent().catch(() => '')).trim()
		if (usage !== 'Used by 1 course') adminRec.add('background-library', `the background the course uses says "${usage}" (expected "Used by 1 course")`)
		await ap.screenshot({ path: path.join(SHOTS, 'guide-admin-background-library.png') })
		await axeStates(ap, adminRec, ap.locator('[data-test="cert-background-pick"]'), '"Upload background …"')
	})
	await station(ap, adminRec, 'Admin settings › Backgrounds: upload a PNG, then delete it while unused (#93)', async () => {
		await enterAdmin()
		const before = await entries().count()
		const idsBefore = await usageIds()
		await ap.locator('[data-test="cert-background-new-name"]').fill('Soft paper')
		const since = Date.now()
		await ap.locator('[data-test="cert-background-file"]').setInputFiles({ name: 'soft-paper.png', mimeType: 'image/png', buffer: makeTestPng(800, 566, (x, y) => [0xf4 - ((x + y) % 40 < 2 ? 30 : 0), 0xee, 0xe0, 255]) })
		await waitUntil(async () => (await entries().count()) === before + 1, 10000)
		await checkToasts(ap, adminRec, since, 'upload a background')
		if ((await entries().count()) !== before + 1) { adminRec.add('background-library', `the library had ${before} backgrounds and has ${await entries().count()} after uploading a PNG`); return }
		const status = (await ap.locator('[data-test="cert-background-library-status"]').textContent()).trim()
		if (!/Soft paper/.test(status)) adminRec.add('background-library', `after the upload the status says "${status}"`)
		const newId = (await usageIds()).find((id) => !idsBefore.includes(id)) || ''
		const usage = (await ap.locator(`[data-test="cert-background-usage-${newId}"]`).textContent().catch(() => '')).trim()
		if (usage !== 'Used by no course') adminRec.add('background-library', `a new background says "${usage}" (expected "Used by no course")`)
		await enterAdmin() // read back
		if ((await entries().count()) !== before + 1) adminRec.add('background-library', 'the uploaded background is gone after a reload')
		await ap.locator(`[data-test="cert-background-delete-${newId}"]`).click()
		const warn = (await ap.locator(`[data-test="cert-background-confirm-${newId}"]`).textContent().catch(() => '')).replace(/\s+/g, ' ').trim()
		if (!/removed from the library/i.test(warn)) adminRec.add('background-library', `the delete dialog of an unused background says "${warn.slice(0, 160)}"`)
		const delSince = Date.now()
		await ap.locator(`[data-test="cert-background-confirm-delete-${newId}"]`).click()
		await waitUntil(async () => (await entries().count()) === before, 8000)
		await checkToasts(ap, adminRec, delSince, 'delete an unused background')
		await enterAdmin()
		if ((await entries().count()) !== before) adminRec.add('background-library', `after deleting the unused background the library has ${await entries().count()} entries, expected ${before}`)
	})
	await station(ap, adminRec, REFUSED_BACKGROUND_STATION, async () => {
		await enterAdmin()
		const before = await entries().count()
		const svg = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>')
		const tries = [
			{ what: 'an SVG', file: { name: 'frame.svg', mimeType: 'image/svg+xml', buffer: svg }, says: /SVG is not accepted/i },
			{ what: 'an SVG named .png', file: { name: 'frame.png', mimeType: 'image/png', buffer: svg }, says: /SVG is not accepted/i },
			{ what: 'a PNG 4100 px wide', file: { name: 'wide.png', mimeType: 'image/png', buffer: makeTestPng(4100, 20, () => [0x20, 0x40, 0x80, 255]) }, says: /4000 pixels per side/i },
		]
		for (const t of tries) {
			await ap.locator('[data-test="cert-background-new-name"]').fill(`Refused ${t.what}`)
			const since = Date.now()
			await ap.locator('[data-test="cert-background-file"]').setInputFiles(t.file)
			const error = ap.locator('[data-test="cert-background-new-error"]')
			await waitUntil(async () => (await error.textContent()).trim().length > 0, 8000)
			await checkToasts(ap, adminRec, since, `upload ${t.what}`)
			const said = (await error.textContent().catch(() => '')).replace(/\s+/g, ' ').trim()
			if (!said) adminRec.add('background-library', `${t.what} was not refused next to the file field`)
			else if (!t.says.test(said)) adminRec.add('background-library', `${t.what} is refused with "${said.slice(0, 160)}", which does not explain why`)
			;(adminRec.current.notes ||= []).push(`${t.what}: ${said}`)
			if ((await entries().count()) !== before) adminRec.add('background-library', `the library had ${before} backgrounds and has ${await entries().count()} after refusing ${t.what}`)
		}
		await ap.screenshot({ path: path.join(SHOTS, 'admin-background-refused.png') })
	})
	await station(ap, adminRec, 'Admin settings › Backgrounds: deleting one in use names the courses first (#93)', async () => {
		await enterAdmin()
		const before = await entries().count()
		let deletes = 0
		const count = (r) => { if (r.request().method() === 'DELETE' && /cert-backgrounds\//.test(r.url())) deletes++ }
		ap.on('response', count)
		await ap.locator(`[data-test="cert-background-delete-${cert.backgroundId}"]`).click()
		const box = ap.locator(`[data-test="cert-background-confirm-${cert.backgroundId}"]`)
		const warn = (await box.textContent().catch(() => '')).replace(/\s+/g, ' ').trim()
		if (!/Used in 1 course/.test(warn)) adminRec.add('background-library', `deleting a background a course uses warns "${warn.slice(0, 160)}" (expected it to name 1 course)`)
		await ap.screenshot({ path: path.join(SHOTS, 'guide-admin-background-delete-in-use.png') })
		await axeStates(ap, adminRec, box.getByRole('button', { name: 'Cancel' }), '"Cancel" (delete a background)')
		await box.getByRole('button', { name: 'Cancel' }).click()
		await ap.waitForTimeout(400)
		ap.off('response', count)
		if (await box.count()) adminRec.add('background-library', '"Cancel" does not close the delete dialog')
		if (deletes) adminRec.add('background-library', `"Cancel" sent ${deletes} DELETE requests`)
		await enterAdmin()
		if ((await entries().count()) !== before) adminRec.add('background-library', `after "Cancel" the library has ${await entries().count()} entries, expected ${before}`)
	})
	flush()
	await admin.context.close()

	// ------------------------------------------------ instructor: the course's background and the JSON switch
	const inst = await login(browser, 'instructor')
	const instRec = new Recorder('instructor')
	instRec.attach(inst.page)
	const ip = inst.page
	const layoutNow = async () => (await ip.request.get(`${APP}/api/courses/${seed.courseId}/cert-layout/backgrounds`, { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })).json()
	const enterDesign = async () => {
		await openMegaTab(ip, 'Administration')
		await ip.locator('[data-test="cert-layout-background"]').scrollIntoViewIfNeeded()
		await settle(ip, 300)
	}
	// the clickable label is the text, not the whole row (NcCheckboxRadioSwitch: a click right of the text does nothing)
	const bgOption = (label) => ip.locator('[data-test="cert-layout-background"] .checkbox-radio-switch').filter({ hasText: label }).first().locator('.checkbox-content')
	const putBackground = () => ip.waitForResponse((r) => r.request().method() === 'PUT' && /\/cert-layout\/background$/.test(new URL(r.url()).pathname), { timeout: 15000 })
	await station(ip, instRec, 'Administration › Certificate design: background and strength (#93)', async () => {
		await enterDesign()
		const library = ip.locator(`input[data-test="cert-layout-background-${cert.backgroundId}"]`)
		await library.waitFor({ state: 'attached', timeout: 10000 }).catch(() => {})
		if (!(await library.isChecked().catch(() => false))) instRec.add('certificate-design', `the course's background "${cert.backgroundName}" is not the selected one`)
		const slider = ip.locator('[data-test="cert-layout-background-intensity"]')
		if ((await slider.inputValue().catch(() => null)) !== String(cert.backgroundIntensity)) instRec.add('certificate-design', `the strength shows ${await slider.inputValue().catch(() => 'nothing')}, the course has ${cert.backgroundIntensity}`)
		for (const builtin of ['No background', 'Double frame', 'Corner ornaments', 'Fine line pattern']) {
			if (!(await bgOption(builtin).isVisible().catch(() => false))) instRec.add('certificate-design', `no "${builtin}" among the backgrounds`)
		}
		// a built-in background: saved at once, the preview opens by itself and shows it
		let since = Date.now()
		let answer = putBackground()
		await bgOption('Corner ornaments').click()
		let res = await answer.catch(() => null)
		if (!res || !res.ok()) instRec.add('certificate-design', `choosing "Corner ornaments" was not saved (${res ? res.status() : 'no request'})`)
		await checkToasts(ip, instRec, since, 'choose Corner ornaments')
		const preview = ip.locator('[data-test="cert-layout-preview"]')
		await waitUntil(() => preview.evaluate((el) => el.open), 4000)
		if (!(await preview.evaluate((el) => el.open).catch(() => false))) instRec.add('certificate-design', 'the preview does not open after choosing a background')
		else if (!(await preview.locator('[data-test="certificate-background-corners"]').isVisible().catch(() => false))) instRec.add('certificate-design', 'the preview does not show the corner ornaments')
		await preview.scrollIntoViewIfNeeded().catch(() => {})
		await ip.screenshot({ path: path.join(SHOTS, 'guide-certificate-design-background.png') })
		// the strength: the preview follows while dragging, saved on release
		since = Date.now()
		answer = putBackground()
		await slider.fill('40')
		res = await answer.catch(() => null)
		if (!res || !res.ok()) instRec.add('certificate-design', `strength 40 % was not saved (${res ? res.status() : 'no request'})`)
		else if (res.request().postDataJSON()?.intensity !== 40) instRec.add('certificate-design', `strength 40 % sent ${JSON.stringify(res.request().postDataJSON())}`)
		await checkToasts(ip, instRec, since, 'strength 40 %')
		const shown = (await ip.locator('.cert-layout-intensity-value').textContent().catch(() => '')).trim()
		if (shown !== '40 %') instRec.add('certificate-design', `the strength reads "${shown}" after moving it to 40`)
		let back = await layoutNow()
		if (back.selected !== 'builtin:corners' || back.intensity !== 40) instRec.add('certificate-design', `read back: background ${back.selected}, strength ${back.intensity} (expected builtin:corners, 40)`)
		await enterDesign()
		if (!(await ip.locator('input[data-test="cert-layout-background-corners"]').isChecked().catch(() => false))) instRec.add('certificate-design', '"Corner ornaments" is not selected after a reload')
		await axeStates(ip, instRec, slider, '"Background strength"')
		await axeStates(ip, instRec, bgOption('Corner ornaments'), '"Corner ornaments"', ip.locator('input[data-test="cert-layout-background-corners"]'))
		// back to the seeded library background at full strength (later stations build on it)
		answer = putBackground()
		await bgOption(cert.backgroundName).click()
		await answer.catch(() => null)
		answer = putBackground()
		await ip.locator('[data-test="cert-layout-background-intensity"]').fill(String(cert.backgroundIntensity))
		await answer.catch(() => null)
		back = await layoutNow()
		if (back.selected !== cert.backgroundId || back.intensity !== cert.backgroundIntensity) instRec.add('certificate-design', `could not restore the library background: ${back.selected}, ${back.intensity}`)
	})
	const credentialSwitch = () => ip.locator('[data-test="cert-credential-download"] input[type=checkbox]')
	const setCredential = async (on) => {
		await openMegaTab(ip, 'Administration')
		await credentialSwitch().scrollIntoViewIfNeeded()
		if ((await credentialSwitch().isChecked()) === on) return true
		const since = Date.now()
		const answer = ip.waitForResponse((r) => r.request().method() !== 'GET' && /\/api\/courses\/\d+/.test(r.url()), { timeout: 15000 })
		await credentialSwitch().click()
		const res = await answer.catch(() => null)
		await checkToasts(ip, instRec, since, `credential switch ${on ? 'on' : 'off'}`)
		if (!res || !res.ok()) instRec.add('credential', `turning the JSON credential ${on ? 'on' : 'off'} was not saved (${res ? res.status() : 'no request'})`)
		await openMegaTab(ip, 'Administration') // read back
		await credentialSwitch().scrollIntoViewIfNeeded()
		const now = await credentialSwitch().isChecked()
		if (now !== on) instRec.add('credential', `the JSON credential switch is ${now ? 'on' : 'off'} after a reload, expected ${on ? 'on' : 'off'}`)
		return now === on
	}
	await station(ip, instRec, 'Administration › Course rules: JSON credential for learners off (#95)', async () => {
		await openMegaTab(ip, 'Administration')
		await credentialSwitch().scrollIntoViewIfNeeded()
		if (!(await credentialSwitch().isChecked())) instRec.add('credential', 'the JSON credential switch is off before anyone turned it off (default is on)')
		await setCredential(false)
		await ip.locator('[data-test="cert-credential-download"]').scrollIntoViewIfNeeded()
		await ip.screenshot({ path: path.join(SHOTS, 'guide-course-rules-credential-switch.png') })
		await axeStates(ip, instRec, credentialSwitch(), '"Show learners the digital credential (JSON)"')
	})

	// ------------------------------------------------ learner1: background, contrast, QR, print, PDF; switch off
	const learner = await login(browser, 'learner1')
	const rec = new Recorder('learner1')
	rec.attach(learner.page)
	const page = learner.page
	let backgroundSrc = null
	await station(page, rec, 'Certificate with background: contrast and QR, light (#93)', async () => {
		await openOwnCertificate(page)
		backgroundSrc = await expectBackground(page, rec, 'light')
		await expectReadableCard(page, rec, 'light')
		await page.screenshot({ path: path.join(SHOTS, 'guide-certificate-background.png') })
	})
	await station(page, rec, 'Certificate with background: print (#93)', async () => {
		await openOwnCertificate(page)
		const { url } = await cardIdentity(page)
		await page.locator('.certificate-actions').getByRole('button', { name: 'Print' }).first().click()
		await page.waitForTimeout(600)
		if (!(await page.locator('#learning-certificate-print-root .certificate-bg-image').count())) rec.add('print', 'the print copy has no background picture')
		await page.emulateMedia({ media: 'print' })
		const look = await page.evaluate(() => {
			const layer = document.querySelector('#learning-certificate-print-root .certificate-bg')
			if (!layer) return null
			const s = getComputedStyle(layer)
			const r = layer.getBoundingClientRect()
			return { display: s.display, adjust: s.printColorAdjust || s.webkitPrintColorAdjust, w: r.width, h: r.height }
		})
		if (!look) rec.add('print', 'no background layer in the print copy')
		else {
			if (look.display === 'none' || look.w < 100 || look.h < 100) rec.add('print', `the background layer is not printed (${JSON.stringify(look)})`)
			if (look.adjust !== 'exact') rec.add('print', `the background layer prints with print-color-adjust "${look.adjust}" (browsers drop it unless "exact")`)
		}
		const file = path.join(DOWNLOADS, 'certificate-background-print.pdf')
		await page.pdf({ path: file, preferCSSPageSize: true, printBackground: true })
		await page.emulateMedia({ media: 'screen' })
		const m = pdfFacts(file)
		if (m.pages !== 1) rec.add('print', `the printed certificate has ${m.pages} pages`)
		if (m.widthPt !== 842 || m.heightPt !== 595) rec.add('print', `the printed certificate is ${m.widthPt} × ${m.heightPt} pt, not A4 landscape (842 × 595)`)
		if (m.qr.length !== 1 || m.qr[0] !== url) rec.add('qr', `the QR code on the printed sheet decodes to ${JSON.stringify(m.qr)}, the card says ${url}`)
		const navy = pixelsNear(m.png, [0x0b, 0x18, 0x30], 30)
		if (navy < 20000) rec.add('print', `the printed sheet does not show the background (${navy} background pixels)`)
		;(rec.current.notes ||= []).push(`print: ${m.pages} page(s), ${m.widthPt}×${m.heightPt} pt, QR ${m.qr[0]}, background pixels ${navy}`)
		await page.evaluate(() => { document.getElementById('learning-certificate-print-root')?.remove(); document.documentElement.classList.remove('learning-certificate-printing') })
	})
	await station(page, rec, 'Certificate: download as PDF, desktop (#94)', async () => {
		await openOwnCertificate(page)
		await expectCertificatePdf(page, rec, 'desktop 1400 px', 'learner-certificate-desktop')
	})
	await station(page, rec, 'Certificate: course switch off ⇒ no "Technical details" (#95)', async () => {
		await openOwnCertificate(page)
		if (await page.locator('details.certificate-credential').count()) rec.add('credential', 'the course turned the JSON credential off, but the learner still sees "Technical details"')
		if (await page.getByText('Technical details', { exact: true }).count()) rec.add('credential', '"Technical details" is still on the page with the switch off')
		for (const button of ['Print', 'Download as PDF']) {
			if (!(await page.locator('.certificate-actions').getByRole('button', { name: button }).isVisible().catch(() => false))) rec.add('credential', `with the JSON credential off, "${button}" is gone too`)
		}
		const { url, id } = await cardIdentity(page)
		// the credential stays valid and reachable for the learner by its route (data export), unchanged
		const json = await fetch(`${APP}/api/certificates/${encodeURIComponent(id)}/download`, { headers: { Authorization: basic('learner1'), 'OCS-APIRequest': 'true' } })
		const body = await json.text()
		if (json.status !== 200 || !/^\s*\{/.test(body)) rec.add('credential', `the credential route answers ${json.status} (${body.slice(0, 60)}) with the switch off; the plan keeps it unchanged`)
		// the public verification page is unchanged and still reachable (and shows no background, ZC6)
		const verify = await fetch(url)
		const html = await verify.text()
		if (verify.status !== 200) rec.add('verify', `the verification page answers ${verify.status} with the switch off`)
		if (!html.includes('lrn-verify__banner')) rec.add('verify', 'the verification page has no result banner')
		if (/revoked|expired|not found|unknown/i.test((html.match(/<h1>([^<]*)<\/h1>/) || [])[1] || '')) rec.add('verify', `the verification page says "${(html.match(/<h1>([^<]*)<\/h1>/) || [])[1]}" for a valid certificate`)
		const payload = (backgroundSrc || '').split(',')[1] || ''
		if (payload && html.includes(payload.slice(100, 300))) rec.add('verify', 'the public verification page carries the certificate background (ZC6: not until decided)')
		;(rec.current.notes ||= []).push(`credential route ${json.status}, verification page ${verify.status}: ${(html.match(/<h1>([^<]*)<\/h1>/) || [])[1]}`)
	})
	flush()
	await learner.context.close()

	// learner1 in the dark theme: the card keeps its paper colours, the background and the plate
	const dark = await login(browser, 'learner1', { contextOptions: { colorScheme: 'dark' } })
	const darkRec = new Recorder('learner1-dark')
	darkRec.attach(dark.page)
	await station(dark.page, darkRec, 'Certificate with background: contrast and QR, dark (#93)', async () => {
		await openOwnCertificate(dark.page)
		const main = await dark.page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--color-main-background').trim())
		if (!/^#(1|2)/.test(main) && !/^rgb\((\d|[1-4]\d),/.test(main)) darkRec.add('detector', `the dark theme did not reach the page (main background "${main}")`)
		await expectBackground(dark.page, darkRec, 'dark')
		await expectReadableCard(dark.page, darkRec, 'dark')
		await dark.page.screenshot({ path: path.join(SHOTS, 'guide-certificate-background-dark.png') })
	})
	flush()
	await dark.context.close()

	// learner1 on a phone (Chromium, 390 × 844 at 3×): the card, the QR code and the PDF
	const phone = await login(browser, 'learner1', { contextOptions: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true } })
	const phoneRec = new Recorder('learner1-phone')
	phoneRec.attach(phone.page)
	await station(phone.page, phoneRec, 'Certificate on a phone: background, QR and PDF, 390 px (#93, #94)', async () => {
		await openOwnCertificate(phone.page)
		await expectBackground(phone.page, phoneRec, 'phone')
		await expectReadableCard(phone.page, phoneRec, 'phone')
		const wide = await phone.page.evaluate(() => document.documentElement.scrollWidth)
		if (wide > 391) phoneRec.add('layout', `the page is ${wide} px wide on a 390 px phone (sideways scrolling)`)
		await expectCertificatePdf(phone.page, phoneRec, 'phone 390 px', 'learner-certificate-phone')
		await phone.page.screenshot({ path: path.join(SHOTS, 'guide-certificate-phone.png') })
	})
	flush()
	await phone.context.close()

	// ------------------------------------------------ instructor: their copy keeps "Technical details"; switch back on
	await station(ip, instRec, 'Compliance report: the instructor\'s copy keeps "Technical details" with the switch off (#95)', async () => {
		await openMegaTab(ip, 'Participants')
		for (const sub of await subTabLabels(ip)) {
			await clickSubTab(ip, sub)
			if (await ip.locator('.cert-report-section').isVisible().catch(() => false)) break
		}
		const view = ip.locator('.cert-report-section .cert-view').first()
		if (!(await view.isVisible().catch(() => false))) { instRec.add('credential', 'no "View" for Learner 1\'s certificate in the compliance report'); return }
		await view.click()
		await ip.locator('.certificate-printable .certificate-card').first().waitFor({ timeout: 15000 })
		await settle(ip, 400)
		const details = ip.locator('details.certificate-credential')
		if (!(await details.count())) instRec.add('credential', 'the instructor\'s copy hides "Technical details" when the course turned it off for learners (it must always show)')
		await closeOverlays(ip)
	})
	await station(ip, instRec, 'Administration › Course rules: JSON credential for learners back on (#95)', async () => {
		await setCredential(true)
	})
	flush()
	await inst.context.close()
})

// GitHub #90: material entries whose file was deleted or moved out of the material folder. The
// instructor sees them with the reason and a ×, learners never see them, the × and "Scan folder"
// take them out of the list. Two extra files only; the seed's three files stay as they are.
test('instructor: material entries whose file left the folder (#90)', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const rec = new Recorder('instructor')
	const { context, page } = await login(browser, 'instructor')
	rec.attach(page)
	const learnerRec = new Recorder('learner3')
	const learner = await login(browser, 'learner3')
	learnerRec.attach(learner.page)
	const folder = seed.materials.folder
	const GONE = 'Old fire drill.md'
	const MOVED = 'Old visitor rules.md'
	const davUrl = (p) => `${BASE}/remote.php/dav/files/instructor/${p.split('/').map(encodeURIComponent).join('/')}`
	const dav = async (method, p, { body, headers = {} } = {}) => {
		const res = await fetch(davUrl(p), { method, headers: { Authorization: basic('instructor'), ...headers }, body })
		if (!res.ok) throw new Error(`WebDAV ${method} ${p} → ${res.status}`)
	}
	const row = (file) => page.locator('.material-table tbody tr').filter({ hasText: file }).first()
	const scan = async (action) => {
		const since = Date.now()
		await page.getByRole('button', { name: 'Scan folder' }).first().click()
		await page.waitForTimeout(1500)
		await settle(page)
		await checkToasts(page, rec, since, action)
	}
	await station(page, rec, 'Learning materials (instructor): two more files, scanned', async () => {
		for (const f of [GONE, MOVED]) await dav('PUT', `${folder}/${f}`, { body: `# ${f.replace(/\.md$/, '')}\n\nNo longer valid.\n`, headers: { 'Content-Type': 'text/markdown' } })
		await enterLearningMaterials(page)
		await scan('Scan folder')
		for (const f of [GONE, MOVED]) if (!(await row(f).isVisible().catch(() => false))) rec.add('materials', `"${f}" is not listed after "Scan folder"`)
	})
	await station(page, rec, 'Learning materials (instructor): entries whose file is gone or moved out (#90)', async () => {
		await dav('DELETE', `${folder}/${GONE}`)
		await dav('MOVE', `${folder}/${MOVED}`, { headers: { Destination: davUrl(MOVED), Overwrite: 'T' } })
		await enterLearningMaterials(page)
		for (const f of [GONE, MOVED]) {
			const r = row(f)
			if (!(await r.isVisible().catch(() => false))) { rec.add('materials', `"${f}" left the list before any scan or ×`); continue }
			const reason = (await r.locator('.material-unavailable-reason').textContent().catch(() => '')).trim()
			if (!reason) rec.add('materials', `"${f}": no reason why it cannot be opened (#90)`)
			else if (!/no longer in the material folder/i.test(reason)) rec.add('materials', `"${f}": the reason reads "${reason}" (#90)`)
			if (!(await r.locator('.material-remove-btn').isVisible().catch(() => false))) rec.add('materials', `"${f}": no × to take the entry out of the list (#90)`)
			if (await r.locator('a.material-file-link').count()) rec.add('materials', `"${f}" is still offered as a link although its file left the folder`)
		}
		for (const f of [...seed.materials.required, ...seed.materials.optional]) {
			if (await row(f).locator('.material-remove-btn').count()) rec.add('materials', `"${f}" is in the folder but offered for removal`)
		}
		await page.screenshot({ path: path.join(SHOTS, 'guide-instructor-materials-stale.png'), fullPage: true })
		await axeStates(page, rec, row(MOVED).locator('.material-remove-btn'), 'the × of an entry whose file left the folder')
	})
	await station(learner.page, learnerRec, 'Learning materials (learner): no entry whose file left the folder (#90)', async () => {
		await enterLearningMaterials(learner.page)
		const text = await learner.page.locator('#app-content').innerText()
		for (const f of [GONE, MOVED]) if (text.includes(f)) learnerRec.add('materials', `the learner sees "${f}", whose file left the material folder (#90)`)
		for (const f of seed.materials.optional) if (!text.includes(f)) learnerRec.add('materials', `the learner no longer sees "${f}"`)
	})
	await station(page, rec, 'Learning materials (instructor): the × takes a stale entry out (#90)', async () => {
		await enterLearningMaterials(page)
		const since = Date.now()
		await row(MOVED).locator('.material-remove-btn').click()
		if (!(await waitUntil(async () => !(await row(MOVED).isVisible()), 8000))) rec.add('materials', `"${MOVED}" is still listed after its ×`)
		await checkToasts(page, rec, since, '× (remove from the list)')
		await enterLearningMaterials(page) // read back
		if (await row(MOVED).isVisible().catch(() => false)) rec.add('materials', `"${MOVED}" is back after a reload`)
		if (!(await row(GONE).isVisible().catch(() => false))) rec.add('materials', `the × of "${MOVED}" also took "${GONE}" out`)
	})
	await station(page, rec, 'Learning materials (instructor): "Scan folder" takes the deleted file out (#90)', async () => {
		await enterLearningMaterials(page)
		await scan('Scan folder (cleanup)')
		await enterLearningMaterials(page) // read back
		if (await row(GONE).isVisible().catch(() => false)) rec.add('materials', `"${GONE}" is still listed after "Scan folder" although its file is deleted (#90)`)
		for (const f of [...seed.materials.required, ...seed.materials.optional]) if (!(await row(f).isVisible().catch(() => false))) rec.add('materials', `"Scan folder" took "${f}" out, which is still in the folder`)
	})
	flush()
	await learner.context.close()
	await context.close()
})

// GitHub #87 with #92 and #84: learner3 fails Fire safety until the attempts per pool and cycle are
// used up (3: the mandatory-training profile's default; the IT course gets 3 through the course
// rules for this test and none again afterwards). The first failure is checked with the usual
// cooldown (wait time on the result), the next two with the cooldown at 0 (occ, restored).
test('learner3: exam attempts used up, the instructor opens a new cycle (#87, #84, #92)', async ({ browser }) => {
	test.setTimeout(20 * 60_000)
	const LIMIT = 3
	const instRec = new Recorder('instructor')
	const inst = await login(browser, 'instructor')
	instRec.attach(inst.page)
	const rec = new Recorder('learner3')
	const { context, page } = await login(browser, 'learner3')
	rec.attach(page)
	let cooldownBefore
	try { cooldownBefore = occRun('config:app:get', 'learning', 'exam_attempt_cooldown_minutes') } catch { cooldownBefore = null }
	let cooldownChanged = false
	let limitChanged = false
	const visible = (sel) => page.locator(sel).first().isVisible().catch(() => false)
	const failFireSafety = async () => {
		await enterExamTab(page)
		await page.locator('#app-content').getByText('Fire safety', { exact: true }).first().click()
		await settle(page)
		const { banner, score } = await takeExam(page, { wrong: true })
		if (banner === 'Passed') rec.add('exam', `Fire safety passed with every answer wrong (${score})`)
		return (await page.locator('[data-test="result-attempts"]').textContent().catch(() => '')).replace(/\s+/g, ' ').trim()
	}
	try {
		await station(inst.page, instRec, 'Administration › Course rules: exam attempts per pool and cycle (#87)', async () => {
			const enter = async () => {
				await openMegaTab(inst.page, 'Administration')
				await inst.page.locator('[data-test="exam-attempt-limit"]').scrollIntoViewIfNeeded()
			}
			await enter()
			const field = inst.page.locator('[data-test="exam-attempt-limit"]')
			const shown = await field.inputValue()
			const want = seed.examAttemptLimit ? String(seed.examAttemptLimit) : ''
			if (shown !== want) instRec.add('attempts', `"Exam attempts per pool and certification cycle" shows "${shown}", the course has "${want || 'unlimited'}"`)
			if (!seed.examAttemptLimit) {
				await field.fill(String(LIMIT))
				const since = Date.now()
				await inst.page.locator('[data-test="cert-save"]').click()
				await inst.page.waitForTimeout(1200)
				await checkToasts(inst.page, instRec, since, 'Save (course rules)')
				limitChanged = true
				await enter() // read back
				const again = await field.inputValue()
				if (again !== String(LIMIT)) instRec.add('attempts', `saved ${LIMIT} attempts, after a reload the field shows "${again}"`)
			}
			await inst.page.screenshot({ path: path.join(SHOTS, 'guide-course-rules-attempts.png') })
			await axeStates(inst.page, instRec, field, '"Exam attempts per pool and certification cycle"')
		})
		await station(page, rec, 'Learning materials (learner3): open and confirm the required documents', async () => {
			await enterLearningMaterials(page)
			await openAndConfirmAll(page, rec, 'learner3')
			await enterLearningMaterials(page)
			const open = (await requiredItems(page).locator('[data-test^="document-confirm-"]').allTextContents()).map((x) => x.trim()).filter((x) => x !== 'Confirmed as read')
			if (open.length) rec.add('materials', `learner3: ${open.length} required documents not confirmed after opening and confirming`)
		})
		await station(page, rec, `Exam: Fire safety, every answer wrong (attempt 1 of ${LIMIT}) (#87, #92)`, async () => {
			const line = await failFireSafety()
			if (!/\b1 attempt failed\b/.test(line) || !/\b2 attempts left\b/.test(line)) rec.add('guidance', `after the first failed attempt of ${LIMIT} the result says "${line}" (#87, #92)`)
			if (!(await visible('[data-test="result-wait"]'))) rec.add('guidance', 'no wait time on the result although the cooldown is on (#92)')
			const back = page.locator('[data-test="guide-materials"]')
			if (!(await back.isVisible().catch(() => false))) rec.add('guidance', 'no "Back to the learning materials" after a failed attempt (#92)')
			else if (!(await isPrimary(back))) rec.add('guidance', '"Back to the learning materials" is not the primary button after a failed attempt (#92)')
			const retake = page.locator('[data-test="guide-retake"]')
			if (await retake.isVisible().catch(() => false) && !(await retake.isDisabled().catch(() => true))) rec.add('guidance', '"Retake exam" can be clicked during the wait time (#92)')
			await page.screenshot({ path: path.join(SHOTS, 'guide-exam-failed-wait.png'), fullPage: true })
		})
		await station(page, rec, 'Exam result: "Back to the learning materials" (#92)', async () => {
			await page.locator('[data-test="guide-materials"]').click()
			await settle(page)
			if (!(await requiredItems(page).first().isVisible().catch(() => false))) rec.add('guidance', `"Back to the learning materials" led to ${shortUrl(page.url())} without the required documents (#92)`)
		})
		occRun('config:app:set', 'learning', 'exam_attempt_cooldown_minutes', '--value', '0')
		cooldownChanged = true
		await station(page, rec, `Exam: Fire safety, every answer wrong (attempt 2 of ${LIMIT}) (#87)`, async () => {
			const line = await failFireSafety()
			if (!/\b2 attempts failed\b/.test(line) || !/\b1 attempt left\b/.test(line)) rec.add('guidance', `after the second failed attempt of ${LIMIT} the result says "${line}" (#87, #92)`)
			if (await visible('[data-test="result-wait"]')) rec.add('guidance', 'a wait time on the result although the cooldown is 0')
		})
		await station(page, rec, `Exam: Fire safety, every answer wrong (attempt ${LIMIT} of ${LIMIT}): attempts used up (#87, #92)`, async () => {
			await failFireSafety()
			if (await visible('[data-test="guide-retake"]')) rec.add('guidance', '"Retake exam" after the last attempt (#87)')
			if (await visible('[data-test="result-attempts"]')) rec.add('guidance', 'the result still counts attempts left after the last one (#87)')
			const ask = page.locator('[data-test="guide-ask"]')
			if (!(await ask.isVisible().catch(() => false))) rec.add('guidance', 'no "Question to the instructors" after the last attempt (#92)')
			else if (!(await isPrimary(ask))) rec.add('guidance', '"Question to the instructors" is not the primary button after the last attempt (#92)')
			const text = await page.locator('.results-screen').innerText().catch(() => '')
			if (!/used up all exam attempts/i.test(text)) rec.add('guidance', 'the result does not say that the attempts are used up (#87)')
			await page.screenshot({ path: path.join(SHOTS, 'guide-exam-attempts-used-up.png'), fullPage: true })
		})
		await station(page, rec, 'Exam tab: attempts used up, question to the instructors (#87, #92)', async () => {
			await enterExamTab(page)
			const note = page.locator('[data-test="exam-tab-exhausted"]')
			if (!(await note.isVisible().catch(() => false))) { rec.add('guidance', 'the exam tab does not say the attempts for Fire safety are used up (#87)'); return }
			const said = (await note.innerText()).replace(/\s+/g, ' ')
			if (!said.includes('Fire safety')) rec.add('guidance', `the exhausted note names "${said.slice(0, 160)}", not Fire safety`)
			if (said.includes('Data protection')) rec.add('guidance', 'the exhausted note names Data protection, which has attempts left')
			await page.screenshot({ path: path.join(SHOTS, 'guide-exam-tab-exhausted.png'), fullPage: true })
			const ask = page.locator('[data-test="exam-tab-ask"]')
			if (!(await ask.isVisible().catch(() => false))) { rec.add('guidance', 'no "Question to the instructors" in the exam tab with the attempts used up (#92)'); return }
			await ask.click()
			await settle(page)
			if (!/\/my-questions$/.test(new URL(page.url()).pathname)) rec.add('guidance', `"Question to the instructors" led to ${shortUrl(page.url())} (#92)`)
		})
		// the server refuses a fourth start too, not only the page (Node fetch: the 4xx is the point)
		await station(page, rec, 'Exam start with the attempts used up: refused by the server (#87)', async () => {
			const res = await fetch(`${APP}/api/training/start`, {
				method: 'POST',
				headers: { Authorization: basic('learner3'), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ poolId: seed.poolIds[0], mode: 'exam', courseId: seed.courseId }),
			})
			const body = await res.json().catch(() => null)
			;(rec.current.notes ||= []).push(`server, 4th start: ${res.status} ${JSON.stringify(body).slice(0, 120)}`)
			if (res.ok) rec.add('attempts', `the server started a fourth exam on Fire safety (${res.status}) (#87)`)
			else if (!JSON.stringify(body || {}).includes('exam_attempts_exhausted')) rec.add('attempts', `the server refuses the fourth start with ${res.status} ${JSON.stringify(body).slice(0, 120)}, not exam_attempts_exhausted`)
		})
		// #87 follow-up: "attempts used up" is an activity too, for the course's owner (instructor)
		// and its co-instructor (admin), never for the learner; the learner's name and the course
		// name link to the course's member list.
		const USED_UP = /used up all exam attempts for “Fire safety”/
		const members = `/apps/learning/courses/${seed.courseId}/members`
		const usedUpActivities = async (user) => {
			const res = await fetch(`${BASE}/ocs/v2.php/apps/activity/api/v2/activity/all?format=json&limit=100`, { headers: { Authorization: basic(user), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
			if (res.status === 304) return []
			if (!res.ok) return null
			return ((await res.json())?.ocs?.data || []).filter((a) => a.app === 'learning' && USED_UP.test(a.subject || ''))
		}
		await station(page, rec, 'Activities (learner3): no "attempts used up" for the learner (#87)', async () => {
			const mine = await usedUpActivities('learner3')
			if (mine === null) { rec.add('activity', 'the activity API of learner3 could not be read'); return }
			if (mine.length) rec.add('activity', `learner3 sees ${mine.length} "attempts used up" activities, meant for the instructors only (#87)`)
		})
		await station(inst.page, instRec, 'Activities (instructor, co-instructor): "attempts used up" links the member list (#87)', async () => {
			for (const user of ['instructor', 'admin']) {
				const hits = await usedUpActivities(user)
				if (hits === null) { instRec.add('activity', `the activity API of ${user} could not be read`); continue }
				;(instRec.current.notes ||= []).push(`${user}: ${hits.map((a) => a.subject).join(' | ') || 'none'}`)
				if (hits.length !== 1) { instRec.add('activity', `${user} has ${hits.length} "attempts used up" activities for learner3, expected 1 (#87)`); continue }
				const a = hits[0]
				if (!a.subject.startsWith('Learner 3 ')) instRec.add('activity', `${user}: the activity says "${a.subject}", not the learner's name (#87)`)
				if (GERMAN.test(a.subject)) instRec.add('german', `activity: ${a.subject}`)
				if (/\blearner3\b/.test(JSON.stringify(a.subject_rich || ''))) instRec.add('activity', `${user}: the activity carries the uid learner3 (#87)`)
				const params = Object.values(a.subject_rich?.[1] || {})
				const linked = params.filter((p) => p.link && new URL(p.link).pathname.endsWith(members)).map((p) => p.name)
				if (!linked.includes('Learner 3') || !linked.includes(COURSE_TITLE)) instRec.add('activity', `${user}: the learner's name and the course name do not both link to ${members} (linked: ${linked.join(', ') || 'nothing'}) (#87)`)
				for (const p of params) if (p.link && !p.link.startsWith(BASE)) instRec.add('activity', `${user}: the link ${p.link} is not absolute to this cloud (an email needs one)`)
			}
			// The instructor's Activity app: the entry shows the links, the learner's name opens the member list
			await inst.page.goto(`${BASE}/apps/activity/`)
			await settle(inst.page)
			let entry = null
			for (let i = 0; i < 15 && !entry; i++) {
				entry = await inst.page.evaluate((src) => {
					const re = new RegExp(src)
					for (const li of document.querySelectorAll('li.activity-entry')) {
						const subject = li.querySelector('.activity-entry__content__subject')
						const text = (subject?.textContent || '').replace(/\s+/g, ' ').trim()
						if (!re.test(text)) continue
						const anchors = [...(subject?.querySelectorAll('a[href]') || [])]
						const learner = anchors.find((a) => a.textContent.trim() === 'Learner 3')
						if (learner) learner.setAttribute('data-persona-pick', '1')
						return { text, anchors: anchors.map((a) => ({ name: a.textContent.trim(), href: a.href })) }
					}
					return null
				}, USED_UP.source)
				if (!entry) {
					await inst.page.evaluate(() => [...document.querySelectorAll('li.activity-entry')].pop()?.scrollIntoView())
					await inst.page.waitForTimeout(1000)
				}
			}
			;(instRec.current.notes ||= []).push(`stream: ${entry ? `${entry.text} → ${entry.anchors.map((x) => `${x.name} ${x.href.replace(BASE, '')}`).join(', ')}` : 'no entry'}`)
			if (!entry) { instRec.add('activity', 'the instructor\'s Activity app shows no "attempts used up" entry (#87)'); return }
			for (const name of ['Learner 3', COURSE_TITLE]) {
				if (!entry.anchors.some((x) => x.name === name && new URL(x.href).pathname.endsWith(members))) instRec.add('activity', `stream: "${name}" in "${entry.text}" is no link to the member list (#87)`)
			}
			await inst.page.screenshot({ path: path.join(SHOTS, 'activity-stream-attempts-used-up.png') })
			const link = inst.page.locator('a[data-persona-pick="1"]').first()
			if (!(await link.count())) return
			await link.scrollIntoViewIfNeeded()
			const popup = inst.page.context().waitForEvent('page', { timeout: 3000 }).catch(() => null)
			await link.click()
			const opened = await popup
			if (opened) { instRec.add('activity', 'stream: the learner link opens a new tab'); await opened.close(); return }
			await inst.page.waitForLoadState('domcontentloaded').catch(() => {})
			await settle(inst.page, 1200)
			if (!new URL(inst.page.url()).pathname.endsWith(members)) instRec.add('activity', `stream: the learner link lands on ${shortUrl(inst.page.url())}, not the member list (#87)`)
			const row = memberRow(inst.page, 'learner3')
			await row.waitFor({ timeout: 15000 }).catch(() => {})
			if (!(await row.isVisible().catch(() => false))) instRec.add('activity', 'stream: the member list the learner link opens does not show learner3 (#87)')
			else if ((await memberBadge(inst.page, 'learner3')) !== 'Exam attempts used up') instRec.add('activity', 'stream: in the member list the link opens, learner3 is not marked "Exam attempts used up" (#87)')
		})
		await station(inst.page, instRec, 'Members: attempts used up, open a new cycle (#87, #84)', async () => {
			await expectNotification(instRec, 'instructor', /used up all exam attempts for “Fire safety”/, 'that learner3 used up the attempts', 1)
			await enterMembers(inst.page)
			const badge = await memberBadge(inst.page, 'learner3')
			if (badge !== 'Exam attempts used up') instRec.add('cycle', `learner3: the member list says "${badge}", not "Exam attempts used up" (#87)`)
			for (const uid of ['learner2']) if (await memberBadge(inst.page, uid)) instRec.add('cycle', `${uid} has the badge "${await memberBadge(inst.page, uid)}" without any exam`)
			await inst.page.screenshot({ path: path.join(SHOTS, 'guide-members-attempts-used-up.png'), fullPage: true })
			await axeStates(inst.page, instRec, memberRow(inst.page, 'learner3').locator('[data-test="member-new-cycle"]'), '"Open a new certification cycle"')
			await openNewCycle(inst.page, instRec, 'learner3')
		})
		await station(page, rec, 'Exam tab after the new cycle: attempts again (#84, #87)', async () => {
			await expectNotification(rec, 'learner3', /new certification cycle is open/i, 'of the new cycle')
			await enterExamTab(page)
			if (await visible('[data-test="exam-tab-exhausted"]')) rec.add('cycle', 'the exam tab still says the attempts are used up after the new cycle (#84)')
			await page.locator('#app-content').getByText('Fire safety', { exact: true }).first().click()
			await settle(page)
			const start = page.getByRole('button', { name: 'Start Exam' }).first()
			if (!(await start.isVisible().catch(() => false)) || await start.isDisabled().catch(() => true)) rec.add('cycle', 'Fire safety cannot be started in the new cycle (#84, #87)')
		})
	} finally {
		if (cooldownChanged) {
			if (cooldownBefore) occRun('config:app:set', 'learning', 'exam_attempt_cooldown_minutes', '--value', cooldownBefore)
			else occRun('config:app:delete', 'learning', 'exam_attempt_cooldown_minutes')
		}
		if (limitChanged) {
			await fetch(`${APP}/api/courses/${seed.courseId}/cert-config`, {
				method: 'PATCH',
				headers: { Authorization: basic('instructor'), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ examAttemptLimit: 0 }),
			}).catch(() => {})
		}
	}
	flush()
	await inst.context.close()
	await context.close()
})

// GitHub #84 with #86 and #92: the instructor revokes learner1's certificate. learner1 is told,
// the completion tab no longer leads to a valid certificate, and the member list offers a new
// cycle. After it, learner1 confirms the required documents again (opening them first where the
// course asks for it) and is led to the exam. Nothing is deleted: the revoked certificate stays.
test('learner1: certificate revoked, the instructor opens a new cycle (#84, #86, #92)', async ({ browser }) => {
	test.setTimeout(15 * 60_000)
	const instRec = new Recorder('instructor')
	const inst = await login(browser, 'instructor')
	instRec.attach(inst.page)
	const rec = new Recorder('learner1')
	const { context, page } = await login(browser, 'learner1')
	rec.attach(page)
	const enterReport = async () => {
		await openMegaTab(inst.page, 'Participants')
		for (const sub of await subTabLabels(inst.page)) {
			await clickSubTab(inst.page, sub)
			if (await inst.page.locator('.cert-report-section').isVisible().catch(() => false)) return
		}
		throw new Error('no compliance report in any Participants sub-tab')
	}
	const certRow = () => inst.page.locator('.cert-report-section tbody tr').filter({ hasText: 'Learner 1' }).first()
	await station(inst.page, instRec, 'Compliance report: revoke the certificate of Learner 1 (#84)', async () => {
		await enterReport()
		await certRow().scrollIntoViewIfNeeded()
		const since = Date.now()
		inst.page.__acceptDialogs = true
		try {
			await certRow().locator('.cert-revoke').click()
			await inst.page.waitForTimeout(1500)
			await checkToasts(inst.page, instRec, since, 'Revoke')
		} finally {
			inst.page.__acceptDialogs = false
		}
		await settle(inst.page)
	})
	await station(inst.page, instRec, 'Members: certificate revoked (#84)', async () => {
		await enterMembers(inst.page)
		const badge = await memberBadge(inst.page, 'learner1')
		if (badge !== 'Certificate revoked') instRec.add('cycle', `learner1: the member list says "${badge}", not "Certificate revoked" (#84)`)
		if (!(await memberRow(inst.page, 'learner1').locator('[data-test="member-new-cycle"]').isVisible().catch(() => false))) instRec.add('cycle', 'learner1: no "Open a new certification cycle" after the revoke (#84)')
		await inst.page.screenshot({ path: path.join(SHOTS, 'guide-members-revoked.png'), fullPage: true })
	})
	await station(page, rec, 'Course › Completion after the revoke (#84)', async () => {
		await expectNotification(rec, 'learner1', /certificate for the course .* was revoked/i, 'of the revoke', 1)
		await openMegaTab(page, 'Completion')
		const note = page.locator('.zeugnis-revoked')
		if (!(await note.isVisible().catch(() => false))) rec.add('cycle', 'the completion tab does not say the certificate was revoked (#84)')
		else if (!/contact your instructor/i.test(await note.innerText())) rec.add('cycle', `revoked note: "${(await note.innerText()).slice(0, 160)}"`)
	})
	await station(page, rec, 'Exam tab after the revoke: no way to the revoked certificate (#84, #92)', async () => {
		await enterExamTab(page)
		if (await page.locator('[data-test="exam-tab-certificate"]').isVisible().catch(() => false)) rec.add('guidance', '"Go to my certificate" in the exam tab although the certificate is revoked (#84, #92)')
	})
	await station(inst.page, instRec, 'Members: open a new cycle after the revoke (#84)', async () => {
		await enterMembers(inst.page)
		await openNewCycle(inst.page, instRec, 'learner1')
	})
	await station(page, rec, 'Course › Completion: revoked, new cycle open (#84)', async () => {
		await expectNotification(rec, 'learner1', /new certification cycle is open/i, 'of the new cycle', 1)
		await openMegaTab(page, 'Completion')
		const note = page.locator('.zeugnis-revoked')
		if (!(await note.isVisible().catch(() => false))) rec.add('cycle', 'the completion tab no longer says the certificate was revoked')
		else if (!/opened a new certification cycle/i.test(await note.innerText())) rec.add('cycle', `with a new cycle open the revoked note says "${(await note.innerText()).slice(0, 160)}" (#84)`)
		await page.screenshot({ path: path.join(SHOTS, 'guide-completion-new-cycle.png'), fullPage: true })
	})
	await station(page, rec, 'Learning materials in the new cycle: confirm again, open first (#84, #86)', async () => {
		await enterLearningMaterials(page)
		await checkConfirmLock(page, rec, seed.materials.openFirst, 'learner1, new cycle')
	})
	await station(page, rec, 'Learning materials in the new cycle: open, confirm, go on (#84, #86, #92)', async () => {
		await enterLearningMaterials(page)
		await openAndConfirmAll(page, rec, 'learner1, new cycle')
		await enterLearningMaterials(page) // read back
		const open = (await requiredItems(page).locator('[data-test^="document-confirm-"]').allTextContents()).map((x) => x.trim()).filter((x) => x !== 'Confirmed as read')
		if (open.length) rec.add('materials', `learner1, new cycle: ${open.length} required documents not confirmed after opening and confirming`)
		const next = page.locator('[data-test="materials-next-step-button"]')
		if (!(await next.isVisible().catch(() => false))) rec.add('guidance', 'new cycle: the required content is done, but no button leads on (#92)')
		else if ((await next.textContent()).trim() !== 'Go to the exam') rec.add('guidance', `new cycle: the next step reads "${(await next.textContent()).trim()}" (#92)`)
	})
	flush()
	await inst.context.close()
	await context.close()
})

// C9b (5.16.0): the instructor's side of #92, #97, #98 and #99.
// - #92: a course without pools (the seed's "Instructors only") offers "Add pools" under "No pools assigned".
// - #97: in "Add pool" the administrator's pool shared "View only" (seed.sharedPool) is greyed out,
//   names its reason and cannot be picked; the server refuses it with that reason; the tag also
//   shows under Pools › Shared with me.
// - #98: a question hit in the Pools search names its pool as typed and opens that question: the
//   editor in one's own pool, "View questions" without an editor in the view-only pool.
// - #99: "Archive" explains first; Escape archives nothing; "Don't show again" archives and is
//   remembered; "Explain before archiving" in the personal settings brings the dialog back; an
//   archived course explains itself to the instructor. A course made for this and deleted after.
test('instructor: empty course, view-only pool, pool search, archive confirmation (#92, #97, #98, #99)', async ({ browser }) => {
	test.setTimeout(15 * 60_000)
	const rec = new Recorder('instructor')
	const { context, page } = await login(browser, 'instructor')
	rec.attach(page)
	const shared = seed.sharedPool
	const api = async (method, p, body) => {
		const res = await fetch(`${APP}/api${p}`, {
			method,
			headers: { Authorization: basic('instructor'), 'OCS-APIRequest': 'true', Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
			body: body !== undefined ? JSON.stringify(body) : undefined,
		})
		return { status: res.status, body: await res.json().catch(() => null) }
	}
	const emptyCourseId = seed.deadLinks.privateCourseId
	const visibleDialog = () => page.locator('[role=dialog]').filter({ visible: true }).first()
	const openEmptyCoursePools = async () => {
		await page.goto(`${APP}/courses/${emptyCourseId}/lernraum`)
		await settle(page)
		const poolsTab = page.locator('.lernraum-subnav button').filter({ hasText: /^\s*Pools\s*$/ }).first()
		if (await poolsTab.isVisible().catch(() => false)) await poolsTab.click()
		await settle(page, 300)
	}
	await station(page, rec, 'Empty course: "Add pools" under "No pools assigned" (#92)', async () => {
		await openEmptyCoursePools()
		const empty = page.locator('#app-content').getByText('No pools assigned', { exact: true }).first()
		if (!(await empty.isVisible().catch(() => false))) { rec.add('guidance', 'the empty course does not say "No pools assigned" (#92)'); return }
		const add = page.locator('.empty-add-pool-btn')
		if (!(await add.isVisible().catch(() => false))) { rec.add('guidance', 'no "Add pools" under "No pools assigned" (#92)'); return }
		if ((await add.textContent()).trim() !== 'Add pools') rec.add('guidance', `the empty course's button reads "${(await add.textContent()).trim()}"`)
		if (!(await isPrimary(add))) rec.add('guidance', '"Add pools" on the empty course is not the primary button (#92)')
		const below = await page.evaluate(() => {
			const btn = document.querySelector('.empty-add-pool-btn')
			const title = [...document.querySelectorAll('#app-content *')].find((el) => el.childElementCount === 0 && el.textContent.trim() === 'No pools assigned')
			return btn && title ? btn.getBoundingClientRect().top >= title.getBoundingClientRect().bottom - 1 : null
		})
		if (below !== true) rec.add('guidance', '"Add pools" is not under "No pools assigned" (#92)')
		await axeStates(page, rec, add, '"Add pools" (empty course)')
		await add.click()
		await settle(page, 300)
		if (!(await visibleDialog().getByText('Add Pool to Course').isVisible().catch(() => false))) rec.add('guidance', '"Add pools" on the empty course opens no "Add Pool to Course" dialog (#92)')
		await page.screenshot({ path: path.join(SHOTS, 'empty-course-add-pools.png') })
	})
	await station(page, rec, 'Add pool dialog: the view-only pool is greyed out, with its reason (#97)', async () => {
		if (!(await visibleDialog().isVisible().catch(() => false))) {
			await openEmptyCoursePools()
			await page.locator('.empty-add-pool-btn, .add-pool-btn').first().click()
			await settle(page, 300)
		}
		const dialog = visibleDialog()
		await dialog.locator('.pool-select-item').first().waitFor({ timeout: 10000 })
		const item = dialog.locator('.pool-select-item').filter({ has: page.locator('.pool-select-name', { hasText: new RegExp(`^${shared.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`) }) }).first()
		if (!(await item.isVisible().catch(() => false))) { rec.add('pools', `"${shared.name}" (shared with the instructor, view only) is not in "Add pool" (#97: listed, greyed out)`); return }
		const state = await item.evaluate((el) => ({
			viewOnly: el.classList.contains('view-only'),
			disabled: el.getAttribute('aria-disabled'),
			checked: el.getAttribute('aria-checked'),
			describedBy: el.getAttribute('aria-describedby'),
			reason: (document.getElementById(el.getAttribute('aria-describedby') || '-')?.textContent || '').trim(),
			tag: (el.querySelector('.pool-view-only-badge')?.textContent || '').trim(),
			cursor: getComputedStyle(el).cursor,
		}))
		if (!state.viewOnly || state.disabled !== 'true') rec.add('pools', `the view-only pool is not marked unavailable in "Add pool": ${JSON.stringify(state)} (#97)`)
		if (state.tag !== 'View only') rec.add('pools', `the view-only pool carries the tag "${state.tag}", not "View only" (#97)`)
		if (state.reason !== 'Only the owner or people who can edit this pool can add it to a course.') rec.add('pools', `the view-only pool's reason reads "${state.reason}" (#97)`)
		await item.click({ force: true }) // aria-disabled: Playwright would wait for it to be enabled
		await page.keyboard.press('Shift')
		await item.focus()
		await page.keyboard.press('Space')
		await page.waitForTimeout(300)
		if ((await item.getAttribute('aria-checked')) === 'true') rec.add('pools', 'the view-only pool can be picked in "Add pool" (#97)')
		if (await dialog.locator('.pool-add-confirm').isVisible().catch(() => false)) rec.add('pools', 'picking the view-only pool offers "Add 1 pool(s)" (#97)')
		const own = dialog.locator('.pool-select-item').filter({ hasText: 'Fire safety' }).first()
		if (await own.isVisible().catch(() => false) && await own.evaluate((el) => el.classList.contains('view-only'))) rec.add('pools', 'the instructor\'s own pool "Fire safety" is marked view only')
		for (const f of await runAxe(page, '[role=dialog]')) rec.add(f.kind, `"Add pool" dialog: ${f.detail}`)
		await axeStates(page, rec, item, 'the view-only pool in "Add pool"')
		await page.screenshot({ path: path.join(SHOTS, 'add-pool-view-only.png') })
		await closeOverlays(page)
	})
	// Node fetch: the 403 is the point, it is no app request of the page
	await station(page, rec, 'Add pool: the server refuses the view-only pool with its reason (#97)', async () => {
		const res = await api('POST', `/courses/${emptyCourseId}/pools`, { poolId: shared.id, sortOrder: 0 })
		;(rec.current.notes ||= []).push(`server: ${res.status} ${JSON.stringify(res.body).slice(0, 160)}`)
		if (res.status < 400) {
			rec.add('pools', `the server added the view-only pool to a course (${res.status}) (#97)`)
			await api('DELETE', `/courses/${emptyCourseId}/pools/${shared.id}`).catch(() => {})
		} else if (res.body?.code !== 'pool_view_only') rec.add('pools', `the server refuses the view-only pool with ${res.status} ${JSON.stringify(res.body).slice(0, 160)}, not code pool_view_only (#97)`)
		await openEmptyCoursePools()
	})
	await station(page, rec, 'Pools › Shared with me: the view-only pool carries its tag (#97)', async () => {
		await openTopTab(page, 'Pools')
		await page.locator('.pool-tabs [role=tab]').filter({ hasText: 'Shared with me' }).first().click()
		await settle(page, 300)
		const card = page.locator('.pool-card.shared').filter({ has: page.locator('.pool-card-open', { hasText: shared.name }) }).first()
		if (!(await card.isVisible().catch(() => false))) { rec.add('pools', `"${shared.name}" is not under Shared with me`); return }
		const name = (await card.locator('.pool-card-open').textContent()).trim()
		if (name !== shared.name) rec.add('pools', `the shared pool is named "${name}", not "${shared.name}" as typed`)
		const tag = (await card.locator('.permission-badge').textContent().catch(() => '')).trim()
		if (tag !== 'View only') rec.add('pools', `the view-only pool under Shared with me carries "${tag}" (#97)`)
	})
	const searchHit = async (term, questionText) => {
		await openTopTab(page, 'Pools')
		const input = page.locator('#pool-search')
		await input.fill(term)
		const hit = page.locator('.search-results-dropdown .results-list li').filter({ hasText: questionText }).first()
		await hit.waitFor({ timeout: 10000 })
		return hit
	}
	const fireQuestion = 'What do you do first when you discover a fire?'
	let fireQuestionId = null
	await station(page, rec, 'Pools search: a question hit names its pool and its ID (#98)', async () => {
		const hit = await searchHit('discover a fire', fireQuestion)
		const pool = (await hit.locator('.result-subtext').textContent().catch(() => '')).trim()
		if (pool !== 'in Fire safety') rec.add('search', `the question hit says "${pool}", not "in Fire safety" (#98)`)
		const id = (await hit.locator('.result-id').textContent().catch(() => '')).trim()
		const m = /^Question ID (\d+)$/.exec(id)
		if (!m) rec.add('search', `the number of the question hit reads "${id}", not "Question ID <n>" (#98)`)
		else fireQuestionId = Number(m[1])
		await axeStates(page, rec, hit, 'a question hit in the Pools search')
		await page.screenshot({ path: path.join(SHOTS, 'pool-search-question-hit.png') })
	})
	await station(page, rec, 'Pools search: a hit in one\'s own pool opens the editor on that question (#98)', async () => {
		const hit = await searchHit('discover a fire', fireQuestion)
		await hit.click()
		await settle(page)
		const dialog = visibleDialog()
		if (!(await waitUntil(() => dialog.isVisible(), 6000))) { rec.add('search', 'the question hit in one\'s own pool opens no editor (#98)'); return }
		const edits = await dialog.evaluate((d, q) => [...d.querySelectorAll('textarea, input')].some((f) => (f.value || '').includes(q)), fireQuestion)
		if (!edits) rec.add('search', 'the editor opened from the search does not hold the question that was picked (#98)')
		for (const f of await runAxe(page, '[role=dialog]')) rec.add(f.kind, `question editor from the search: ${f.detail}`)
		await page.keyboard.press('Escape')
		await page.waitForTimeout(400)
		await closeOverlays(page)
		if (fireQuestionId) {
			const item = page.locator(`.question-item[data-question-id="${fireQuestionId}"]`)
			if (!(await item.isVisible().catch(() => false))) rec.add('search', `after the editor, question ${fireQuestionId} is not in the list it was opened from (#98)`)
		}
	})
	await station(page, rec, 'Pools search: a hit in the view-only pool opens "View questions", no editor (#98)', async () => {
		const hit = await searchHit('reference binder', shared.question)
		const pool = (await hit.locator('.result-subtext').textContent().catch(() => '')).trim()
		if (pool !== `in ${shared.name}`) rec.add('search', `the question hit says "${pool}", not "in ${shared.name}" as typed (#98)`)
		const id = Number((/(\d+)$/.exec((await hit.locator('.result-id').textContent().catch(() => '')).trim()) || [])[1])
		await hit.click()
		await settle(page)
		await page.waitForTimeout(800)
		if (await visibleDialog().isVisible().catch(() => false)) rec.add('search', 'the question hit in a view-only pool opens an editor (#98)')
		const tab = page.locator('[role=tab][aria-selected="true"]').filter({ hasText: 'View questions' })
		if (!(await tab.count())) rec.add('search', `the view-only pool opens on "${(await page.locator('[role=tab][aria-selected="true"]').allTextContents()).map((s) => s.trim()).join(' / ')}", not "View questions" (#98)`)
		const item = page.locator(`.question-item[data-question-id="${id}"]`)
		if (!id || !(await item.isVisible().catch(() => false))) rec.add('search', `the picked question (${id || '?'}) is not shown in "View questions" (#98)`)
		else {
			const focused = await page.evaluate((qid) => document.activeElement?.closest?.('.question-item')?.getAttribute('data-question-id') === String(qid), id)
			if (!focused) rec.add('search', 'the keyboard focus is not on the picked question (#98)')
		}
		await page.screenshot({ path: path.join(SHOTS, 'pool-search-view-only-hit.png') })
	})
	// #98 follow-up: Nextcloud's minimum button height made the × taller than the field. Measured
	// at desktop and phone width; the keyboard path (Tab reaches it, Enter clears, focus back on the
	// field) at desktop width.
	for (const width of [1400, 390]) {
		await station(page, rec, `Pools search: the × sits inside the field, ${width} px (#98)`, async () => {
			await page.setViewportSize({ width, height: width < 500 ? 844 : 900 })
			try {
				await openTopTab(page, 'Pools')
				const input = page.locator('#pool-search')
				await input.fill('fire')
				const clear = page.locator('.clear-search-btn')
				await clear.waitFor({ timeout: 5000 })
				const box = await input.boundingBox()
				const x = await clear.boundingBox()
				;(rec.current.notes ||= []).push(`search field ${JSON.stringify(box)}, × ${JSON.stringify(x)} at ${width} px`)
				if (!box || !x) { rec.add('search', `the search field or its × has no box at ${width} px (#98)`); return }
				// C10: the magnifier on the left was an empty span without a size, so it never showed
				const lens = await page.locator('.icon-search-label .icon-search').evaluate((el) => {
					const r = el.getBoundingClientRect()
					return { x: r.x, y: r.y, width: r.width, height: r.height, image: getComputedStyle(el).backgroundImage }
				}).catch(() => null)
				if (!lens || lens.width < 12 || lens.height < 12 || lens.image === 'none') rec.add('layout', `the magnifier in the search field is not visible at ${width} px (${JSON.stringify(lens)})`)
				else if (lens.y < box.y || lens.y + lens.height > box.y + box.height || lens.x < box.x) rec.add('layout', `the magnifier sits outside the search field at ${width} px`)
				if (x.height > box.height + 0.5) rec.add('layout', `the × is ${Math.round(x.height)} px high, the search field ${Math.round(box.height)} px, at ${width} px (#98)`)
				if (x.y < box.y - 0.5 || x.y + x.height > box.y + box.height + 0.5 || x.x < box.x - 0.5 || x.x + x.width > box.x + box.width + 0.5) rec.add('layout', `the × sticks out of the search field at ${width} px (× ${Math.round(x.y)}–${Math.round(x.y + x.height)}, field ${Math.round(box.y)}–${Math.round(box.y + box.height)}) (#98)`)
				if (x.height < 24 || x.width < 24) rec.add('a11y-flow', `the × is ${Math.round(x.width)}×${Math.round(x.height)} px, under the 24 px target size at ${width} px (#98)`)
				await page.screenshot({ path: path.join(SHOTS, `pool-search-clear-${width}.png`) })
				if (width > 500) {
					await input.focus()
					await page.keyboard.press('Tab')
					const onClear = await clear.evaluate((b) => document.activeElement === b).catch(() => false)
					if (!onClear) rec.add('a11y-flow', 'Tab from the search field does not reach the × (#98)')
					else {
						await page.keyboard.press('Enter')
						await settle(page, 300)
						if ((await input.inputValue()) !== '') rec.add('search', 'Enter on the × does not clear the search (#98)')
						if (!(await input.evaluate((el) => document.activeElement === el))) rec.add('a11y-flow', 'after the × cleared the search, the focus is not back on the field (#98)')
					}
				}
				await input.fill('')
			} finally {
				await page.setViewportSize({ width: 1400, height: 900 })
			}
		})
	}

	// RTL (follow-up to #98): the search in "Manage" of one's own pool. The × sits at the end of the
	// field (left in Arabic), the typed text keeps clear of it (padding at the inline end).
	await station(page, rec, 'Pool window › Manage: the question search follows the reading direction (ar)', async () => {
		occRun('user:setting', 'instructor', 'core', 'lang', 'ar')
		try {
			await page.goto(`${APP}/pools?poolId=${seed.poolIds[0]}`)
			await settle(page, 1000)
			const input = page.locator('.question-search-input').first()
			await input.waitFor({ timeout: 15000 })
			const dir = await input.evaluate((el) => getComputedStyle(el).direction)
			if (dir !== 'rtl') { rec.add('layout', `the question search is "${dir}" in Arabic, not rtl`); return }
			await input.fill('fire')
			const clear = page.locator('.question-search-bar .clear-search-btn').first()
			await clear.waitFor({ timeout: 5000 })
			// the search is debounced: wait for its answer (hits or "no hits"), so axe sees the hits in both modes
			const hits = page.locator('.question-search-results')
			await hits.or(page.locator('.question-search-empty')).first().waitFor({ timeout: 8000 }).catch(() => {})
			;(rec.current.notes ||= []).push(`ar: ${await page.locator('.question-search-hit').count()} hits for "fire"`)
			if (!(await hits.isVisible().catch(() => false))) rec.add('search', 'ar: the question search finds nothing for "fire" in Fire safety')
			const box = await input.boundingBox()
			const x = await clear.boundingBox()
			const pad = await input.evaluate((el) => ({ left: parseFloat(getComputedStyle(el).paddingLeft), right: parseFloat(getComputedStyle(el).paddingRight) }))
			;(rec.current.notes ||= []).push(`ar: field ${JSON.stringify(box)}, × ${JSON.stringify(x)}, padding ${JSON.stringify(pad)}`)
			if (!box || !x) { rec.add('layout', 'ar: the question search or its × has no box'); return }
			if (x.x + x.width / 2 >= box.x + box.width / 2) rec.add('layout', 'ar: the × of the question search sits at the start (right), over the typed text, not at its end (left)')
			if (x.x < box.x - 0.5 || x.x + x.width > box.x + box.width + 0.5 || x.y < box.y - 0.5 || x.y + x.height > box.y + box.height + 0.5) rec.add('layout', 'ar: the × sticks out of the question search field')
			if (pad.left < 32) rec.add('layout', `ar: the typed text of the question search runs under the × (padding ${JSON.stringify(pad)})`)
			if (x.height < 24 || x.width < 24) rec.add('a11y-flow', `ar: the × of the question search is ${Math.round(x.width)}×${Math.round(x.height)} px, under the 24 px target size`)
			// axe while the × is there (the station's own check runs after the field is emptied again)
			for (const f of await runAxe(page, '.question-search-bar')) rec.add(f.kind, `question search with its ×: ${f.detail}`)
			await page.screenshot({ path: path.join(SHOTS, 'question-search-ar.png') })
			await input.fill('')
		} finally {
			occRun('user:setting', 'instructor', 'core', 'lang', 'en')
		}
	})

	// #99 on a course of its own, deleted afterwards
	const made = await api('POST', '/courses', { title: 'Archive check', description: 'Made by the persona sweep for the archive confirmation.', profile: seed.gamification ? 'it' : 'mandatory' })
	const archiveId = made.body?.id
	const status = async () => (await api('GET', `/courses/${archiveId}`)).body?.status
	const skipNow = async () => (await api('GET', '/settings/personal')).body?.archive_confirm_skip
	const courseCard = () => page.locator(`.course-card[data-course-id="${archiveId}"]`).first()
	const courseMenu = async (label) => {
		await openTopTab(page, 'Courses')
		const card = courseCard()
		await card.waitFor({ timeout: 10000 })
		await card.locator('.course-card-actions button[aria-haspopup], .course-card-actions .action-item__menutoggle, .course-card-actions button').last().click()
		await page.getByRole('menuitem', { name: label }).first().click()
		await page.waitForTimeout(600)
	}
	const archiveDialog = () => page.getByRole('dialog', { name: 'Archive the course “Archive check”?' })
	try {
		if (!archiveId) throw new Error(`could not make the course for the archive check: ${made.status}`)
		await api('PUT', '/settings/personal/archive-confirm', { skip: false })
		await station(page, rec, 'Course list › Archive: the confirmation explains first (#99)', async () => {
			await courseMenu('Archive')
			const dialog = archiveDialog()
			if (!(await dialog.isVisible().catch(() => false))) { rec.add('archive', '"Archive" archives without explaining first (#99)'); return }
			const explained = await dialog.evaluate((d) => {
				const ids = (d.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean)
				return ids.map((i) => document.getElementById(i)?.textContent || '').join(' ').replace(/\s+/g, ' ').trim()
			})
			if (!/Archiving marks the course as finished/i.test(explained)) rec.add('a11y-flow', `the archive dialog's description (aria-describedby) reads "${explained.slice(0, 120)}", not the explanation (#99)`)
			if (!(await dialog.locator('[data-test="course-archive-explanation"]').isVisible().catch(() => false))) rec.add('archive', 'the archive dialog does not say what archiving changes (#99)')
			const text = (await dialog.innerText()).replace(/\s+/g, ' ')
			if (!/activate the course again/i.test(text)) rec.add('archive', 'the archive dialog does not say the course can be activated again (#99)')
			for (const f of await runAxe(page, '[role=dialog]')) rec.add(f.kind, `archive dialog: ${f.detail}`)
			await page.screenshot({ path: path.join(SHOTS, 'archive-confirm.png') })
			// Escape from "Don't show again" (NcModal ignores Escape on a focused input)
			await page.keyboard.press('Shift')
			await dialog.locator('.archive-confirm__skip input').first().focus()
			await page.keyboard.press('Escape')
			await page.waitForTimeout(600)
			if (await dialog.isVisible().catch(() => false)) rec.add('archive', 'Escape does not close the archive dialog')
			if ((await status()) !== 'active') rec.add('archive', `Escape in the archive dialog archived the course (status ${await status()}) (#99)`)
		})
		await station(page, rec, 'Archive confirmation: "Don\'t show again", then Archive (#99)', async () => {
			await courseMenu('Archive')
			const dialog = archiveDialog()
			if (!(await dialog.isVisible().catch(() => false))) { rec.add('archive', 'the archive dialog does not come back after Escape (#99)'); return }
			// NcCheckboxRadioSwitch does not pass data-test on; its class does
			const skip = dialog.locator('.archive-confirm__skip')
			await skip.getByText('Don’t show again').first().click()
			await page.waitForTimeout(300)
			const ticked = await skip.locator('input').first().isChecked().catch(() => null)
			if (ticked !== true) rec.add('archive', `"Don't show again" does not tick (${ticked})`)
			if (!(await dialog.isVisible().catch(() => false))) { rec.add('archive', 'ticking "Don\'t show again" closed the dialog (#99)'); return }
			const since = Date.now()
			await dialog.locator('[data-test="archive-confirm-ok"]').click()
			await page.waitForTimeout(1500)
			await checkToasts(page, rec, since, 'Archive (confirmation)')
			if ((await status()) !== 'archived') rec.add('archive', `"Archive" in the dialog did not archive the course (status ${await status()})`)
			if ((await skipNow()) !== 'yes') rec.add('archive', `"Don't show again" was not remembered (archive_confirm_skip ${await skipNow()}) (#99)`)
		})
		await station(page, rec, 'Course list › Archive after "Don\'t show again": no dialog (#99)', async () => {
			await courseMenu('Activate')
			if ((await status()) !== 'active') { rec.add('archive', `"Activate" did not reactivate the course (status ${await status()})`); return }
			await page.reload()
			await settle(page)
			await courseMenu('Archive')
			if (await archiveDialog().isVisible().catch(() => false)) rec.add('archive', 'the archive dialog comes back after "Don\'t show again" and a reload (#99)')
			if ((await status()) !== 'archived') rec.add('archive', `"Archive" without the dialog did not archive (status ${await status()})`)
		})
		await station(page, rec, 'Personal settings: "Explain before archiving" brings the confirmation back (#99)', async () => {
			// the app's Settings tab knows the role; Nextcloud's own personal settings page does not
			await openTopTab(page, 'Settings')
			await settle(page, 800)
			const row = page.locator('.archive-confirm-setting').first()
			if (!(await row.isVisible().catch(() => false))) { rec.add('archive', 'no "Explain before archiving" in the personal settings (#99)'); return }
			const input = row.locator('input').first()
			if (await input.isChecked()) rec.add('archive', '"Explain before archiving" shows on although "Don\'t show again" was chosen')
			await row.getByText('Explain before archiving', { exact: true }).first().click()
			await page.waitForTimeout(300)
			const since = Date.now()
			const save = page.getByRole('button', { name: 'Save', exact: true }).first()
			if (await save.isVisible().catch(() => false)) await save.click()
			await page.waitForTimeout(1500)
			await checkToasts(page, rec, since, 'Save (personal settings)')
			if ((await skipNow()) !== 'no') { rec.add('archive', `"Explain before archiving" saved, the server keeps archive_confirm_skip ${await skipNow()} (#99)`); return }
			await courseMenu('Activate')
			await courseMenu('Archive')
			if (!(await archiveDialog().isVisible().catch(() => false))) rec.add('archive', 'with "Explain before archiving" on again, "Archive" archives without the dialog (#99)')
			else {
				await archiveDialog().locator('[data-test="archive-confirm-cancel"]').click()
				await page.waitForTimeout(500)
				if ((await status()) !== 'active') rec.add('archive', `"Cancel" in the archive dialog archived the course (status ${await status()})`)
			}
		})
		await station(page, rec, 'Archived course (instructor): what archiving changes (#99)', async () => {
			await page.goto(`${APP}/courses/${seed.deadLinks.archivedCourseId}`)
			await settle(page)
			const explanation = page.locator('#app-content [data-test="course-archive-explanation"]').first()
			if (!(await explanation.isVisible().catch(() => false))) { rec.add('archive', 'the archived course does not explain to its instructor what archiving changes (#99)'); return }
			const re = (await page.locator('#app-content [data-test="course-archive-reactivate"]').first().textContent().catch(() => '')).trim()
			if (!/activate the course again at any time/i.test(re)) rec.add('archive', `the archived course's instructor (its creator) reads "${re}" about reactivating (#99)`)
		})
	} finally {
		await api('PUT', '/settings/personal/archive-confirm', { skip: false }).catch(() => {})
		if (archiveId) await api('DELETE', `/courses/${archiveId}`).catch(() => {})
	}
	flush()
	await context.close()
})

// GitHub #96: after learner3's three failed attempts every Fire safety question is a weak question,
// the long one (pools.json: a sentence with a 120-character URL) included. At phone width its text
// wraps in the Question column and the table scrolls sideways in a region of its own.
test('instructor: weak questions with a long question, phone width (#96)', async ({ browser }) => {
	test.setTimeout(5 * 60_000)
	const rec = new Recorder('instructor')
	const { context, page } = await login(browser, 'instructor')
	rec.attach(page)
	await station(page, rec, 'Participants › Weak questions: a long question wraps in its column (#96)', async () => {
		await page.setViewportSize({ width: 390, height: 844 })
		try {
			await page.goto(`${APP}/courses/${seed.courseId}/weak-questions`)
			await settle(page)
			const region = page.locator('.weak-questions-scroll')
			await region.waitFor({ timeout: 15000 })
			const row = page.locator('.weak-questions-table tbody tr').filter({ hasText: 'fire alarm tested' }).first()
			if (!(await row.isVisible().catch(() => false))) { rec.add('participants', 'the long question is not among the weak questions after three failed attempts'); return }
			// the server shortens the text to 120 characters; a cut text says so
			const shown = (await row.locator('.q-text').textContent()).trim()
			if (!shown.endsWith('…')) rec.add('participants', `the long question is cut without a mark: "…${shown.slice(-40)}"`)
			const geo = await row.evaluate((tr) => {
				const cells = [...tr.children].map((td) => td.getBoundingClientRect())
				const q = tr.querySelector('.q-text')
				const qi = [...tr.children].indexOf(q)
				const range = document.createRange()
				range.selectNodeContents(q)
				const text = range.getBoundingClientRect()
				const region = tr.closest('.weak-questions-scroll')
				return {
					textRight: text.right, textLeft: text.left, cellRight: cells[qi].right, cellLeft: cells[qi].left, nextLeft: cells[qi + 1]?.left,
					lines: Math.round(text.height / parseFloat(getComputedStyle(q).lineHeight || '20')),
					regionScroll: region.scrollWidth - region.clientWidth,
					regionFits: region.getBoundingClientRect().right <= window.innerWidth + 1,
					page: document.documentElement.scrollWidth - window.innerWidth,
					rtl: getComputedStyle(tr).direction === 'rtl',
				}
			})
			;(rec.current.notes ||= []).push(`weak questions at 390 px: ${JSON.stringify(geo)}`)
			if (geo.textRight > geo.cellRight + 1 || geo.textLeft < geo.cellLeft - 1 || (geo.nextLeft !== undefined && geo.textRight > geo.nextLeft + 1)) rec.add('layout', `the long question runs out of its column into "Chapter" (text ${Math.round(geo.textLeft)}–${Math.round(geo.textRight)}, column ${Math.round(geo.cellLeft)}–${Math.round(geo.cellRight)}) (#96)`)
			if (!geo.regionFits) rec.add('layout', 'the weak questions region is wider than the screen (#96)')
			if (geo.page > 1) rec.add('layout', `the page scrolls sideways by ${geo.page} px at phone width (#96)`)
			if (geo.regionScroll <= 0) rec.add('layout', 'at 390 px the weak questions table does not scroll sideways in its region (the columns are squeezed) (#96)')
			const named = await region.evaluate((r) => ({ role: r.getAttribute('role'), tab: r.getAttribute('tabindex'), name: r.getAttribute('aria-label') }))
			if (named.role !== 'region' || named.tab !== '0' || named.name !== 'Weak questions') rec.add('a11y-flow', `the scrolling weak questions region is not a named Tab stop: ${JSON.stringify(named)} (#96)`)
			else {
				await page.keyboard.press('Shift')
				await region.focus()
				const before = await region.evaluate((r) => r.scrollLeft)
				await page.keyboard.press('ArrowRight')
				await page.keyboard.press('ArrowRight')
				await page.waitForTimeout(300)
				const after = await region.evaluate((r) => r.scrollLeft)
				if (geo.regionScroll > 0 && after === before) rec.add('a11y-flow', 'the arrow keys do not scroll the focused weak questions region (#96)')
			}
			await page.screenshot({ path: path.join(SHOTS, 'weak-questions-long-phone.png'), fullPage: true })
		} finally {
			await page.setViewportSize({ width: 1400, height: 900 })
		}
	})
	// .rate-yellow was #e6a817, about 2.1:1 on white. The seed's weak questions are all failed
	// three times (red), so each of the three classes is put on a real wrong-rate cell in turn and
	// measured with the page's own CSS: text colour against the first opaque background behind it.
	for (const scheme of ['light', 'dark']) {
		await station(page, rec, `Participants › Weak questions: the wrong rates keep 4.5:1, ${scheme}`, async () => {
			await page.emulateMedia({ colorScheme: scheme })
			try {
				await page.goto(`${APP}/courses/${seed.courseId}/weak-questions`)
				await settle(page)
				const cell = page.locator('.weak-questions-table td.wrong-rate').first()
				if (!(await cell.isVisible({ timeout: 15000 }).catch(() => false))) { rec.add('participants', 'no wrong rate is shown among the weak questions'); return }
				const measured = await cell.evaluate((td) => {
					const rgb = (c) => (c.match(/[\d.]+/g) || []).map(Number)
					const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }).reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0)
					const back = (el) => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length >= 3 && (c[3] === undefined || c[3] === 1)) return c } return [255, 255, 255] }
					const before = td.className
					const out = {}
					for (const cls of ['rate-red', 'rate-yellow', 'rate-ok']) {
						td.className = `wrong-rate ${cls}`
						const fg = rgb(getComputedStyle(td).color)
						const bg = back(td)
						const [hi, lo] = [lum(fg), lum(bg)].sort((a, b) => b - a)
						out[cls] = { ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100, fg: getComputedStyle(td).color, bg: `rgb(${bg.slice(0, 3).join(', ')})` }
					}
					td.className = before
					return out
				})
				;(rec.current.notes ||= []).push(`wrong rate contrast, ${scheme}: ${JSON.stringify(measured)}`)
				for (const [cls, m] of Object.entries(measured)) {
					if (m.ratio < 4.5) rec.add('contrast', `.${cls} in the weak questions is ${m.ratio}:1 (${m.fg} on ${m.bg}), ${scheme}`)
				}
				for (const f of await runAxe(page)) rec.add(f.kind, `weak questions, ${scheme}: ${f.detail}`)
			} finally {
				await page.emulateMedia({ colorScheme: null })
			}
		})
	}
	flush()
	await context.close()
})

// 5.17.0 follow-up: #98 (reported on 5.16.0) a learner's question hit from a pool of a course they
// are enrolled in said "Could not find the pool for the selected question"; it lands on Training
// now (never "View questions", the deliberate limit). RTL: magnifier and × of the Pools search
// follow the reading direction (ar). #85: the names in Learning's activities are links (the
// activity API hands the web UI and the digest email the same rich subject).
test('learner: search hit from an enrolled course, RTL search field, activity links (#85, #98)', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const occ = (...args) => execFileSync('docker', ['exec', '-u', 'www-data', '-w', '/var/www/html', state.NC, 'php', 'occ', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
	const fireQuestion = 'What do you do first when you discover a fire?'
	const hitOpensTraining = async (page, rec, who) => {
		await openTopTab(page, 'Pools')
		const input = page.locator('#pool-search')
		if (!(await input.isVisible().catch(() => false))) { rec.add('search', `${who}: the Pools page has no search field`); return }
		await input.fill('discover a fire')
		const hit = page.locator('.search-results-dropdown .results-list li').filter({ hasText: fireQuestion }).first()
		// isVisible() does not wait; the hits come from a debounced request
		if (!(await hit.waitFor({ timeout: 10000 }).then(() => true).catch(() => false))) { rec.add('search', `${who}: the question of the course pool is not found`); return }
		await hit.locator('button.result-btn').click()
		await settle(page)
		await page.waitForTimeout(800)
		const toasts = await page.locator('.toastify').allTextContents().catch(() => [])
		if (toasts.some((t) => /Could not find the pool/i.test(t))) rec.add('search', `${who}: "Could not find the pool for the selected question" after the hit from the enrolled course (#98)`)
		const selectedTabs = (await page.locator('[role=tab][aria-selected="true"]').allTextContents()).map((t) => t.trim())
		if (!selectedTabs.some((t) => /^Training$/.test(t))) rec.add('search', `${who}: the hit opens on "${selectedTabs.join(' / ')}", not Training (#98)`)
		if (await page.locator('[role=tab]').filter({ hasText: /^\s*(View questions|Manage)\s*$/ }).count()) rec.add('search', `${who}: the pool from the course offers "View questions"/"Manage" (answer key, #98)`)
		if (await page.locator('[role=dialog]').filter({ visible: true }).count()) rec.add('search', `${who}: the hit opens a dialog (#98)`)
		await page.screenshot({ path: path.join(SHOTS, `pool-search-course-hit-${slug(who)}.png`) })
	}

	{
		const rec = new Recorder('learner2')
		const { context, page } = await login(browser, 'learner2')
		rec.attach(page)
		await station(page, rec, 'Pools search: a learner\'s hit from the enrolled course opens Training (#98)', async () => {
			await hitOpensTraining(page, rec, 'learner2')
		})
		// RTL: the magnifier at the start (right), the × at the end (left), both inside the field
		await station(page, rec, 'Pools search: magnifier and × follow the reading direction (ar)', async () => {
			occ('user:setting', 'learner2', 'core', 'lang', 'ar')
			try {
				// the tab label is Arabic now: straight to the Pools route
				await page.goto(`${APP}/pools`)
				await settle(page)
				const dir = await page.evaluate(() => getComputedStyle(document.querySelector('#pool-search') || document.body).direction)
				if (dir !== 'rtl') { rec.add('layout', `the Pools search is "${dir}" in Arabic, not rtl`); return }
				const input = page.locator('#pool-search')
				await input.fill('fire')
				const clear = page.locator('.clear-search-btn')
				await clear.waitFor({ timeout: 5000 })
				const box = await input.boundingBox()
				const x = await clear.boundingBox()
				const lens = await page.locator('.icon-search-label .icon-search').boundingBox()
				;(rec.current.notes ||= []).push(`ar: field ${JSON.stringify(box)}, × ${JSON.stringify(x)}, magnifier ${JSON.stringify(lens)}`)
				if (!box || !x || !lens) { rec.add('layout', 'ar: the search field, its × or the magnifier has no box'); return }
				const mid = box.x + box.width / 2
				if (lens.x + lens.width / 2 <= mid) rec.add('layout', 'ar: the magnifier sits at the end (left) of the search field, not at its start (right)')
				if (x.x + x.width / 2 >= mid) rec.add('layout', 'ar: the × sits at the start (right) of the search field, over the typed text, not at its end (left)')
				if (x.x < box.x - 0.5 || x.x + x.width > box.x + box.width + 0.5 || lens.x < box.x - 0.5 || lens.x + lens.width > box.x + box.width + 0.5) rec.add('layout', 'ar: the × or the magnifier sticks out of the search field')
				// the typed text starts after the magnifier and ends before the ×
				const pad = await input.evaluate((el) => ({ right: parseFloat(getComputedStyle(el).paddingRight), left: parseFloat(getComputedStyle(el).paddingLeft) }))
				if (pad.right < 32 || pad.left < 32) rec.add('layout', `ar: the text of the search field runs under the magnifier or the × (padding ${JSON.stringify(pad)})`)
				await page.screenshot({ path: path.join(SHOTS, 'pool-search-ar.png') })
				await input.fill('')
			} finally {
				occ('user:setting', 'learner2', 'core', 'lang', 'en')
			}
		})
		flush()
		await context.close()
	}

	{
		const rec = new Recorder('instructor-studentview', { studentView: true })
		const { context, page } = await login(browser, 'instructor', { studentView: true })
		rec.attach(page)
		await station(page, rec, 'Pools search: in the student view a hit opens Training, no answer key (#98)', async () => {
			await hitOpensTraining(page, rec, 'instructor in the student view')
		})
		flush()
		await context.close()
	}

	{
		const rec = new Recorder('learner1')
		const { context, page } = await login(browser, 'learner1')
		rec.attach(page)
		await station(page, rec, 'Activities: the course and the certificate are links (#85)', async () => {
			await openApp(page)
			const res = await fetch(`${BASE}/ocs/v2.php/apps/activity/api/v2/activity/all?format=json&limit=100`, { headers: { Authorization: basic('learner1'), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
			if (!res.ok) { rec.add('activity', `the activity API answers ${res.status}`); return }
			const entries = ((await res.json())?.ocs?.data || []).filter((a) => a.app === 'learning')
			;(rec.current.notes ||= []).push(`learning activities: ${entries.map((a) => a.subject).join(' | ').slice(0, 900)}`)
			if (!entries.length) { rec.add('activity', 'learner1 has no Learning activities'); return }
			const course = `/apps/learning/courses/${seed.courseId}`
			const links = entries.flatMap((a) => Object.values(a.subject_rich?.[1] || {}).map((p) => ({ subject: a.subject, name: p.name, link: p.link || '' })))
			for (const a of entries) {
				if (!a.subject_rich?.[0]) rec.add('activity', `"${a.subject}" has no rich subject, so its names are no links (#85)`)
			}
			if (!links.some((l) => l.name === COURSE_TITLE && l.link.endsWith(course))) rec.add('activity', `no Learning activity links the course name to ${course} (#85)`)
			if (!links.some((l) => /certificate/i.test(l.subject) && l.name === COURSE_TITLE && l.link.endsWith(`${course}/summary`))) rec.add('activity', 'no certificate activity links to the course\'s "Completion and certificate" tab (#85)')
			for (const l of links) {
				if (l.link && !/^https?:\/\//.test(l.link)) rec.add('activity', `"${l.subject}": the link ${l.link} is not absolute (an email needs one)`)
			}
			// the links lead somewhere: the course opens for learner1
			const target = links.find((l) => l.link.endsWith(course))
			if (target) {
				await page.goto(target.link)
				await settle(page)
				if (await page.getByText('This course is not available').isVisible().catch(() => false)) rec.add('activity', 'the course link of the activity opens "This course is not available" (#85)')
			}
		})

		// The same in the Activity app as a person sees it: the names are clickable links in the
		// stream, and clicking them lands on the course, its "Completion & certificate" tab, the pool
		// and (gamification on) the achievements on the dashboard. The stream pages lazily: scroll
		// until Learning's entries are loaded.
		const course = `/apps/learning/courses/${seed.courseId}`
		const streamLinks = async () => {
			await page.goto(`${BASE}/apps/activity/`)
			await settle(page)
			let found = []
			for (let i = 0; i < 25; i++) {
				found = await page.evaluate(() => [...document.querySelectorAll('li.activity-entry')].map((li) => {
					const subject = li.querySelector('.activity-entry__content__subject')
					return {
						text: (subject?.textContent || '').replace(/\s+/g, ' ').trim(),
						anchors: [...(subject?.querySelectorAll('a[href]') || [])].map((a) => ({ name: a.textContent.trim(), href: a.href })),
						strong: [...(subject?.querySelectorAll('strong') || [])].filter((s) => !s.closest('a')).map((s) => s.textContent.trim()),
					}
				}))
				const learning = found.filter((e) => e.anchors.some((a) => a.href.includes('/apps/learning/')) || e.text.includes(COURSE_TITLE))
				if (learning.some((e) => /certificate/i.test(e.text)) && learning.some((e) => /enrolled/i.test(e.text))) break
				await page.evaluate(() => [...document.querySelectorAll('li.activity-entry')].pop()?.scrollIntoView())
				await page.waitForTimeout(1200)
			}
			return found.filter((e) => e.anchors.some((a) => a.href.includes('/apps/learning/')) || e.text.includes(COURSE_TITLE))
		}
		// pick: the first link whose entry text matches `text` and whose href matches `href` (regex sources)
		const clickStreamLink = async (pick) => {
			const href = await page.evaluate(({ textSrc, hrefSrc }) => {
				const textRe = new RegExp(textSrc, 'i')
				const hrefRe = new RegExp(hrefSrc)
				for (const li of document.querySelectorAll('li.activity-entry')) {
					const subject = li.querySelector('.activity-entry__content__subject')
					const text = (subject?.textContent || '').replace(/\s+/g, ' ').trim()
					if (!textRe.test(text)) continue
					for (const a of subject?.querySelectorAll('a[href]') || []) {
						if (hrefRe.test(a.href)) { a.setAttribute('data-persona-pick', '1'); return a.href }
					}
				}
				return null
			}, { textSrc: pick.text.source, hrefSrc: pick.href.source })
			if (!href) return null
			const link = page.locator('a[data-persona-pick="1"]').first()
			await link.scrollIntoViewIfNeeded()
			const popup = page.context().waitForEvent('page', { timeout: 3000 }).catch(() => null)
			await link.click()
			const opened = await popup
			const target = opened || page
			await target.waitForLoadState('domcontentloaded').catch(() => {})
			await settle(target, 1200)
			return { href, target, opened }
		}

		await station(page, rec, 'Activity app: Learning\'s entries show the course and certificate as links (#85)', async () => {
			const entries = await streamLinks()
			;(rec.current.notes ||= []).push(`stream: ${entries.map((e) => `${e.text} → ${e.anchors.map((a) => a.href.replace(BASE, '')).join(', ') || '(no link)'}`).join(' | ').slice(0, 1400)}`)
			if (!entries.length) { rec.add('activity', 'the Activity app shows learner1 no Learning entry'); return }
			for (const e of entries) {
				if (e.text.includes(COURSE_TITLE) && !e.anchors.some((a) => a.name === COURSE_TITLE)) rec.add('activity', `stream: "${e.text}" shows the course name as text, not as a link (#85)`)
				for (const a of e.anchors) {
					if (!/^https?:\/\//.test(a.href) || !a.href.startsWith(BASE)) rec.add('activity', `stream: "${e.text}" links ${a.href}, not this cloud (#85)`)
				}
			}
			const enrolled = entries.find((e) => /enrolled in the course/i.test(e.text) && e.text.includes(COURSE_TITLE))
			if (!enrolled) rec.add('activity', 'stream: no "You were enrolled in the course" entry for the course')
			else if (!enrolled.anchors.some((a) => a.name === COURSE_TITLE && new URL(a.href).pathname.endsWith(course))) rec.add('activity', `stream: the enrolment links the course name to ${enrolled.anchors.map((a) => a.href).join(', ') || 'nothing'}, not ${course} (#85)`)
			const certs = entries.filter((e) => /certificate/i.test(e.text) && e.text.includes(COURSE_TITLE))
			if (!certs.length) rec.add('activity', 'stream: no certificate entry for learner1 (passed the exam, so one was issued)')
			for (const e of certs) {
				if (!e.anchors.some((a) => a.name === COURSE_TITLE && new URL(a.href).pathname.endsWith(`${course}/summary`))) rec.add('activity', `stream: "${e.text}" does not link to the course's "Completion & certificate" tab (#85)`)
			}
			const cycle = entries.find((e) => /certification cycle/i.test(e.text))
			if (cycle && !cycle.anchors.some((a) => new URL(a.href).pathname.endsWith(`${course}/summary`))) rec.add('activity', `stream: "${cycle.text}" does not link to the "Completion & certificate" tab (#85)`)
			for (const e of entries.filter((x) => /New question pool/i.test(x.text))) {
				if (!e.anchors.some((a) => /\/apps\/learning\/pools\?poolId=\d+$/.test(new URL(a.href).pathname + new URL(a.href).search))) rec.add('activity', `stream: "${e.text}" does not link the pool (#85)`)
			}
			await page.screenshot({ path: path.join(SHOTS, 'activity-stream-learning.png'), fullPage: false })
			// Click the course name of the enrolment: the course opens
			const hit = await clickStreamLink({ text: new RegExp(`enrolled in the course ${COURSE_TITLE}`), href: /\/courses\/\d+$/ })
			if (!hit) { rec.add('activity', 'stream: no clickable course name in the enrolment'); return }
			if (hit.opened) { rec.add('activity', 'stream: the course link opens a new tab'); await hit.opened.close() }
			{
				// learners land on the course's first Lernraum tab (/courses/<id>/<tab>), never on the certificate
				const landed = new URL(page.url()).pathname
				if (!(landed.endsWith(course) || landed.includes(`${course}/`)) || landed.endsWith('/summary')) rec.add('activity', `stream: clicking the course name lands on ${shortUrl(page.url())}, not the course (#85)`)
			}
			if (await page.getByText('This course is not available').isVisible().catch(() => false)) rec.add('activity', 'stream: the course link opens "This course is not available" (#85)')
			if (!(await page.locator('#app-content').getByText(COURSE_TITLE).filter({ visible: true }).count().catch(() => 0))) rec.add('activity', 'stream: the course link does not show the course')
		})

		await station(page, rec, 'Activity app: the certificate link opens "Completion & certificate" (#85)', async () => {
			await streamLinks()
			const hit = await clickStreamLink({ text: /certificate/, href: /\/summary$/ })
			if (!hit) { rec.add('activity', 'stream: no clickable certificate entry'); return }
			if (hit.opened) { rec.add('activity', 'stream: the certificate link opens a new tab'); await hit.opened.close() }
			if (!new URL(page.url()).pathname.endsWith(`${course}/summary`)) rec.add('activity', `stream: the certificate link lands on ${shortUrl(page.url())} (#85)`)
			const selected = (await page.locator('[role=tab][aria-selected="true"]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim())
			;(rec.current.notes ||= []).push(`selected tabs: ${selected.join(' / ')}`)
			if (!selected.some((t) => /^Completion( & certificate)?$/.test(t))) rec.add('activity', `stream: the certificate link opens "${selected.join(' / ')}", not "Completion & certificate" (#85)`)
			if (await page.getByText('This course is not available').isVisible().catch(() => false)) rec.add('activity', 'stream: the certificate link opens "This course is not available" (#85)')
			if (!(await page.locator('#app-content').getByText(/certificate/i).filter({ visible: true }).count().catch(() => 0))) rec.add('activity', 'stream: the "Completion & certificate" tab shows no certificate text')
		})

		await station(page, rec, 'Activity app: a new pool\'s link opens that pool (#85)', async () => {
			await streamLinks()
			const hit = await clickStreamLink({ text: /New question pool/, href: /\/pools\?poolId=\d+$/ })
			if (!hit) { rec.add('activity', 'stream: no clickable pool name in "New question pool"'); return }
			if (hit.opened) { rec.add('activity', 'stream: the pool link opens a new tab'); await hit.opened.close() }
			const toasts = await page.locator('.toastify').allTextContents().catch(() => [])
			if (toasts.some((t) => /Could not|not found|not available/i.test(t))) rec.add('activity', `stream: the pool link shows "${toasts.join(' / ')}" (#85)`)
			const selected = (await page.locator('[role=tab][aria-selected="true"]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim())
			;(rec.current.notes ||= []).push(`pool link ${hit.href.replace(BASE, '')}: selected tabs ${selected.join(' / ')}`)
			if (!selected.some((t) => /^Training$/.test(t))) rec.add('activity', `stream: the pool link opens "${selected.join(' / ') || 'no pool'}", not the pool on Training (#85)`)
		})

		if (state.GAMIFICATION === 'on') {
			await station(page, rec, ACHIEVEMENT_LINK_STATION, async () => {
				const entries = await streamLinks()
				const badges = entries.filter((e) => /Achievement unlocked/i.test(e.text))
				;(rec.current.notes ||= []).push(`achievements in the stream: ${badges.map((e) => e.text).join(' | ') || 'none'}`)
				if (!badges.length) {
					// learner1 may unlock nothing in this run; then the API station's rich-subject check is the evidence
					const res = await fetch(`${BASE}/ocs/v2.php/apps/activity/api/v2/activity/all?format=json&limit=200`, { headers: { Authorization: basic('learner1'), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
					const all = res.ok ? ((await res.json())?.ocs?.data || []).filter((a) => a.app === 'learning' && /Achievement/i.test(a.subject)) : []
					if (all.length) rec.add('activity', `the API lists ${all.length} achievement(s), the stream shows none`)
					return
				}
				for (const e of badges) {
					// GitHub #101: the link opens Today's achievements section, not just Today
					if (!e.anchors.some((a) => { const u = new URL(a.href); return u.pathname.endsWith('/apps/learning/dashboard') && u.searchParams.get('section') === 'achievements' })) rec.add('activity', `stream: "${e.text}" does not link to the dashboard's achievements (#85, #101)`)
				}
				const hit = await clickStreamLink({ text: /Achievement unlocked/, href: /\/dashboard\?section=achievements$/ })
				if (!hit) { rec.add('activity', 'stream: no clickable achievement'); return }
				if (hit.opened) { rec.add('activity', 'stream: the achievement link opens a new tab'); await hit.opened.close() }
				const heading = page.locator('#dashboard-achievements-heading')
				if (!(await heading.isVisible().catch(() => false))) { rec.add('activity', 'stream: the achievement link opens the dashboard without its achievements (#85)'); return }
				const inView = await heading.evaluate((el) => { const r = el.getBoundingClientRect(); return r.bottom > 0 && r.top < window.innerHeight }).catch(() => false)
				if (!inView) rec.add('activity', 'stream: the achievement link opens the dashboard, but its achievements are scrolled out of view (#101)')
			})
		}
		flush()
		await context.close()
	}
})

for (const [persona, opts] of [['admin', {}], ['instructor', {}], ['learner2', {}], ['admin-studentview', { studentView: true }]]) {
	test(`sweep: ${persona}`, async ({ browser }) => {
		test.setTimeout(45 * 60_000)
		await sweepPersona(browser, persona, opts)
		expect(true).toBe(true)
	})
}
