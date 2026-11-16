// persona.sweep.cjs — the Persona-Gate: click through Learning as each role would.
//
// Order matters: the learner passes both exams BEFORE any generic sweep (a sweep that touched an
// exam would burn attempts and start the cooldown); exam-start buttons are on the deny list.
// Results go to out/gamification-<off|on>/ (the mode of the running instance): findings.json,
// shots/, downloads/. report.mjs turns findings.json into report.md and stations.json there.
const { test, expect } = require('@playwright/test')
const fs = require('fs')
const path = require('path')
const { expectedReason, axeFindings, stationText, pickAnswerIndex } = require('./lib.cjs')

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
// axe-core from app/node_modules (devDependency, pinned). Injected with page.evaluate: that runs
// over the DevTools protocol, outside the page's CSP. A <script> tag would be blocked by
// Nextcloud's nonce CSP, and bypassCSP would hide the CSP violations this sweep has caught before.
const AXE_SOURCE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
const BASE = state.URL
const APP = `${BASE}/index.php/apps/learning`
const COURSE_TITLE = 'Annual safety training'
const PASSWORDS = { admin: state.ADMIN_PASS, instructor: state.INSTRUCTOR_PASS, learner1: state.LEARNER_PASS, learner2: state.LEARNER_PASS, learner3: state.LEARNER_PASS }

// Buttons the sweep never clicks: destructive, irreversible, or they use up an exam attempt.
const DENY = /make (instructor|student|learner)|promot|demot|delet|remov|revok|withdraw|unenrol|unenroll|leave|archiv|merge|reset|log ?out|sign out|generat|rotat|start exam|start the exam|practice exam|begin|abort|end exam|uninstall|discard|clear|close course|close panel|minimi[sz]e panel|import|upload|löschen|entfern|widerruf|austragen|zurücksetzen/i
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
		this.ctx = { user: persona.replace(/-.*/, ''), studentView, poolOwner: seed.poolOwner }
		this.current = null
		this.requests = []
		this.toastsSeen = new Set()
	}

	attach(page) {
		this.page = page
		page.on('response', (r) => {
			const u = r.url()
			if (!isAppUrl(u)) return
			const req = r.request()
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
		const hits = new Set()
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
		let node
		while ((node = walker.nextNode())) {
			const text = node.textContent.replace(/\s+/g, ' ').trim()
			if (!text || !german.test(text)) continue
			const el = node.parentElement
			if (!el || el.closest('script,style,noscript,textarea') || !visible(el)) continue
			hits.add('text: ' + text.slice(0, 140))
		}
		for (const el of document.querySelectorAll('[placeholder],[title],[aria-label]')) {
			if (!visible(el)) continue
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

/** axe-core on the app's area: WCAG 2.x A/AA rules, violations only (see lib.cjs: axeFindings). */
async function runAxe(page) {
	const hasAxe = await page.evaluate(() => typeof window.axe === 'object').catch(() => false)
	if (!hasAxe) await page.evaluate(AXE_SOURCE)
	const violations = await page.evaluate(async () => {
		const root = document.querySelector('#app-content') || document.querySelector('#content') || document.body
		const res = await window.axe.run(root, {
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
			resultTypes: ['violations'],
		})
		return res.violations.map((v) => ({
			id: v.id, impact: v.impact, help: v.help,
			nodes: v.nodes.map((n) => ({ impact: n.impact, target: n.target, any: n.any.map((c) => ({ data: c.data })) })),
		}))
	})
	return axeFindings(violations)
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
	if (page.url() !== home) await reenter()
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
	if (persona.startsWith('admin')) {
		const enterAdmin = async () => { await page.goto(`${BASE}/index.php/settings/admin/learning`); await settle(page, 1200) }
		await station(page, rec, 'Admin settings', async () => { await enterAdmin(); await clickAll(page, rec, enterAdmin) })
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
			;(document.querySelector('#app-content') || document.body).prepend(el)
		})
		const hits = (await runAxe(page)).filter((f) => f.detail.startsWith('color-contrast') && f.detail.includes('#persona-axe-selftest'))
		await page.evaluate(() => document.getElementById('persona-axe-selftest')?.remove())
		rec.current.axeSelfTest = hits.length ? 'contrast check works' : 'CONTRAST CHECK BROKEN: the self-test element was not reported'
		if (!hits.length) rec.add('detector', rec.current.axeSelfTest)
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
	})
	await station(page, rec, 'Certificate: print path', async () => {
		await page.locator('.certificate-actions').getByRole('button', { name: /print/i }).first().click()
		await page.waitForTimeout(800)
		const printed = await page.evaluate(() => window.__personaPrinted || 0)
		if (!printed) rec.add('print', 'Print did not call window.print()')
		const rootText = await page.evaluate(() => document.querySelector('#learning-certificate-print-root')?.textContent?.trim().length || 0)
		if (rootText < 50) rec.add('print', `print copy #learning-certificate-print-root has ${rootText} characters`)
		await page.emulateMedia({ media: 'print' })
		await page.screenshot({ path: path.join(SHOTS, 'guide-certificate-print.png'), fullPage: true })
		await page.pdf({ path: path.join(DOWNLOADS, 'certificate-print.pdf'), format: 'A4', printBackground: true })
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
	await station(page, rec, 'Administration › Course rules › Certification', async () => {
		await openMegaTab(page, 'Administration')
		const heading = page.getByText('Certification', { exact: true }).first()
		await heading.scrollIntoViewIfNeeded()
		await page.waitForTimeout(400)
		await page.screenshot({ path: path.join(SHOTS, 'guide-certification.png') })
		await page.screenshot({ path: path.join(SHOTS, 'guide-course-rules-full.png'), fullPage: true })
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

for (const [persona, opts] of [['admin', {}], ['instructor', {}], ['learner2', {}], ['admin-studentview', { studentView: true }]]) {
	test(`sweep: ${persona}`, async ({ browser }) => {
		test.setTimeout(45 * 60_000)
		await sweepPersona(browser, persona, opts)
		expect(true).toBe(true)
	})
}
