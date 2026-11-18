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
const DENY = /activat|make (instructor|student|learner)|promot|demot|delet|remov|deselect|abwählen|revok|withdraw|unenrol|unenroll|leave|archiv|merge|reset|log ?out|sign out|generat|rotat|start exam|start the exam|practice exam|begin|abort|end exam|uninstall|discard|clear|close course|close panel|minimi[sz]e panel|import|upload|löschen|entfern|widerruf|austragen|zurücksetzen/i
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
async function runAxe(page) {
	const hasAxe = await page.evaluate(() => typeof window.axe === 'object').catch(() => false)
	if (!hasAxe) await page.evaluate(AXE_SOURCE)
	const results = await page.evaluate(async () => {
		const root = document.querySelector('#app-content') || document.querySelector('#content') || document.body
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
	})
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
	await station(page, rec, 'Learning materials (learner): confirm both required documents', async () => {
		await enterMaterials()
		const n = await confirmButtons().count()
		for (let i = 0; i < n; i++) {
			const btn = confirmButtons().nth(i)
			if (await btn.isDisabled().catch(() => true)) continue
			await btn.click()
			await page.waitForTimeout(800)
		}
		await enterMaterials() // read back: the confirmation is stored, not only shown
		const labels = (await confirmButtons().allTextContents()).map((s) => s.trim())
		const open = labels.filter((l) => l !== 'Confirmed as read')
		if (open.length) rec.add('materials', `after confirming and reloading, ${open.length} of ${labels.length} required documents are not confirmed: ${labels.join(', ')}`)
		if (mats.examGated && !(await page.locator('[data-test="required-content-unlocked"]').isVisible().catch(() => false))) {
			rec.add('materials', 'all required documents confirmed, but the exam is not shown as unlocked')
		}
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
			page.__acceptDialogs = true
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
				const pick = pickAnswerIndex(await page.locator('.answer-btn').allTextContents(), answer)
				if (pick === -1) throw new Error(`no answer button "${answer}" for question "${text}"`)
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
			page.__acceptDialogs = false
			const banner = (await page.locator('.result-banner__status').textContent().catch(() => '')).trim()
			if (banner !== 'Passed') rec.add('exam', `${pool}: result banner says "${banner}" after all answers right`)
			// "Passed" alone let a wrong click through (7 of 8 is still over the pass mark).
			const score = (await page.locator('.results-screen .stat-value').first().textContent().catch(() => '')).trim()
			const sm = score.match(/(\d+)\s*\/\s*(\d+)/)
			if (!sm || sm[1] !== sm[2]) rec.add('exam', `${pool}: ${score || 'no score'} correct after all answers right`)
		})
	}
	await station(page, rec, 'Exam result: certificate notice', async () => {
		const open = page.getByRole('button', { name: 'Open certificate' })
		if (!(await open.isVisible().catch(() => false))) rec.add('certificate', 'no "Open certificate" after passing both required pools')
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
// intro and a dialog (seed: fixture demo-q1 in the optional pool "Question media demo"). learner3
// answers it in Training, wrong on purpose, so that the feedback for the chosen option shows.
test('learner practises the media question: hint, intro, feedback, dialog', async ({ browser }) => {
	test.setTimeout(10 * 60_000)
	const media = seed.media
	const rec = new Recorder('learner3')
	const { context, page } = await login(browser, 'learner3')
	rec.attach(page)
	const card = () => page.locator('.training-active .question-card').first()
	await station(page, rec, 'Training: media question before answering (hint, intro)', async () => {
		await openMegaTab(page, 'Learning space')
		await page.locator('.lernraum-subnav button').filter({ hasText: 'Training' }).first().click()
		await settle(page)
		await page.locator('#app-content .pool-item').filter({ hasText: media.poolName }).first().click()
		await settle(page)
		await page.getByRole('button', { name: 'Start Training' }).first().click()
		await page.locator('.question-text').first().waitFor({ timeout: 15000 })
		const text = (await page.locator('.question-text').first().textContent()).trim()
		if (text !== media.question) throw new Error(`Training shows "${text}", not the media question`)
		const hint = card().locator('.question-hint')
		if (!(await hint.isVisible().catch(() => false))) rec.add('media', 'no hint before answering')
		else {
			await hint.locator('button').first().click()
			await page.waitForTimeout(300)
			if (!(await hint.locator('.question-hint__text').isVisible().catch(() => false))) rec.add('media', 'the hint does not open')
		}
		const player = card().locator('.qmedia')
		await player.waitFor({ timeout: 10000 }).catch(() => rec.add('media', 'no intro player before answering'))
		if (await player.isVisible().catch(() => false)) {
			const title = (await player.locator('.qmedia__title').textContent().catch(() => '')).trim()
			if (title !== 'Intro') rec.add('media', `player title before answering is "${title}", not "Intro"`)
			const play = player.locator('.qmedia__button--primary')
			if (await play.isDisabled().catch(() => true)) rec.add('media', 'the intro cannot be played (Play disabled)')
			else {
				await play.click()
				await page.waitForTimeout(1500)
				const note = (await player.locator('.qmedia__note').textContent().catch(() => '')).trim()
				if (note) rec.add('media', `intro audio: ${note}`)
				await play.click().catch(() => {}) // pause
			}
		}
	})
	await station(page, rec, 'Training: media question answered wrong (feedback, dialog)', async () => {
		const pick = pickAnswerIndex(await page.locator('.answer-btn').allTextContents(), media.wrongAnswer)
		if (pick === -1) throw new Error(`no answer button "${media.wrongAnswer}"`)
		await page.locator('.answer-btn').nth(pick).click()
		await page.locator('.answer-feedback').first().waitFor({ timeout: 15000 })
		await settle(page)
		const feedback = card().locator('.rich-feedback')
		if (!(await feedback.isVisible().catch(() => false))) rec.add('media', 'no feedback per option after answering')
		else {
			const chosen = (await feedback.locator('.rich-feedback__option--wrong').first().textContent().catch(() => '')).replace(/\s+/g, ' ')
			if (!chosen.includes(media.wrongAnswer)) rec.add('media', `the wrong option's feedback does not name "${media.wrongAnswer}": ${chosen.slice(0, 120)}`)
			if (!(await feedback.locator('.rich-feedback__takeaway').isVisible().catch(() => false))) rec.add('media', 'no key takeaway after answering')
		}
		if (await card().locator('.question-hint').isVisible().catch(() => false)) rec.add('media', 'the hint is still shown after answering')
		const player = card().locator('.qmedia')
		if (!(await player.isVisible().catch(() => false))) rec.add('media', 'no dialog player after answering')
		else {
			const title = (await player.locator('.qmedia__title').textContent().catch(() => '')).trim()
			if (title !== 'Debrief') rec.add('media', `player title after answering is "${title}", not "Debrief"`)
			// Usable without sound: step to the second line and check a caption shows.
			const next = player.getByRole('button', { name: /Next line/ })
			await next.click().catch(() => rec.add('media', 'no "Next line" in the dialog'))
			await next.click().catch(() => {})
			await page.waitForTimeout(400)
			const caption = (await player.locator('.qmedia__caption').textContent().catch(() => '')).trim()
			if (!caption) rec.add('media', 'stepping through the dialog shows no caption')
			await player.locator('.qmedia__transcript summary').click().catch(() => {})
			await page.waitForTimeout(300)
		}
	})
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

for (const [persona, opts] of [['admin', {}], ['instructor', {}], ['learner2', {}], ['admin-studentview', { studentView: true }]]) {
	test(`sweep: ${persona}`, async ({ browser }) => {
		test.setTimeout(45 * 60_000)
		await sweepPersona(browser, persona, opts)
		expect(true).toBe(true)
	})
}
