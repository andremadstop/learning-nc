/**
 * GitHub #36: in a course with a completion tab (certificate or released summary), learners get
 * "Abschluss" as an area of its own, with the recent sessions that used to sit in "Mein
 * Fortschritt". The participants area disappears for learners when nothing is left in it, and
 * old bookmarks on the moved leaves land on the new place — also on the first load, where only
 * syncCurrentTabFromRoute runs (the visibleTabs watcher does not fire then).
 * CourseDetail, CourseTabTeilnehmer, StudentDetail and CourseSummary render for real.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, reactive, ref } from 'vue'

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
// Renders the gamification switch it receives, so the CourseDetail -> Verwaltung binding is checked.
vi.mock('../../src/components/CourseTabVerwaltung.vue', async () => {
	const { h: render } = await import('vue')
	return {
		default: {
			name: 'CourseTabVerwaltung',
			props: { gamificationEnabled: { type: Boolean, default: true } },
			render() {
				return render('div', { class: 'stub-verwaltung', 'data-gamification': String(this.gamificationEnabled) })
			},
		},
	}
})
vi.mock('../../src/components/ExamReadiness.vue', () => stub('ExamReadiness'))

import CourseDetail from '../../src/components/CourseDetail.vue'
import { abschlussTabIds, learnerHasProgressTab, teilnehmerTabIds } from '../../src/utils/courseView.js'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app, liveProps

async function renderCourse({ props = {}, course = {}, routeTab = '', tools = { enabled_tools: null }, studentPools = [] } = {}) {
	api.routes['/apps/learning/api/courses/{courseId}'] = {
		id: 5, title: 'Pflichtschulung', status: 'active', is_instructor: false, pools: [], members: [], ...course,
	}
	api.routes['/apps/learning/api/settings/tools'] = tools
	api.routes['/apps/learning/api/courses/{courseId}/students/{studentId}'] = {
		user_id: 'learner',
		display_name: 'Lena',
		xp: { level: 4, total_xp: 1234, level_progress_pct: 40, xp_to_next_level: 300, xp_in_level: 100 },
		streak: { current_streak: 0, last_activity_date: null },
		badges: [],
		badge_progress: [],
		pools: studentPools,
		recent_sessions: [{ completed_at: 1700000000, pool_name: 'Brandschutz', mode: 'exam', score: '9/10' }],
		leitner_enabled: course.mode_config?.leitner !== false,
		stats: { total_sessions: 7, total_mastered: 2, enrolled_at: 1, last_activity_date: '2026-03-04' },
	}
	host = document.createElement('div')
	document.body.appendChild(host)
	const instanceRef = ref(null)
	const live = reactive({ courseId: 5, userRole: 'student', ...props })
	liveProps = live
	app = createApp({ render: () => h(CourseDetail, { ref: instanceRef, ...live }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.globalProperties.$route = { params: { id: '5', tab: routeTab } }
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return instanceRef.value
}

function megaIds(detail) {
	return detail.visibleMegaTabs.map((tab) => tab.id)
}

function megaLabels() {
	return Array.from(host.querySelectorAll('.mega-tab-button')).map((b) => b.textContent.trim())
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

const certifying = (mode = {}) => ({ cert_enabled: true, mode_config: { course_summary: false, ...mode } })
// The reporter's course: certificate, gamification, Leitner and the class register off.
const bare = () => certifying({ leitner: false, classbook: false, league: false, duel: false, gameshow: false })

describe('learner tab helpers (GitHub #36)', () => {
	it('keeps "Mein Fortschritt" unless gamification and Leitner are off and a completion area exists', () => {
		expect(learnerHasProgressTab(certifying(), true)).toBe(true)
		expect(learnerHasProgressTab(certifying(), false)).toBe(true)
		expect(learnerHasProgressTab(certifying({ leitner: false }), true)).toBe(true)
		expect(learnerHasProgressTab(certifying({ leitner: false }), false)).toBe(false)
		// without a completion area the progress tab is the only home of the recent sessions
		expect(learnerHasProgressTab({ mode_config: { leitner: false } }, false)).toBe(true)
	})

	it('empties the participants leaves of the reporter\'s course and gives it a completion area', () => {
		expect(teilnehmerTabIds(bare(), { gamificationEnabled: false })).toEqual([])
		expect(abschlussTabIds(bare())).toEqual(['summary'])
		expect(abschlussTabIds(bare(), { actsAsInstructor: true })).toEqual([])
		expect(abschlussTabIds({ mode_config: {} })).toEqual([])
	})
})

describe('CourseDetail — the learners\' Abschluss area (GitHub #36)', () => {
	it('shows Abschluss & Zertifikat as an area with the summary and the recent sessions', async () => {
		const detail = await renderCourse({ course: certifying() })
		expect(megaLabels()).toEqual(['Lernraum', 'Teilnehmer', 'Abschluss & Zertifikat', 'Wettbewerb', 'Kommunikation'])

		detail.selectMegaTab('abschluss')
		await flush()
		expect(detail.currentTab).toBe('summary')
		expect(host.querySelector('.abschluss-mega-tab .course-summary'), 'summary rendered').not.toBeNull()
		expect(host.querySelector('.abschluss-mega-tab .session-item')?.textContent).toContain('Brandschutz')
	})

	// Review of #36: with gamification and Leitner off "Mein Fortschritt" is gone, and with it the
	// accuracy per pool; nobody but the instructor drill-down showed it any more.
	it('shows the accuracy per pool in Abschluss when "Mein Fortschritt" is gone', async () => {
		const studentPools = [{ pool_id: 3, pool_name: 'Datenschutz', accuracy: 85, total: 0, boxes: {} }]
		const detail = await renderCourse({ props: { gamificationEnabled: false }, course: bare(), studentPools })
		detail.selectMegaTab('abschluss')
		await flush()
		expect(host.querySelector('.abschluss-mega-tab .pool-accuracy-item')?.textContent).toContain('Datenschutz')
		expect(host.querySelector('.abschluss-mega-tab .pool-accuracy-item')?.textContent).toContain('85%')
	})

	it('shows no second accuracy list in Abschluss while "Mein Fortschritt" stays', async () => {
		const studentPools = [{ pool_id: 3, pool_name: 'Datenschutz', accuracy: 85, total: 0, boxes: {} }]
		const detail = await renderCourse({ props: { gamificationEnabled: true }, course: certifying({ leitner: false }), studentPools })
		detail.selectMegaTab('abschluss')
		await flush()
		expect(host.querySelector('.abschluss-mega-tab .session-item'), 'sessions rendered').not.toBeNull()
		expect(host.querySelector('.abschluss-mega-tab .pool-accuracy-item')).toBeNull()
	})

	it('drops the recent sessions from "Mein Fortschritt" when the Abschluss area lists them', async () => {
		const detail = await renderCourse({ course: certifying() })
		detail.selectMegaTab('teilnehmer')
		await flush()
		expect(detail.currentTab).toBe('my-progress')
		expect(host.querySelector('.student-header-card'), 'My progress rendered').not.toBeNull()
		expect(host.querySelector('.session-item')).toBeNull()
	})

	it('keeps an IT course without a completion tab as before: no Abschluss, sessions in "Mein Fortschritt"', async () => {
		const detail = await renderCourse({ course: { mode_config: { course_summary: false } } })
		expect(megaIds(detail)).toEqual(['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation'])
		detail.selectMegaTab('teilnehmer')
		await flush()
		expect(host.querySelector('.session-item')?.textContent).toContain('Brandschutz')
	})

	it('hides the participants area for learners when no leaf is left', async () => {
		const detail = await renderCourse({ props: { gamificationEnabled: false }, course: bare() })
		expect(megaLabels()).toEqual(['Lernraum', 'Abschluss & Zertifikat', 'Kommunikation'])
		expect(megaIds(detail)).not.toContain('teilnehmer')
	})

	it('keeps the instructor areas unchanged (summary stays under Teilnehmer, no Abschluss)', async () => {
		const detail = await renderCourse({ props: { userRole: 'instructor' }, course: { ...certifying(), is_instructor: true } })
		expect(megaIds(detail)).toEqual(['lernraum', 'teilnehmer', 'wettbewerb', 'kommunikation', 'verwaltung'])
		expect(detail.teilnehmerLeafTabs).toContain('summary')
		expect(detail.megaTabForLeaf('summary')).toBe('teilnehmer')
	})

	it('moves the area along when the instructor switches the student view on while on the summary', async () => {
		const detail = await renderCourse({ props: { userRole: 'instructor' }, course: { ...certifying(), is_instructor: true } })
		detail.syncCurrentTabFromRoute('summary')
		await flush()
		expect(detail.activeMegaTab).toBe('teilnehmer')
		liveProps.studentViewActive = true
		await flush()
		expect(detail.currentTab).toBe('summary')
		expect(detail.activeMegaTab).toBe('abschluss')
	})
})

describe('CourseDetail — old bookmarks on moved leaves, first load (GitHub #36)', () => {
	const cases = [
		// [route tab, course, gamification, expected area, expected leaf]
		['summary', certifying(), true, 'abschluss', 'summary'],
		['teilnehmer', bare(), false, 'abschluss', 'summary'],
		['my-progress', bare(), false, 'abschluss', 'summary'],
		// switched off, not moved: nothing of it lives elsewhere, so the Lernraum
		['classbook', bare(), false, 'lernraum', 'training'],
		['classbook', certifying({ classbook: false }), true, 'teilnehmer', 'my-progress'],
		['classbook', { mode_config: { classbook: false } }, true, 'teilnehmer', 'my-progress'],
		['summary', { mode_config: { course_summary: false } }, true, 'teilnehmer', 'my-progress'],
		['my-progress', certifying(), true, 'teilnehmer', 'my-progress'],
	]

	for (const [tab, course, gamification, area, leaf] of cases) {
		it(`/courses/5/${tab} (${JSON.stringify(course.mode_config)}, gamification ${gamification ? 'on' : 'off'}) opens ${area}/${leaf}`, async () => {
			const detail = await renderCourse({ props: { gamificationEnabled: gamification }, course, routeTab: tab })
			expect(detail.activeMegaTab).toBe(area)
			expect(detail.currentTab).toBe(leaf)
			expect(megaIds(detail)).toContain(area)
		})
	}
})

describe('CourseDetail hands the gamification switch to the course rules (GitHub #36)', () => {
	for (const enabled of [false, true]) {
		it(`Verwaltung receives gamificationEnabled=${enabled}`, async () => {
			const detail = await renderCourse({ props: { userRole: 'instructor', gamificationEnabled: enabled }, course: { is_instructor: true, mode_config: {} } })
			detail.selectMegaTab('verwaltung')
			await flush()
			expect(host.querySelector('.stub-verwaltung')?.dataset.gamification).toBe(String(enabled))
		})
	}
})

describe('texts without XP, streaks and badges with gamification off (GitHub #36)', () => {
	it('the summary guide and the course_summary description name no streaks, XP or badges', async () => {
		const detail = await renderCourse({ props: { gamificationEnabled: false }, course: certifying() })
		expect(detail.learningGuidePayload('summary').text).not.toMatch(/Streak|Badge|XP/)
		app.unmount()
		host.remove()
		const on = await renderCourse({ course: certifying() })
		expect(on.learningGuidePayload('summary').text).toMatch(/Streaks, Badges/)
	})

	it('the learner Abschluss area hands the AI switch to the summary (swarm counters)', async () => {
		for (const ai of [false, true]) {
			api.routes['/apps/learning/api/courses/{courseId}/summary'] = {
				mastery: {}, sessions: {}, xp: {}, streak: {}, duels: {}, badges: [], swarm: { total_contributions: 2 }, trouble_spots: [],
			}
			api.routes['/apps/learning/api/courses/5/pass-status'] = { applicable: false }
			const detail = await renderCourse({ course: certifying({ course_summary: true }), tools: { enabled_tools: null, ai_enabled: ai } })
			detail.selectMegaTab('abschluss')
			await flush()
			expect(host.querySelector('.abschluss-mega-tab .summary-grid'), 'summary statistics rendered').not.toBeNull()
			expect(host.querySelector('.abschluss-mega-tab .snapshot-inline-stats') !== null).toBe(ai)
			app.unmount()
			host.remove()
			app = null
		}
	})
})

describe('CourseRecentSessions in the student view (GitHub #36)', () => {
	it('shows the empty hint, not an error, when the viewer is no student of the course (404)', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockImplementationOnce(() => Promise.reject(Object.assign(new Error('404'), { response: { status: 404 } })))
		const { default: CourseRecentSessions } = await import('../../src/components/CourseRecentSessions.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseRecentSessions, { courseId: 5, studentId: 'teacher' }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		expect(host.querySelector('.empty-hint')).not.toBeNull()
		expect(host.textContent).not.toContain('Failed to load')
	})
})


// GitHub #63: an instructor previewing the course in the student view is no learner of it. The
// server answers his own learner record with 404 (CourseService::getStudentDetail checks the
// member role), which "Mein Fortschritt" showed as "Student not found in this course." plus a
// console error, and the completion area asked for in vain on every visit.
describe('CourseDetail — an instructor in the student view (GitHub #63)', () => {
	const PREVIEW_NOTE = 'Schüler-Ansicht: Lernende sehen hier ihren eigenen Lernfortschritt. Du bist in diesem Kurs Lehrkraft und hast deshalb keinen eigenen.'

	async function studentDetailRequests() {
		const axios = (await import('@nextcloud/axios')).default
		return axios.get.mock.calls.filter(([url]) => url === '/apps/learning/api/courses/{courseId}/students/{studentId}')
	}

	beforeEach(async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockClear()
	})

	it('shows a note in "Mein Fortschritt" instead of asking for a learner record that cannot exist', async () => {
		const detail = await renderCourse({ props: { studentViewActive: true }, course: { is_instructor: true, mode_config: { course_summary: false } } })
		detail.selectMegaTab('teilnehmer')
		await flush()
		expect(detail.currentTab).toBe('my-progress')
		expect(host.querySelector('.my-progress-section .student-preview-note')?.textContent).toContain(PREVIEW_NOTE)
		expect(host.querySelector('.student-header-card')).toBeNull()
		expect(await studentDetailRequests()).toEqual([])
	})

	it('shows the note in the completion area instead of the recent sessions', async () => {
		const detail = await renderCourse({ props: { studentViewActive: true }, course: { is_instructor: true, ...certifying() } })
		detail.selectMegaTab('abschluss')
		await flush()
		expect(host.querySelector('.abschluss-mega-tab .course-summary'), 'summary still rendered').not.toBeNull()
		expect(host.querySelector('.abschluss-mega-tab .student-preview-note')?.textContent).toContain(PREVIEW_NOTE)
		expect(host.querySelector('.abschluss-mega-tab .session-item')).toBeNull()
		expect(await studentDetailRequests()).toEqual([])
	})

	it('leaves a real learner as before: his own record is loaded and no note is shown', async () => {
		const detail = await renderCourse({ course: { mode_config: { course_summary: false } } })
		detail.selectMegaTab('teilnehmer')
		await flush()
		expect(host.querySelector('.student-header-card'), 'My progress rendered').not.toBeNull()
		expect(host.querySelector('.student-preview-note')).toBeNull()
		expect((await studentDetailRequests()).length).toBeGreaterThan(0)
	})
})
