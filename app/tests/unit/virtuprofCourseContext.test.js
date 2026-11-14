/**
 * GitHub #40 (g): a learner's course question ("Send a question" in the VirtuProf help panel)
 * almost never reached the course instructor.
 *
 * SupportTicketService routes a course_content ticket to the instructor only when the ticket
 * carries a courseId. That id came solely from TrainingMode/LeitnerMode/ExamMode once a question
 * was open; the course page's context (CourseDetail, App.vue) sent only the title, so the panel
 * said "will be sent to the course instructor" while the ticket went to the admin inbox.
 * VirtuProf also merges each context into the previous one, so a course id or title once set
 * outlived the course: from the dashboard a question still went to the last course.
 *
 * Runs the real App.vue payload methods, CourseDetail's emitter and VirtuProf's merge; nothing
 * of the merge rule is copied here.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'alice' })) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

const { plain } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
	'AnalyticsDashboard', 'CourseList', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'StudentDashboard', 'TeamLeadDashboard', 'LeagueTab',
	'CourseTabLernraum', 'CourseTabTeilnehmer', 'CourseTabWettbewerb', 'CourseTabKommunikation', 'CourseTabVerwaltung',
	'CourseSummary', 'StudentKnowledgeContribute', 'CourseFeed', 'BuddyMatching',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}

async function load() {
	const { default: App } = await import('../../src/App.vue')
	const { default: CourseDetail } = await import('../../src/components/CourseDetail.vue')
	const { default: VirtuProf } = await import('../../src/components/VirtuProf.vue')
	return { App, CourseDetail, VirtuProf }
}

function appPayload(App, state) {
	const self = { mainView: 'dashboard', previousMainView: '', selectedCourse: null, selectedStudent: null, poolFromCourse: false, poolFromCourseObj: null, ...state }
	for (const [name, fn] of Object.entries(App.methods)) {
		self[name] = fn.bind(self)
	}
	return self.virtuprofContextPayload()
}

function virtuProf(VirtuProf) {
	const self = { currentContext: null, hintLevel: 0, lastHintQuestionId: null }
	self.updateContext = VirtuProf.methods.updateContext.bind(self)
	Object.defineProperty(self, 'hasCourseContext', { get: () => VirtuProf.computed.hasCourseContext.call(self) })
	return self
}

describe('VirtuProf course context (GitHub #40)', () => {
	// The first dynamic import transforms App.vue with its whole component tree. That alone
	// takes close to the 5 s test timeout and runs over it under the parallel pre-commit run, so
	// the modules are loaded once here with room to spare.
	beforeAll(async () => {
		setActivePinia(createPinia())
		await load()
	}, 60000)

	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('the course page payload carries the course id', async () => {
		const { App } = await load()
		const payload = appPayload(App, { mainView: 'courses', selectedCourse: { id: 42, title: 'Netzwerk' } })

		expect(payload).toMatchObject({ area: 'course-detail', courseId: 42, courseTitle: 'Netzwerk' })
	})

	it('CourseDetail hands VirtuProf the course id', async () => {
		const { CourseDetail } = await load()
		const { useVirtuProfStore } = await import('../../src/stores/virtuProfStore.js')
		const self = {
			isInstructor: false,
			course: { id: 42, title: 'Netzwerk' },
			courseId: 42,
			currentTab: 'feed',
			arenaSubMode: '',
			isStudentLearningTab: false,
			selectedLearningPool: null,
		}

		CourseDetail.methods.emitVirtuProfContext.call(self)

		expect(useVirtuProfStore().context).toMatchObject({ courseId: 42, courseTitle: 'Netzwerk' })
	})

	it('leaving the course clears the course from the merged context', async () => {
		const { App, VirtuProf } = await load()
		const vp = virtuProf(VirtuProf)

		vp.updateContext(appPayload(App, { mainView: 'courses', selectedCourse: { id: 42, title: 'Netzwerk' } }))
		expect(vp.currentContext.courseId).toBe(42)
		expect(vp.hasCourseContext).toBe(true)

		vp.updateContext(appPayload(App, { mainView: 'dashboard' }))

		expect(vp.currentContext.courseId).toBe(null)
		expect(vp.hasCourseContext).toBe(false)
	})

	it('moving to another course replaces the course id', async () => {
		const { App, VirtuProf } = await load()
		const vp = virtuProf(VirtuProf)

		vp.updateContext(appPayload(App, { mainView: 'courses', selectedCourse: { id: 42, title: 'Netzwerk' } }))
		vp.updateContext(appPayload(App, { mainView: 'courses', selectedCourse: { id: 7, title: 'Linux' } }))

		expect(vp.currentContext).toMatchObject({ courseId: 7, courseTitle: 'Linux' })
	})

	it('a pool opened from a course keeps that course, a pool opened on its own has none', async () => {
		const { App } = await load()

		expect(appPayload(App, { mainView: 'pools', currentView: 'questions', mode: 'training', selectedPool: { name: 'P' },
			poolFromCourse: true, poolFromCourseObj: { id: 42, title: 'Netzwerk' } })).toMatchObject({ courseId: 42, courseTitle: 'Netzwerk' })
		expect(appPayload(App, { mainView: 'pools', currentView: 'questions', mode: 'training', selectedPool: { name: 'P' } }))
			.toMatchObject({ courseId: null, courseTitle: '' })
		// the flag outlives the pool view; the dashboard must not inherit the course
		expect(appPayload(App, { mainView: 'dashboard', poolFromCourse: true, poolFromCourseObj: { id: 42, title: 'Netzwerk' } }))
			.toMatchObject({ courseId: null, courseTitle: '' })
	})
})
