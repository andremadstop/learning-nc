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
const { expectedReason, axeFindings, stationText, pickAnswerIndex, REFUSED_LOGO_STATION, DEAD_LINK_STATION_PREFIX } = require('./lib.cjs')

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
async function login(browser, persona, { studentView = false } = {}) {
	const user = persona.replace(/-.*/, '')
	const context = await browser.newContext({ acceptDownloads: true, locale: 'en-GB', timezoneId: 'Europe/London', viewport: { width: 1400, height: 900 } })
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

/** Open every unconfirmed required document (new tab, as a learner would), then confirm it. */
async function openAndConfirmAll(page, rec, who) {
	const items = requiredItems(page)
	const n = await items.count()
	for (let i = 0; i < n; i++) {
		const item = items.nth(i)
		const btn = itemConfirm(item)
		const title = await itemTitle(item)
		if ((await btn.textContent().catch(() => '')).trim() === 'Confirmed as read') continue
		const opened = page.context().waitForEvent('page', { timeout: 10000 }).catch(() => null)
		await item.locator('a[data-test^="document-open-"]').first().click()
		const tab = await opened
		if (!tab) rec.add('materials', `${who}: "Open document" of "${title}" opened nothing`)
		else {
			await tab.waitForLoadState().catch(() => {})
			await tab.close().catch(() => {})
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
	})
	await station(page, rec, 'Certificate: download', async () => {
		const dl = await download(page, () => page.locator('.certificate-actions').getByRole('button', { name: /download/i }).first().click(), 'learner-certificate')
		if (dl.size < 200) rec.add('download', `certificate download is ${dl.size} bytes (${dl.suggested})`)
		if (!/^\s*[{[]/.test(dl.head.toString('utf8')) && !/^%PDF/.test(dl.head.toString('latin1'))) rec.add('download', `certificate download is neither JSON nor PDF: ${dl.suggested}`)
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
		const attrs = await el.evaluate((n) => ({ role: n.getAttribute('role'), expanded: n.getAttribute('aria-expanded'), label: n.getAttribute('aria-label'), tabindex: n.getAttribute('tabindex') }))
		if (attrs.role !== 'button' || attrs.expanded !== 'false' || attrs.tabindex !== '0') rec.add('a11y-flow', `the hidden question is not a named Tab stop: ${JSON.stringify(attrs)}`)
		if (attrs.label !== 'Question, hidden until the end of the intro – tap or point at it to show it') rec.add('a11y-flow', `the hidden question is named "${attrs.label}"`)
		const veil = (await el.locator('.qconceal__veil').textContent().catch(() => '')).trim()
		if (veil !== 'The question appears after the intro – or right away when you point or tap here.') rec.add('media', `the hidden question says "${veil}"`)
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
	// question to the next. The flow pool (seed) has two questions with a spoken intro, in shuffled
	// order. After "Next Question" the second must be held hidden from the first frame (no flash),
	// the first one's sound must have stopped, a mouse crossing the area on its way to the player
	// must not uncover it, and a pointer resting inside it (it was there for "Next Question", so it
	// never "enters" the area) uncovers it after a short rest.
	await station(page, rec, 'Question card with intro: from the first media question to the second (#66)', async () => {
		const flow = media.flow
		if (!flow) throw new Error('seed has no media flow pool')
		await prefsCall({ autoplay: false, sounds: 'auto' })
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Training' }).first().click()
		await settle(page)
		await page.locator('#app-content .pool-item').filter({ hasText: flow.poolName }).first().click()
		await settle(page)
		await page.getByRole('button', { name: 'Start Training' }).first().click()
		await page.mouse.move(0, 0)
		await card().locator('.question-text').first().waitFor({ state: 'attached', timeout: 15000 })
		const first = (await card().locator('.question-text').first().textContent()).trim()
		if (!flow.questions.includes(first)) throw new Error(`Training of the flow pool shows "${first}"`)
		await player().waitFor({ timeout: 10000 })
		if (!(await waitUntil(isHidden, 3000))) rec.add('media', 'flow: the first question is not held hidden for its intro')
		// a quick pass over the hidden area towards the player above it must not uncover it
		const firstBox = await conceal().boundingBox()
		if (!firstBox) throw new Error('flow: no box for the hidden question')
		await page.mouse.move(firstBox.x + firstBox.width / 2, firstBox.y + firstBox.height + 30)
		await page.mouse.move(firstBox.x + firstBox.width / 2, firstBox.y - 30, { steps: 4 })
		await page.waitForTimeout(900)
		if (!(await isHidden())) rec.add('media', 'flow: a mouse crossing the hidden question uncovers it (no hover intent)')
		await conceal().hover()
		if (!(await waitUntil(async () => !(await isHidden()), 2000))) { rec.add('media', 'flow: hovering the first hidden question does not uncover it'); return }
		await card().locator('.answer-btn').first().click()
		await card().locator('.answer-feedback').first().waitFor({ timeout: 15000 })
		await settle(page, 300)
		// The pointer rests in the upper part of the question area (the same element holds the next
		// question, which keeps at least 80 px; its top may move a few px) and "Next Question" is
		// pressed by keyboard, so the pointer never leaves it.
		const answeredBox = await conceal().boundingBox()
		const rest = { x: answeredBox.x + answeredBox.width / 2, y: answeredBox.y + Math.min(60, answeredBox.height / 2) }
		await page.mouse.move(rest.x, rest.y)
		// the explanation after answering plays (a click is a gesture); "Next Question" follows at
		// once, so its sound is still running when the next question comes
		let played = false
		const play = player().locator('.qmedia__button--primary')
		if (!(await play.isDisabled().catch(() => true))) {
			await play.click()
			played = await waitUntil(() => page.evaluate(() => [...document.querySelectorAll('.training-active .qmedia audio')].some((a) => !a.paused)), 1500, 50)
			;(rec.current.notes ||= []).push(`flow: sound of the first question playing at "Next Question": ${played}`)
			await page.mouse.move(rest.x, rest.y)
		}
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
		await card().locator('.next-btn').first().focus()
		await page.evaluate(() => window.__personaFlowArm())
		await page.keyboard.press('Enter')
		await card().locator('.question-text').filter({ hasNotText: first }).first().waitFor({ state: 'attached', timeout: 15000 })
		await page.waitForTimeout(1000)
		const second = (await card().locator('.question-text').first().textContent()).trim()
		if (second === first || !flow.questions.includes(second)) throw new Error(`after "Next Question" Training shows "${second}"`)
		const flash = await page.evaluate(() => (window.__personaFlow || []).find((s) => s.opacity > 0.05) || null)
		await page.evaluate(() => window.__personaFlowObserver?.disconnect())
		if (flash) rec.add('media', `flow: the second question shows before its intro (${JSON.stringify(flash)})`)
		if (!(await isHidden())) { rec.add('media', 'flow: the second question is not held hidden for its intro'); return }
		if (played) {
			const still = await page.evaluate(() => [...document.querySelectorAll('.training-active audio')].filter((a) => !a.paused).length)
			if (still) rec.add('media', `flow: the first question's sound plays on under the second (${still} playing)`)
		}
		const box = await conceal().boundingBox()
		const inside = box && rest.x > box.x && rest.x < box.x + box.width && rest.y > box.y && rest.y < box.y + box.height
		if (!inside) throw new Error(`flow: the resting pointer ${JSON.stringify(rest)} is not inside the second hidden question ${JSON.stringify(box)}`)
		// the pointer moves a little where it already is: no mouseenter, only movement inside
		await page.mouse.move(rest.x + 6, rest.y + 4, { steps: 2 })
		await page.waitForTimeout(150)
		if (!(await isHidden())) (rec.current.notes ||= []).push('flow: uncovered within 150 ms of moving (no rest)')
		await page.mouse.move(rest.x + 2, rest.y + 6)
		if (!(await waitUntil(async () => !(await isHidden()), 2500, 100))) rec.add('media', 'flow: a pointer resting inside the second hidden question does not uncover it (it never "enters")')
		await page.mouse.move(0, 0)
		await page.screenshot({ path: path.join(SHOTS, 'media-question-second.png') })
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
			const c = control.getBoundingClientRect()
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
	flush()
	await context.close()
})

for (const [persona, opts] of [['admin', {}], ['instructor', {}], ['learner2', {}], ['admin-studentview', { studentView: true }]]) {
	test(`sweep: ${persona}`, async ({ browser }) => {
		test.setTimeout(45 * 60_000)
		await sweepPersona(browser, persona, opts)
		expect(true).toBe(true)
	})
}
