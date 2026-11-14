import { beforeEach, describe, expect, it, vi } from 'vitest'
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
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['classbook', 'my-progress'])
	})

	it('builds student sub-tabs with classbook + my-progress (no summary when disabled)', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: false },
			},
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['classbook', 'my-progress'])
	})

	it('shows summary pill for students when course_summary is enabled', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				mode_config: { course_summary: true },
			},
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['classbook', 'my-progress', 'summary'])
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
	it('shows "Abschluss & Zertifikat" to students when cert_enabled and course_summary is off', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: true, mode_config: { course_summary: false } },
			userRole: 'student',
			actsAsInstructor: false,
		})

		expect(instance.visibleSubTabs).toEqual([
			{ id: 'classbook', label: 'Klassenbuch' },
			{ id: 'my-progress', label: 'Mein Fortschritt' },
			{ id: 'summary', label: 'Abschluss & Zertifikat' },
		])
	})

	it('a deep link to the summary tab (certificate notification) selects it for students', () => {
		const instance = createInstance({
			course: { is_instructor: false, cert_enabled: true, mode_config: { course_summary: false } },
			userRole: 'student',
			actsAsInstructor: false,
			activeTab: 'summary',
		})

		instance.syncFromActiveTab('summary')

		expect(instance.currentSubTab).toBe('summary')
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
