/**
 * #37 / #39: status badges and marks in the compliance flow must be readable.
 *
 * Nextcloud 33–35 defines --color-success|warning|error|info as BACKGROUND
 * tints (light #D8F3DA, dark #11321A …). Text on them needs --color-X-text,
 * borders/strokes need --color-element-X (or --color-border-X). Using the tint
 * itself as text colour gave 1.1–1.4:1 instead of the WCAG AA 4.5:1.
 *
 * This test reads the scoped <style> of the touched components and checks
 * exactly the selectors fixed for #37 — not the whole file (the remaining
 * occurrences elsewhere in the app are tracked separately).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const TINT = '(?:success|warning|error|info)'

function styleOf(file) {
	const sfc = readFileSync(resolve(__dirname, '../../src/components', file), 'utf8')
	const blocks = [...sfc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1])
	return blocks.join('\n').replace(/\/\*[\s\S]*?\*\//g, '')
}

/** All declarations of every rule whose selector list contains `selector`. */
function declsFor(css, selector) {
	const out = {}
	let hits = 0
	for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selectors = m[1].split(',').map(s => s.trim().replace(/\s+/g, ' '))
		if (!selectors.includes(selector)) continue
		hits++
		for (const decl of m[2].split(';')) {
			const i = decl.indexOf(':')
			if (i < 0) continue
			out[decl.slice(0, i).trim()] = decl.slice(i + 1).trim()
		}
	}
	return hits ? out : null
}

// [file, selector, kind]
//   pill: tinted background + readable text colour on it
//   text: text directly on page/card
//   line: border/stroke only (non-text, 3:1)
const CASES = [
	// 37-a / 39-f — Lernraum pool badges (instructor + student view)
	['CourseTabLernraum.vue', '.required-badge', 'pill'],
	['CourseTabLernraum.vue', '.required-enforced-badge', 'pill'],
	['CourseTabLernraum.vue', '.exam-relevant-badge', 'pill'],
	['CourseTabLernraum.vue', '.remove-pool-btn:hover', 'text'],
	// 37-h — Lernraum videos + curriculum hint
	['CourseTabLernraum.vue', '.video-item__status--done', 'pill'],
	['CourseTabLernraum.vue', '.video-item__status--pending', 'pill'],
	['CourseTabLernraum.vue', '.curriculum-saved-hint', 'text'],
	// 37-b / 37-c — completion & certificate
	['CourseSummary.vue', '.summary-toast', 'pill'],
	['CourseSummary.vue', '.zeugnis-passed', 'text'],
	['CourseSummary.vue', '.zeugnis-not-passed', 'text'],
	// #39: the per-pool result moved into the component shared with the Exam tab
	['CertPoolResult.vue', '.cert-pool-result__state--passed', 'text'],
	['CourseSummary.vue', '.trouble-accuracy', 'text'],
	['CourseSummary.vue', '.zeugnisstatus-card', 'line'],
	['CourseSummary.vue', '.narrative-card', 'line'],
	// 37-d — pool cards
	['PoolList.vue', '.pool-meta-badge.chapter', 'pill'],
	['PoolList.vue', '.permission-badge.edit', 'pill'],
	// 37-e — "Active"
	['CourseList.vue', '.status-badge.active', 'pill'],
	['CourseDetail.vue', '.status-badge.active', 'pill'],
	['InstructorDashboard.vue', '.status-badge.active', 'pill'],
	['InstructorDashboard.vue', '.card-students .summary-value', 'text'],
	['InstructorDashboard.vue', '.card-active .summary-value', 'text'],
	['InstructorDashboard.vue', '.card-students', 'line'],
	['InstructorDashboard.vue', '.card-active', 'line'],
	// 37-g — white text on a full tint
	['AdminSettings.vue', '.audit-overdue-warning', 'pill'],
	['QuestionList.vue', '.correct .answer-icon', 'pill'],
	// the answer text next to that icon (same element, was still the tint: 1.18:1 light)
	['QuestionList.vue', '.answer-item.correct', 'text'],
	['PbqPlacement.vue', '.pbq-hotspot', 'pill'],
	['PbqPlacement.vue', '.pbq-hotspot--assigned', 'pill'],
	['DailyChallengeCard.vue', '.dc-done-badge', 'pill'],
	['Klassenbuch.vue', '.role-pill.student', 'pill'],
	// 37-h — video gate, team lead, exam, participants
	['VideoPlayer.vue', '.video-player__gate--pending', 'pill'],
	['VideoPlayer.vue', '.video-player__gate--complete', 'pill'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__status-badge--overdue', 'pill'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__status-badge--missing', 'pill'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__status-badge--expired', 'pill'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__status-badge--passed', 'pill'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__remind-sent', 'text'],
	['TeamLeadDashboard.vue', '.teamlead-dashboard__error', 'text'],
	['ExamMode.vue', '.timer-green', 'pill'],
	['ExamMode.vue', '.timer-yellow', 'pill'],
	['ExamMode.vue', '.timer-red', 'pill'],
	['ExamMode.vue', '.lock-note', 'text'],
	['ExamMode.vue', '.timeout-note', 'text'],
	['ExamMode.vue', '.text-success', 'text'],
	['ExamMode.vue', '.text-error', 'text'],
	['ExamMode.vue', '.ra-correct-selected', 'pill'],
	['ExamMode.vue', '.ra-wrong-selected', 'pill'],
	['ExamMode.vue', '.ra-correct-missed', 'pill'],
	['ExamMode.vue', '.snake-line', 'line'],
	['ExamMode.vue', '.snake-warning .snake-line', 'line'],
	['ExamMode.vue', '.snake-danger .snake-line', 'line'],
	['CourseTabTeilnehmer.vue', 'td.mastery-high', 'pill'],
	['CourseTabTeilnehmer.vue', 'td.mastery-medium', 'pill'],
	['CourseTabTeilnehmer.vue', 'td.mastery-low', 'pill'],
	['CourseTabTeilnehmer.vue', '.critical-cards-high', 'pill'],
	['CourseTabTeilnehmer.vue', '.critical-cards-medium', 'pill'],
	['CourseTabTeilnehmer.vue', '.critical-cards-low', 'pill'],
	['CourseTabTeilnehmer.vue', '.at-risk-section', 'line'],
	['CourseTabTeilnehmer.vue', '.at-risk-title', 'text'],
	['CourseTabTeilnehmer.vue', '.at-risk-card.risk-high', 'line'],
	['CourseTabTeilnehmer.vue', '.at-risk-card.risk-medium', 'line'],
	['CourseTabTeilnehmer.vue', '.risk-badge.high', 'pill'],
	['CourseTabTeilnehmer.vue', '.risk-badge.medium', 'pill'],
	// review of #40 — learner question status in Kommunikation
	['CourseTabKommunikation.vue', '.ticket-status-cd.status-open', 'pill'],
	['CourseTabKommunikation.vue', '.ticket-status-cd.status-answered', 'pill'],
	// StudentDetail (pre-existing, same pattern): level badge and per-pool mastery
	['StudentDetail.vue', '.level-gold', 'pill'],
	['StudentDetail.vue', '.mastery-high-text', 'pill'],
	['StudentDetail.vue', '.mastery-medium-text', 'pill'],
	['StudentDetail.vue', '.mastery-low-text', 'pill'],
]

const TEXT_OK = new RegExp(`^var\\(--color-(${TINT})-text\\b`)
const BARE_TINT = new RegExp(`var\\(--color-${TINT}\\)`)
const LINE_PROPS = ['border', 'border-color', 'border-left', 'border-left-color', 'border-inline-start', 'border-inline-start-color', 'stroke']

describe('#37 status colours use the Nextcloud text/element pairs', () => {
	it.each(CASES)('%s %s (%s)', (file, selector, kind) => {
		const d = declsFor(styleOf(file), selector)
		expect(d, `${selector} not found in ${file}`).not.toBeNull()

		if (kind === 'pill' || kind === 'text') {
			expect(d.color, `${selector} color`).toMatch(TEXT_OK)
		}
		if (kind === 'pill') {
			// solid tint as background — no colour-mix fading it towards the page
			expect(d.background, `${selector} background`).toMatch(new RegExp(`^var\\(--color-${TINT}\\)$`))
			// the tint colour of the background and the -text colour must belong together
			const bg = d.background.match(new RegExp(`--color-(${TINT})`))[1]
			expect(d.color).toContain(`--color-${bg}-text`)
		}
		// borders and strokes: never the bare tint (it is near-invisible as a line)
		for (const prop of LINE_PROPS) {
			if (d[prop] !== undefined) {
				expect(d[prop], `${selector} ${prop}`).not.toMatch(BARE_TINT)
			}
		}
		if (kind === 'line') {
			const lines = LINE_PROPS.filter(p => d[p] !== undefined)
			expect(lines.length, `${selector} has a border/stroke`).toBeGreaterThan(0)
		}
	})

	it('CourseSummary card accents follow the reading direction (RTL)', () => {
		const css = styleOf('CourseSummary.vue')
		expect(css).not.toMatch(/border-left/)
	})
})

// WCAG relative luminance / contrast for the NC 33–35 theme values
// (apps/theming/lib/Themes/DefaultTheme.php + DarkTheme.php).
function lum(hex) {
	const c = hex.replace('#', '').match(/../g).map(h => parseInt(h, 16) / 255)
		.map(v => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
	return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
}
function contrast(a, b) {
	const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p)
	return (x + 0.05) / (y + 0.05)
}

const THEMES = {
	light: { page: '#ffffff', success: ['#D8F3DA', '#005416'], warning: ['#FFEEC5', '#664700'], error: ['#FFE7E7', '#8A0000'] },
	dark: { page: '#171717', success: ['#11321A', '#D5F2DC'], warning: ['#3D3010', '#FFEEC5'], error: ['#552121', '#FFCCCC'] },
}

describe('#37 the chosen pairs reach WCAG AA in the stock themes', () => {
	for (const [theme, v] of Object.entries(THEMES)) {
		for (const tint of ['success', 'warning', 'error']) {
			it(`${theme} ${tint}: X-text on X and on the page ≥ 4.5`, () => {
				const [bg, text] = v[tint]
				expect(contrast(text, bg)).toBeGreaterThanOrEqual(4.5)
				expect(contrast(text, v.page)).toBeGreaterThanOrEqual(4.5)
				// the old pattern (tint as text on the page) was far below
				expect(contrast(bg, v.page)).toBeLessThan(2)
			})
		}
	}
})
