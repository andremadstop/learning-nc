import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => ({ uid: 'alice' })),
}))

// Mock @nextcloud/dialogs (avoids CSS import from NcDialog — Node 22's native ESM
// loader rejects the dist .css asset; same pattern as StudentDashboard.test.js)
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/StudentDetail.vue', () => stub('StudentDetail'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/Klassenbuch.vue', () => ({
	default: { name: 'Klassenbuch', props: ['courseId', 'userRole'], template: '<div class="klassenbuch-stub" :data-role="userRole" />' },
}))
// GitHub #35: the picker stub declares the real component's interface, so a renamed prop or a
// dropped members-changed relay fails the wiring test below.
const { pickerStub } = vi.hoisted(() => ({ pickerStub: { instance: null } }))
vi.mock('../../src/components/CourseMemberPicker.vue', () => ({
	default: {
		name: 'CourseMemberPicker',
		props: { courseId: { type: [Number, String], required: true } },
		emits: ['members-changed'],
		created() {
			pickerStub.instance = this
		},
		template: '<div class="picker-stub" />',
	},
}))

import CourseTabTeilnehmer from '../../src/components/CourseTabTeilnehmer.vue'
import { setTestLanguage, translate as catalogueTranslate } from './support/l10nCatalog.js'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabTeilnehmer.data === 'function' ? CourseTabTeilnehmer.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: true,
			mode_config: {
				course_summary: false,
			},
		},
		userRole: 'instructor',
		courseMembers: [],
		coursePools: [],
		activeTab: 'members',
		$emit: vi.fn(),
		// The prop CourseDetail hands down. Set per test; the harness does not re-derive it from
		// the course (that copy of CourseDetail's rule would test itself, not the component).
		actsAsInstructor: true,
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabTeilnehmer.computed.isInstructor.call(instance) },
		isCourseSummaryReleased: { get: () => CourseTabTeilnehmer.computed.isCourseSummaryReleased.call(instance) },
		visibleSubTabs: { get: () => CourseTabTeilnehmer.computed.visibleSubTabs.call(instance) },
		hasCompletionArea: { get: () => CourseTabTeilnehmer.computed.hasCompletionArea.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabTeilnehmer.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabTeilnehmer', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds instructor sub-tabs: members, classbook, progress, heatmap, weak-questions, class-profile, summary', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual([
			'members', 'classbook', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'summary',
		])
	})

	// GitHub #35: the student view must win over the course membership in every mega-tab.
	it('the student view (actsAsInstructor false) shows an own course with the learner sub-tabs', () => {
		const instance = createInstance({ userRole: 'student', actsAsInstructor: false })

		expect(instance.isInstructor).toBe(false)
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['my-progress', 'classbook'])
	})

	it('builds student sub-tabs with my-progress + classbook (no summary when disabled)', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: false },
			},
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['my-progress', 'classbook'])
	})

	// GitHub #36: learners have the completion tab as an area of its own (CourseDetail)
	it('shows no summary pill for students even when course_summary is enabled', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: true },
			},
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['my-progress', 'classbook'])
	})

	it('emits tab-change with correct leaf ID when selecting a sub-tab', () => {
		const instance = createInstance()

		instance.selectSubTab('progress')

		expect(instance.currentSubTab).toBe('progress')
		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'progress')
	})

	it('computes pool mastery correctly from row data', () => {
		const instance = createInstance()

		const row = {
			pools: [
				{ pool_id: 10, mastered: 8, total_questions: 10 },
				{ pool_id: 20, mastered: 0, total_questions: 5 },
			],
		}

		expect(instance.getPoolMastery(row, 10)).toBe(80)
		expect(instance.getPoolMastery(row, 20)).toBe(0)
		expect(instance.getPoolMastery(row, 99)).toBeNull()
	})

	it('maps critical-card counts to dashboard severity classes', () => {
		const instance = createInstance()

		expect(instance.criticalCardsClass(0)).toBe('critical-cards-low')
		expect(instance.criticalCardsClass(3)).toBe('critical-cards-medium')
		expect(instance.criticalCardsClass(10)).toBe('critical-cards-high')
	})

	it('loads at-risk students including FSRS critical-card counts', async () => {
		axios.get.mockResolvedValue({
			data: {
				at_risk: [
					{ user_id: 'bob', display_name: 'Bob', critical_cards_count: 4 },
				],
			},
		})
		const instance = createInstance()

		await instance.fetchAtRisk()

		expect(instance.atRiskStudents).toEqual([
			{ user_id: 'bob', display_name: 'Bob', critical_cards_count: 4 },
		])
	})
})

// GitHub #35: the completion tab is where the certificate lives. A certifying course shows it to
// learners even when the summary statistics (mode_config.course_summary) are not released.
describe('CourseTabTeilnehmer — completion tab in certifying courses (GitHub #35)', () => {
	// GitHub #36: the learners' completion tab moved to an area of its own (CourseDetail).
	it('leaves the completion tab out of the learner sub-tabs of a certifying course', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: true, mode_config: { course_summary: false } },
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs).toEqual([
			{ id: 'my-progress', label: 'Mein Fortschritt' },
			{ id: 'classbook', label: 'Klassenbuch' },
		])
		expect(instance.hasCompletionArea).toBe(true)
	})

	it('keeps the student completion tab hidden for a non-certifying course without released summary', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: false, mode_config: { course_summary: false } },
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).not.toContain('summary')
	})

	it('stores the signing availability the cert report returns for the instructor warning', async () => {
		const instance = createInstance({
			course: { is_instructor: true, cert_enabled: true, mode_config: { course_summary: false } },
			actsAsInstructor: true,
		})
		instance.certFilters = () => ({})
		axios.get.mockResolvedValueOnce({ data: { rows: [], signing_available: false } })

		await instance.fetchCertReport()

		expect(instance.certSigningAvailable).toBe(false)
	})

	// Review: per learner, not only the instance-wide key state.
	it('stores the members who passed without a certificate', async () => {
		const instance = createInstance({
			course: { is_instructor: true, cert_enabled: true, mode_config: { course_summary: false } },
			actsAsInstructor: true,
		})
		instance.certFilters = () => ({})
		axios.get.mockResolvedValueOnce({ data: { rows: [], signing_available: true, passed_without_certificate: [{ display_name: 'Jane Doe' }] } })

		await instance.fetchCertReport()

		expect(instance.certPassedWithoutCertificate).toEqual([{ display_name: 'Jane Doe' }])
	})

	it('treats a missing or failed passed-without-certificate check as an empty list', async () => {
		const instance = createInstance({
			course: { is_instructor: true, cert_enabled: true, mode_config: { course_summary: false } },
			actsAsInstructor: true,
		})
		instance.certFilters = () => ({})
		axios.get.mockResolvedValueOnce({ data: { rows: [], signing_available: true, passed_without_certificate: null } })

		await instance.fetchCertReport()

		expect(instance.certPassedWithoutCertificate).toEqual([])
	})
})

describe('CourseTabTeilnehmer member picker wiring (GitHub #35)', () => {
	it('passes the course id to the picker and relays members-changed so the list refreshes', async () => {
		axios.get.mockResolvedValue({ data: [] })
		pickerStub.instance = null
		const membersChanged = vi.fn()
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(CourseTabTeilnehmer, {
				courseId: 5,
				course: { is_instructor: true, mode_config: { course_summary: false } },
				userRole: 'instructor',
				actsAsInstructor: true,
				activeTab: 'members',
				onMembersChanged: membersChanged,
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = (app, s, p, c) => (c === 1 ? s : p)
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()

		try {
			expect(pickerStub.instance).not.toBeNull()
			expect(pickerStub.instance.courseId).toBe(5)

			pickerStub.instance.$emit('members-changed')
			await nextTick()

			expect(membersChanged).toHaveBeenCalledTimes(1)
		} finally {
			app.unmount()
			host.remove()
		}
	})
})

// GitHub #35: Klassenbuch got the global role, so a global instructor who is a student of this course
// (or an instructor in the student view) was not treated as a learner there (no kudos).
describe('CourseTabTeilnehmer classbook role (GitHub #35)', () => {
	async function classbookRole(props) {
		axios.get.mockResolvedValue({ data: [] })
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(CourseTabTeilnehmer, { courseId: 5, activeTab: 'classbook', ...props }),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = (app, s, p, c) => (c === 1 ? s : p)
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		try {
			return host.querySelector('.klassenbuch-stub')?.dataset.role
		} finally {
			app.unmount()
			host.remove()
		}
	}

	it('passes the role inside the course, not the global one', async () => {
		expect(await classbookRole({
			course: { is_instructor: false, mode_config: { course_summary: false } }, userRole: 'instructor', actsAsInstructor: false,
		})).toBe('student')
		expect(await classbookRole({
			course: { is_instructor: true, mode_config: { course_summary: false } }, userRole: 'student', actsAsInstructor: true,
		})).toBe('instructor')
	})
})

// Audit #154: the at-risk reasons were German sentences, rendered as they came for every reader.
describe('CourseTabTeilnehmer at-risk reasons', () => {
	const passthrough = globalThis.t
	afterEach(() => {
		globalThis.t = passthrough
		setTestLanguage('de')
	})

	it('translates the reason codes into the reader\'s language', () => {
		globalThis.t = catalogueTranslate
		setTestLanguage('en')
		const instance = createInstance()
		const labels = instance.riskReasonLabels({
			risk_reasons: ['Inaktiv seit 12 Tagen', 'Nur 0 Sessions in 14 Tagen'],
			risk_reason_codes: [
				{ code: 'inactive_days', n: 12 },
				{ code: 'never_active' },
				{ code: 'low_accuracy', n: 40 },
				{ code: 'critical_cards', n: 11 },
				{ code: 'box_one_share', n: 70 },
				{ code: 'streak_lost', n: 9 },
				{ code: 'few_sessions', n: 0 },
			],
		})
		expect(labels).toEqual([
			'Inactive for 12 days',
			'Never active',
			'Accuracy 40%',
			'Critical cards: 11',
			'70% of cards in box 1',
			'Streak lost (was 9)',
			'Sessions in 14 days: 0',
		])
	})

	it('falls back to the German sentences of a server without codes', () => {
		const instance = createInstance()
		expect(instance.riskReasonLabels({ risk_reasons: ['Inaktiv seit 9 Tagen'] })).toEqual(['Inaktiv seit 9 Tagen'])
	})
})

// GitHub #44: "Show worst questions" toggled an empty list. It now shows only with questions, and
// a row without any says why (the threshold the server applies).
describe('CourseTabTeilnehmer heatmap (GitHub #44)', () => {
	async function renderHeatmap(rows) {
		axios.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/chapter-heatmap') ? rows : [] }))
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({
			render: () => h(CourseTabTeilnehmer, {
				courseId: 5,
				course: { is_instructor: true, mode_config: { course_summary: false } },
				userRole: 'instructor',
				actsAsInstructor: true,
				activeTab: 'heatmap',
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = (app, s, p, c) => (c === 1 ? s : p)
		app.config.warnHandler = () => {}
		app.mount(host)
		for (let i = 0; i < 6; i++) {
			await Promise.resolve()
			await nextTick()
		}
		return { host, app }
	}

	const row = (key, top) => ({ chapter_key: key, chapter_title: key, success_rate: 50, unique_learners: 1, severity: 'yellow', top_wrong_questions: top })

	it('offers no button on a row without worst questions, and names the threshold', async () => {
		const { host, app } = await renderHeatmap([row('pool:3', [])])
		try {
			expect(host.textContent).not.toContain('Show worst questions')
			expect(host.querySelector('.heatmap-no-worst')?.textContent).toContain('2')
		} finally {
			app.unmount()
			host.remove()
		}
	})

	it('lists the worst questions with wrong and total answers', async () => {
		const { host, app } = await renderHeatmap([row('ch1', [{ id: 7, text: 'Was ist ein Fluchtweg?', wrong_rate: 66.7, wrong_answers: 2, total_answers: 3 }])])
		try {
			const button = [...host.querySelectorAll('button')].find((b) => b.textContent.includes('Show worst questions'))
			expect(button).toBeDefined()
			button.click()
			await nextTick()
			expect(host.querySelector('.wrong-q-rate')?.textContent).toContain('2 von 3 Antworten falsch (66.7 %)')
		} finally {
			app.unmount()
			host.remove()
		}
	})
})

describe('MIN_ANSWERS_PER_QUESTION (GitHub #44)', () => {
	it('is the number the server applies', async () => {
		const { readFileSync } = await import('node:fs')
		const { resolve } = await import('node:path')
		const { MIN_ANSWERS_PER_QUESTION } = await import('../../src/utils/courseFigures.js')
		const php = readFileSync(resolve(__dirname, '../../lib/Service/CourseService.php'), 'utf8')
		expect(php).toContain(`public const MIN_ANSWERS_PER_QUESTION = ${MIN_ANSWERS_PER_QUESTION};`)
	})
})

// Lückencheck B2 review: a slower answer to an earlier status choice must not overwrite the table
// under the select's newer status.
describe('CourseTabTeilnehmer coverage request order (#42)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockReset()
	})

	it('keeps the rows of the newest filter choice when an older answer arrives last', async () => {
		const instance = createInstance()
		let resolveOverdue
		let resolveNever
		axios.get
			.mockImplementationOnce(() => new Promise((resolve) => { resolveOverdue = resolve }))
			.mockImplementationOnce(() => new Promise((resolve) => { resolveNever = resolve }))

		instance.onCoverageStatusChange('overdue')
		instance.onCoverageStatusChange('never_passed')
		expect(axios.get).toHaveBeenCalledTimes(2)

		resolveNever({ data: { rows: [{ member_ref: 'n', status: 'never_passed' }] } })
		await Promise.resolve()
		await Promise.resolve()
		resolveOverdue({ data: { rows: [{ member_ref: 'o', status: 'overdue' }] } })
		await Promise.resolve()
		await Promise.resolve()

		expect(instance.coverageStatusFilter).toBe('never_passed')
		expect(instance.coverageAppliedStatus).toBe('never_passed')
		expect(instance.coverageRows.map((row) => row.member_ref)).toEqual(['n'])
	})

	it('a failed older request stays silent once a newer one was sent', async () => {
		const instance = createInstance()
		let rejectOld
		axios.get
			.mockImplementationOnce(() => new Promise((resolve, reject) => { rejectOld = reject }))
			.mockResolvedValueOnce({ data: { rows: [] } })
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

		instance.onCoverageStatusChange('overdue')
		instance.coverageStatusFilter = 'expired'
		await instance.fetchCoverage()
		rejectOld(new Error('slow and failed'))
		await Promise.resolve()
		await Promise.resolve()

		expect(instance.$emit).not.toHaveBeenCalledWith('error', expect.anything())
		expect(instance.coverageAppliedStatus).toBe('expired')
		errSpy.mockRestore()
	})
})
