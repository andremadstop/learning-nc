/**
 * GitHub #36: with the admin's gamification switch off, the course views still showed XP, level,
 * streak, badges and a leaderboard (and Leitner boxes in courses without Leitner).
 *
 * The switch now comes from GET /api/settings/personal (App.vue) and travels as the prop
 * gamificationEnabled. Every component here is rendered for real: the assertions are on the DOM
 * the learner or instructor sees, both with the switch off and — the IT courses that must not
 * change — with it on.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { routes: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => Promise.resolve({ data: api.routes[url] ?? {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'learner', displayName: 'Lena' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcTextField', () => stub('NcTextField'))

vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/CertPoolResult.vue', () => stub('CertPoolResult'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/CourseMemberPicker.vue', () => stub('CourseMemberPicker'))
vi.mock('../../src/components/LeagueTab.vue', () => stub('LeagueTab'))
vi.mock('../../src/components/ArenaSelector.vue', () => stub('ArenaSelector'))
vi.mock('../../src/components/DuelMode.vue', () => stub('DuelMode'))
vi.mock('../../src/components/GameshowMode.vue', () => stub('GameshowMode'))
vi.mock('../../src/components/AbenteuerMode.vue', () => stub('AbenteuerMode'))
vi.mock('../../src/components/CourseTabLernraum.vue', () => stub('CourseTabLernraum'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => stub('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => stub('CourseTabVerwaltung'))
vi.mock('../../src/components/ExamReadiness.vue', () => stub('ExamReadiness'))

import StudentDetail from '../../src/components/StudentDetail.vue'
import Klassenbuch from '../../src/components/Klassenbuch.vue'
import CourseSummary from '../../src/components/CourseSummary.vue'
import CourseTabTeilnehmer from '../../src/components/CourseTabTeilnehmer.vue'
import CourseTabWettbewerb from '../../src/components/CourseTabWettbewerb.vue'
import CourseDetail from '../../src/components/CourseDetail.vue'
import { wettbewerbTabIds } from '../../src/utils/courseView.js'
import { summaryToMarkdown } from '../../src/utils/summary-export.js'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app

async function render(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	const instanceRef = ref(null)
	app = createApp({ render: () => h(component, { ref: instanceRef, ...props }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return instanceRef.value
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

beforeEach(() => {
	api.routes = {}
})

const modeConfig = (overrides = {}) => ({
	training: true, leitner: true, exam: true, duel: true, gameshow: true, league: true, abenteuer: false, course_summary: true, tools: true, ...overrides,
})

// ── My progress / instructor drill-down ───────────────────────────────────────

function studentDetailResponse(overrides = {}) {
	return {
		user_id: 'learner',
		display_name: 'Lena',
		xp: { level: 4, total_xp: 1234, level_progress_pct: 40, xp_to_next_level: 300, xp_in_level: 100 },
		streak: { current_streak: 0, last_activity_date: null },
		badges: [{ badge_id: 'pioneer', name: 'Pioneer', emoji: '*', earned: false, legacy: false }],
		badge_progress: [],
		pools: [{ pool_id: 3, pool_name: 'Brandschutz', boxes: { box_1: 0 }, total: 0, mastered: 0, mastery_pct: 0, accuracy: 85 }],
		recent_sessions: [],
		leitner_enabled: true,
		stats: { total_sessions: 7, total_mastered: 2, enrolled_at: 1, last_activity_date: '2026-03-04' },
		...overrides,
	}
}

describe('StudentDetail (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/students/{studentId}'

	it('shows no level, XP, streak or badges with gamification off, but the last activity', async () => {
		api.routes[url] = studentDetailResponse()
		await render(StudentDetail, { courseId: 5, studentId: 'learner', gamificationEnabled: false })
		const text = host.textContent

		expect(host.querySelector('.level-pill')).toBeNull()
		expect(host.querySelector('.xp-bar-container')).toBeNull()
		expect(text).not.toContain('XP')
		expect(text).not.toContain('Badges (')
		expect(text).toContain('7 Sessions')
		expect(text).toContain('Last active:')
	})

	it('keeps the full gamification profile with gamification on', async () => {
		api.routes[url] = studentDetailResponse()
		await render(StudentDetail, { courseId: 5, studentId: 'learner', gamificationEnabled: true })

		expect(host.querySelector('.level-pill').textContent).toContain('Lv.4')
		expect(host.querySelector('.xp-bar-container')).not.toBeNull()
		expect(host.textContent).toContain('Badges (0/1)')
		expect(host.textContent).toContain('Leitner Boxes per Pool')
	})

	it('shows the accuracy per pool instead of empty Leitner boxes when the course has no Leitner', async () => {
		api.routes[url] = studentDetailResponse({ leitner_enabled: false })
		await render(StudentDetail, { courseId: 5, studentId: 'learner' })

		expect(host.textContent).not.toContain('Leitner Boxes per Pool')
		expect(host.querySelector('.leitner-boxes')).toBeNull()
		expect(host.textContent).not.toContain('Mastered')
		expect(host.textContent).toContain('Genauigkeit pro Pool')
		expect(host.querySelector('.pool-accuracy-item').textContent).toContain('85%')
	})
})

// ── Classbook ─────────────────────────────────────────────────────────────────

describe('Klassenbuch (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/classbook'
	const payload = {
		profiles: [{ user_id: 'teacher', display_name: 'Tom', role: 'instructor', level: 6, kudos_received_count: 0, is_self: false }],
		current_user_id: 'learner',
		kudos_remaining: 3,
	}

	it('shows no level with gamification off', async () => {
		api.routes[url] = payload
		await render(Klassenbuch, { courseId: 5, gamificationEnabled: false })
		expect(host.textContent).toContain('Tom')
		expect(host.querySelector('.squad-level')).toBeNull()
	})

	it('shows the level with gamification on', async () => {
		api.routes[url] = payload
		await render(Klassenbuch, { courseId: 5 })
		expect(host.querySelector('.squad-level').textContent).toContain('Level 6')
	})
})

// ── Completion tab ────────────────────────────────────────────────────────────

describe('CourseSummary (GitHub #36)', () => {
	const summary = {
		mastery: { total_mastered: 3, total_questions: 10, mastery_rate: 30 },
		sessions: { total_sessions: 4, total_questions: 40, overall_accuracy: 80 },
		xp: { total_xp: 999, current_level: 5 },
		streak: { current_streak: 2, longest_streak: 9 },
		duels: { wins: 1, total_duels: 2, win_rate: 50 },
		badges: [{ badge_id: 'pioneer', name: 'Pioneer', emoji: '*', earned: true }],
		swarm: {},
		trouble_spots: [],
	}

	function cards() {
		return Array.from(host.querySelectorAll('.card-kicker')).map((el) => el.textContent.trim())
	}

	async function renderSummary(props, courseOverrides = {}) {
		api.routes['/apps/learning/api/courses/{courseId}/summary'] = summary
		api.routes['/apps/learning/api/courses/5/pass-status'] = { applicable: false }
		return render(CourseSummary, {
			courseId: 5,
			courseName: 'Arbeitsschutz',
			course: { id: 5, mode_config: modeConfig(courseOverrides) },
			...props,
		})
	}

	it('leaves out XP, streak, duels and badges with gamification off', async () => {
		await renderSummary({ gamificationEnabled: false })
		const shown = cards()
		expect(shown).toContain('Sessions & Genauigkeit')
		expect(shown).not.toContain('XP & Level')
		expect(shown).not.toContain('Streak')
		expect(shown).not.toContain('Duell-Win-Rate')
		expect(shown).not.toContain('Badge-Galerie')
		expect(host.querySelector('.stats-grid').classList.contains('grid--single')).toBe(true)
	})

	it('leaves out the box-5 mastery when the course has no Leitner', async () => {
		await renderSummary({}, { leitner: false })
		expect(cards()).not.toContain('Meisterschaft')
		expect(cards()).toContain('XP & Level')
	})

	it('keeps every card in a course with gamification and Leitner', async () => {
		await renderSummary({})
		expect(cards()).toEqual(expect.arrayContaining(['Meisterschaft', 'XP & Level', 'Streak', 'Duell-Win-Rate', 'Badge-Galerie']))
		expect(host.querySelector('.stats-grid').classList.contains('grid--single')).toBe(false)
	})

	// GitHub #36: the swarm feeds the AI assistant's knowledge
	it('shows the swarm counters in the snapshot card only with AI on', async () => {
		await renderSummary({ aiEnabled: false })
		// GitHub #54: without a certificate and without AI the snapshot card has nothing left.
		expect(host.querySelector('.snapshot-card')).toBeNull()
		expect(host.querySelector('.snapshot-inline-stats')).toBeNull()
		app.unmount()
		host.remove()

		await renderSummary({ aiEnabled: true })
		expect(host.querySelector('.snapshot-inline-stats')?.textContent).toContain('Schwarm-Beiträge')
	})
})

describe('summaryToMarkdown (GitHub #36)', () => {
	const summary = {
		mastery: { total_mastered: 3, total_questions: 10, mastery_rate: 30 },
		sessions: { total_sessions: 4 },
		xp: { total_xp: 999, current_level: 5 },
		streak: { longest_streak: 9 },
		duels: { wins: 1, total_duels: 2, win_rate: 50 },
		badges: [{ badge_id: 'pioneer', name: 'Pioneer', earned: true }],
	}

	it('writes no XP, level, streak, badges or duels with gamification off', () => {
		const md = summaryToMarkdown(summary, 'Kurs', 'Lena', { gamificationEnabled: false })
		expect(md).not.toContain('XP')
		expect(md).not.toContain('Level')
		expect(md).not.toContain('Streak')
		expect(md).not.toContain('Pioneer')
		expect(md).not.toContain('Duelle')
		expect(md).toContain('Gemeistert')
	})

	it('writes no box-5 mastery without Leitner', () => {
		expect(summaryToMarkdown(summary, 'Kurs', 'Lena', { leitnerEnabled: false })).not.toContain('Gemeistert')
	})

	it('keeps everything without options', () => {
		const md = summaryToMarkdown(summary, 'Kurs', 'Lena')
		expect(md).toContain('999 XP')
		expect(md).toContain('Pioneer')
		expect(md).toContain('Duelle')
	})
})

// ── Instructor progress table ─────────────────────────────────────────────────

describe('CourseTabTeilnehmer progress table (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/progress'

	async function renderProgress(props) {
		api.routes[url] = { students: [{ user_id: 'learner', display_name: 'Lena', current_level: 4, total_xp: 1234, critical_cards_count: 0, overall_mastery: 10, pools: [] }], meta: { total: 1 } }
		return render(CourseTabTeilnehmer, {
			courseId: 5,
			course: { id: 5, is_instructor: true, mode_config: modeConfig() },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'progress',
			...props,
		})
	}

	function headers() {
		return Array.from(host.querySelectorAll('.progress-table th')).map((th) => th.textContent.trim())
	}

	async function requestedSort() {
		const axios = (await import('@nextcloud/axios')).default
		const call = axios.get.mock.calls.filter(([u]) => u === url).pop()
		return call?.[1]?.params
	}

	it('hides Level and XP and sorts by name with gamification off', async () => {
		await renderProgress({ gamificationEnabled: false })
		expect(headers().some((h) => h.startsWith('Level'))).toBe(false)
		expect(headers().some((h) => h.startsWith('XP'))).toBe(false)
		expect(host.querySelector('.level-pill')).toBeNull()
		expect(await requestedSort()).toMatchObject({ sortKey: 'user_id', sortDir: 'asc' })
	})

	it('keeps Level and XP and the XP sort with gamification on', async () => {
		await renderProgress({})
		expect(headers().some((h) => h.startsWith('Level'))).toBe(true)
		expect(headers().some((h) => h.startsWith('XP'))).toBe(true)
		expect(await requestedSort()).toMatchObject({ sortKey: 'total_xp', sortDir: 'desc' })
	})
})

// ── Competition ───────────────────────────────────────────────────────────────

describe('wettbewerbTabIds (GitHub #36)', () => {
	const course = (overrides) => ({ mode_config: modeConfig(overrides) })

	// GitHub #51/#53: League, Arena and Adventure are game modes, gone with gamification off.
	it('leaves learners nothing with gamification off, whatever the mode switches say', () => {
		expect(wettbewerbTabIds(course({ abenteuer: true }), { gamificationEnabled: false })).toEqual([])
		expect(wettbewerbTabIds(course({ league: false, duel: false, gameshow: false }), { gamificationEnabled: false })).toEqual([])
	})

	it('is unchanged for learners with gamification on', () => {
		expect(wettbewerbTabIds(course(), {})).toEqual(['leaderboard', 'league', 'arena'])
		expect(wettbewerbTabIds(course({ abenteuer: true }), {})).toEqual(['leaderboard', 'league', 'arena', 'abenteuer'])
	})

	it('gives instructors only the switched-on modes, and the leaderboard', () => {
		// GitHub #51: Arena with Duel and Gameshow off was an empty page for the instructor.
		expect(wettbewerbTabIds(course({ duel: false, gameshow: false, league: false }), { actsAsInstructor: true })).toEqual(['leaderboard'])
		// GitHub #53: League and Adventure respected their switches only for learners.
		expect(wettbewerbTabIds(course({ league: false, abenteuer: false }), { actsAsInstructor: true })).toEqual(['leaderboard', 'arena'])
		// With gamification off the instructor keeps the leaderboard ranked by mastered cards (#36).
		expect(wettbewerbTabIds(course({ abenteuer: true }), { actsAsInstructor: true, gamificationEnabled: false })).toEqual(['leaderboard'])
		// An IT course with every mode on looks as before.
		expect(wettbewerbTabIds(course({ abenteuer: true }), { actsAsInstructor: true })).toEqual(['leaderboard', 'league', 'arena', 'abenteuer'])
	})
})

describe('CourseTabWettbewerb (GitHub #36)', () => {
	it('opens the first remaining sub-tab instead of a switched-off one', async () => {
		await render(CourseTabWettbewerb, {
			courseId: 5,
			course: { id: 5, is_instructor: true, mode_config: modeConfig({ league: false }) },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'league',
		})
		const pills = Array.from(host.querySelectorAll('.wettbewerb-pill')).map((b) => b.textContent.trim())
		expect(pills).toEqual(['Rangliste', 'Arena'])
		expect(host.querySelector('.league-section')).toBeNull()
	})
})

describe('CourseTabWettbewerb instructor leaderboard (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/leaderboard'

	async function renderLeaderboard(props, modeOverrides = {}) {
		api.routes[url] = { leaderboard: [{ user_id: 'learner', display_name: 'Lena', rank: 1, current_level: 4, total_xp: 1234, total_mastered: 3, current_streak: 2, total_sessions: 5 }], meta: { total: 1 } }
		return render(CourseTabWettbewerb, {
			courseId: 5,
			course: { id: 5, is_instructor: true, mode_config: modeConfig(modeOverrides) },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'leaderboard',
			...props,
		})
	}

	function headers() {
		return Array.from(host.querySelectorAll('.leaderboard-table th')).map((th) => th.textContent.trim())
	}

	async function requestedParams() {
		const axios = (await import('@nextcloud/axios')).default
		return axios.get.mock.calls.filter(([u]) => u === url).pop()?.[1]?.params
	}

	it('hides Level, XP and Streak and ranks by mastered cards with gamification off', async () => {
		await renderLeaderboard({ gamificationEnabled: false })
		const shown = headers()
		expect(shown.some((h) => h.startsWith('Level'))).toBe(false)
		expect(shown.some((h) => h.startsWith('XP'))).toBe(false)
		expect(shown.some((h) => h.startsWith('Streak'))).toBe(false)
		expect(shown.some((h) => h.startsWith('Gemeistert'))).toBe(true)
		expect(host.querySelector('.level-pill')).toBeNull()
		expect(await requestedParams()).toMatchObject({ sortKey: 'total_mastered', sortDir: 'desc' })
	})

	it('ranks by sessions when the course has no Leitner either', async () => {
		await renderLeaderboard({ gamificationEnabled: false }, { leitner: false })
		expect(await requestedParams()).toMatchObject({ sortKey: 'total_sessions' })
	})

	it('is unchanged with gamification on', async () => {
		await renderLeaderboard({})
		const shown = headers()
		expect(shown.some((h) => h.startsWith('Level'))).toBe(true)
		expect(shown.some((h) => h.startsWith('Streak'))).toBe(true)
		expect(await requestedParams()).toMatchObject({ sortKey: 'total_xp' })
	})
})

describe('CourseDetail competition area (GitHub #36)', () => {
	function megaLabels() {
		return Array.from(host.querySelectorAll('.mega-tab-button')).map((b) => b.textContent.trim())
	}

	async function renderCourse(props, modeOverrides) {
		api.routes['/apps/learning/api/courses/{courseId}'] = { id: 5, title: 'Kurs', status: 'active', is_instructor: false, mode_config: modeConfig(modeOverrides), pools: [], members: [] }
		api.routes['/apps/learning/api/settings/tools'] = { enabled_tools: null }
		return render(CourseDetail, { courseId: 5, userRole: 'student', ...props })
	}

	it('hides Wettbewerb for learners when gamification is off and no competition mode is left', async () => {
		await renderCourse({ gamificationEnabled: false }, { league: false, duel: false, gameshow: false })
		expect(megaLabels()).toEqual(['Lernraum', 'Teilnehmer', 'Abschluss', 'Kommunikation'])
	})

	it('hides Wettbewerb for learners with gamification off even with league and arena switched on (#51)', async () => {
		await renderCourse({ gamificationEnabled: false }, {})
		expect(megaLabels()).not.toContain('Wettbewerb')
	})

	it('keeps Wettbewerb in an IT course with gamification on', async () => {
		await renderCourse({}, { league: false, duel: false, gameshow: false })
		expect(megaLabels()).toEqual(['Lernraum', 'Teilnehmer', 'Abschluss', 'Wettbewerb', 'Kommunikation'])
	})

	it('a deep link to the hidden leaderboard or the hidden area lands on the Lernraum', async () => {
		const detail = await renderCourse({ gamificationEnabled: false }, { league: false, duel: false, gameshow: false })
		for (const tab of ['leaderboard', 'wettbewerb']) {
			detail.activeMegaTab = 'teilnehmer'
			detail.currentTab = 'my-progress'
			detail.syncCurrentTabFromRoute(tab)
			await flush()
			expect(detail.activeMegaTab).toBe('lernraum')
			expect(detail.currentTab).toBe(detail.defaultLernraumTab())
		}
	})

	it('hands the switch down to My progress (CourseDetail -> CourseTabTeilnehmer -> StudentDetail)', async () => {
		for (const enabled of [false, true]) {
			api.routes['/apps/learning/api/courses/{courseId}/students/{studentId}'] = studentDetailResponse()
			const detail = await renderCourse({ gamificationEnabled: enabled }, {})
			detail.syncCurrentTabFromRoute('my-progress')
			await flush()
			expect(host.querySelector('.student-header-card'), 'My progress rendered').not.toBeNull()
			expect(host.querySelector('.level-pill') !== null).toBe(enabled)
			app.unmount()
			host.remove()
			app = null
		}
	})

	// Review of #40: a bookmark on a Kommunikation leaf the learner does not have stayed the
	// current tab with no area around it; it now opens the feed.
	it('a deep link to hidden learning partners, swarm or an instructor leaf opens the feed', async () => {
		const detail = await renderCourse({}, { buddies: false })
		for (const tab of ['buddies', 'schwarm', 'announcements', 'requests']) {
			detail.activeMegaTab = 'lernraum'
			detail.currentTab = detail.defaultLernraumTab()
			detail.syncCurrentTabFromRoute(tab)
			await flush()
			expect(detail.activeMegaTab, tab).toBe('kommunikation')
			expect(detail.currentTab, tab).toBe('feed')
		}
	})

	it('a deep link to learning partners still opens them while the switch is on', async () => {
		const detail = await renderCourse({}, {})
		detail.syncCurrentTabFromRoute('buddies')
		await flush()
		expect(detail.activeMegaTab).toBe('kommunikation')
		expect(detail.currentTab).toBe('buddies')
	})

	it('a deep link to the leaderboard still opens it with gamification on', async () => {
		const detail = await renderCourse({}, {})
		detail.syncCurrentTabFromRoute('leaderboard')
		await flush()
		expect(detail.activeMegaTab).toBe('wettbewerb')
		expect(detail.currentTab).toBe('leaderboard')
	})
})

// ── Fresh-eyes review of the persona run "off" (GitHub #36) ──────────────────

describe('Fresh-eyes review: Klassenbuch kudos (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/classbook'
	const payload = {
		profiles: [{ user_id: 'colleague', display_name: 'Cleo', role: 'student', level: 2, kudos_received_count: 1, is_self: false }],
		current_user_id: 'learner',
		kudos_remaining: 3,
	}

	it('shows no kudos counter, kudos button or kudos question with gamification off', async () => {
		api.routes[url] = payload
		await render(Klassenbuch, { courseId: 5, gamificationEnabled: false })
		expect(host.textContent).toContain('Cleo')
		expect(host.querySelector('.kudos-remaining')).toBeNull()
		expect(host.querySelector('.squad-footer')).toBeNull()
		expect(host.textContent).not.toContain('Kudos')
	})

	it('keeps kudos with gamification on', async () => {
		api.routes[url] = payload
		await render(Klassenbuch, { courseId: 5 })
		expect(host.querySelector('.kudos-remaining').textContent).toContain('3 Kudos')
		expect(host.querySelector('.squad-footer').textContent).toContain('1 Kudos')
	})
})

describe('Fresh-eyes review: certification card frame (GitHub #36)', () => {
	async function renderCert(passStatus) {
		api.routes['/apps/learning/api/courses/{courseId}/summary'] = { sessions: {}, mastery: {}, badges: [], trouble_spots: [] }
		api.routes['/apps/learning/api/courses/5/pass-status'] = passStatus
		await render(CourseSummary, { courseId: 5, courseName: 'Pflichtschulung', course: { id: 5, mode_config: modeConfig({ course_summary: false }) }, gamificationEnabled: false })
		return host.querySelector('.zeugnisstatus-card')
	}

	it('is not green while the learner has not passed', async () => {
		const card = await renderCert({ applicable: true, passed: false })
		expect(card).not.toBeNull()
		expect(card.textContent).toContain('Noch nicht bestanden')
		expect(card.classList.contains('zeugnisstatus-card--passed')).toBe(false)
	})

	it('is green once passed', async () => {
		const card = await renderCert({ applicable: true, passed: true })
		expect(card.classList.contains('zeugnisstatus-card--passed')).toBe(true)
	})
})

describe('Fresh-eyes review: progress table and compliance exports (GitHub #36)', () => {
	async function renderTab(activeTab, courseOverrides = {}, courseFields = {}) {
		api.routes['/apps/learning/api/courses/{courseId}/progress'] = { students: [{ user_id: 'learner', display_name: 'Lena', critical_cards_count: 0, overall_mastery: 0, pools: [] }], meta: { total: 1 } }
		api.routes['/apps/learning/api/courses/{courseId}/at-risk'] = { at_risk: [{ user_id: 'learner', display_name: 'Lena', risk_level: 'medium', risk_reason_codes: [{ code: 'never_active' }], critical_cards_count: 0, accuracy: null }] }
		return render(CourseTabTeilnehmer, {
			courseId: 5,
			course: { id: 5, is_instructor: true, cert_enabled: true, mode_config: modeConfig(courseOverrides), ...courseFields },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab,
			gamificationEnabled: false,
		})
	}

	it('says that the percentages are Leitner mastery in a course without Leitner', async () => {
		await renderTab('progress', { leitner: false })
		const note = host.querySelector('.progress-leitner-note')
		expect(note).not.toBeNull()
		expect(note.textContent).toContain('Dieser Kurs nutzt Leitner nicht')
		expect(note.textContent).toContain('Abschluss & Zertifikat')
	})

	it('names the area "Abschluss" when the course has no certificate', async () => {
		await renderTab('progress', { leitner: false }, { cert_enabled: false })
		const note = host.querySelector('.progress-leitner-note')
		expect(note).not.toBeNull()
		expect(note.textContent).toContain('„Abschluss“')
		expect(note.textContent).not.toContain('Zertifikat“')
	})

	it('shows no such note in a course with Leitner', async () => {
		await renderTab('progress')
		expect(host.querySelector('.progress-leitner-note')).toBeNull()
	})

	it('explains what puts a learner on the at-risk list', async () => {
		await renderTab('progress', { leitner: false })
		const hint = host.querySelector('.at-risk-hint')
		expect(hint).not.toBeNull()
		expect(hint.textContent).toContain('kritische Karten')
	})
})

describe('Fresh-eyes review: medals follow the ranking, not the sort direction (GitHub #36)', () => {
	const url = '/apps/learning/api/courses/{courseId}/leaderboard'
	const rows = [
		{ user_id: 'b', display_name: 'Bea', rank: 1, total_mastered: 0, total_sessions: 0 },
		{ user_id: 'a', display_name: 'Ada', rank: 2, total_mastered: 5, total_sessions: 4 },
	]

	async function renderBoard() {
		api.routes[url] = { leaderboard: rows, meta: { total: 2 } }
		const view = await render(CourseTabWettbewerb, {
			courseId: 5,
			course: { id: 5, is_instructor: true, mode_config: modeConfig() },
			userRole: 'instructor',
			actsAsInstructor: true,
			activeTab: 'leaderboard',
		})
		return view
	}

	it('gives medals when sorted best-first', async () => {
		await renderBoard()
		expect(host.querySelectorAll('.rank-medal').length).toBe(2)
	})

	it('gives no gold medal to the last place of an ascending sort', async () => {
		const view = await renderBoard()
		view.leaderboardSortKey = 'total_sessions'
		view.leaderboardSortAsc = true
		await flush()
		expect(host.querySelectorAll('.rank-medal').length).toBe(0)
	})

	it('gives no medals when sorted by name', async () => {
		const view = await renderBoard()
		view.leaderboardSortKey = 'user_id'
		view.leaderboardSortAsc = false
		await flush()
		expect(host.querySelectorAll('.rank-medal').length).toBe(0)
	})
})
