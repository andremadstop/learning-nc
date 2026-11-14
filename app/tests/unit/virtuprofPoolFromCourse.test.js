/**
 * GitHub #40 (g), review follow-up: a pool opened from a course lost the course as soon as a
 * question was on screen.
 *
 * App.vue renders TrainingMode/LeitnerMode/ExamMode without a courseId, so their question
 * watchers sent courseId: null. VirtuProf merges each context into the previous one, so that
 * null overwrote the course id App.vue had set for the pool view while the title stayed: the
 * panel promised "sent to the course instructor" and the ticket went to the admin inbox.
 *
 * Runs the real App.vue payload, the modes' real question watchers and VirtuProf's merge.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
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
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showInfo: vi.fn(), showWarning: vi.fn() }))
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


const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
// Every other component App.vue and the three modes render is a plain stub; only the modes'
// own definitions and VirtuProf's merge are real.
for (const name of ['AiConsentHint', 'BadgeUnlock', 'Certificate', 'LevelUpOverlay', 'PbqRenderer',
	'QuestionLanguageSwitcher', 'QuestionScenario',
	'PersonalSettings', 'PoolList', 'QuestionList',
	'AnalyticsDashboard', 'CourseList', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'StudentDashboard', 'TeamLeadDashboard', 'LeagueTab',
	'CourseTabLernraum', 'CourseTabTeilnehmer', 'CourseTabWettbewerb', 'CourseTabKommunikation', 'CourseTabVerwaltung',
	'CourseSummary', 'StudentKnowledgeContribute', 'CourseFeed', 'BuddyMatching',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => stub(name))
}

async function load() {
	const { default: App } = await import('../../src/App.vue')
	const { default: VirtuProf } = await import('../../src/components/VirtuProf.vue')
	const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
	const { default: LeitnerMode } = await import('../../src/components/LeitnerMode.vue')
	const { default: ExamMode } = await import('../../src/components/ExamMode.vue')
	const { useVirtuProfStore } = await import('../../src/stores/virtuProfStore.js')
	return { App, VirtuProf, modes: { TrainingMode, LeitnerMode, ExamMode }, store: useVirtuProfStore() }
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

// Runs the mode's real question watcher with the props App.vue passes (no courseId) and returns
// the context it hands the VirtuProf store.
function modeContext(name, Mode, store, courseId) {
	const watcherKey = name === 'LeitnerMode' ? 'currentItem' : 'currentQuestion'
	const self = { poolId: 5, courseId, getCorrectAnswerIndex: () => 0 }
	for (const [key, fn] of Object.entries(Mode.methods || {})) {
		if (!(key in self)) self[key] = fn.bind(self)
	}
	Mode.watch[watcherKey].handler.call(self, { id: 99, text: 'Q?', answers: [{ text: 'A' }] })
	return store.context
}

const pool = { mainView: 'pools', currentView: 'questions', mode: 'training', selectedPool: { name: 'P' } }

describe('VirtuProf: a pool opened from a course, with a question on screen (GitHub #40)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	for (const name of ['TrainingMode', 'LeitnerMode', 'ExamMode']) {
		it(`${name} keeps the course id App.vue set`, async () => {
			const { App, VirtuProf, modes, store } = await load()
			const vp = virtuProf(VirtuProf)

			vp.updateContext(appPayload(App, { ...pool, poolFromCourse: true, poolFromCourseObj: { id: 42, title: 'Netzwerk' } }))
			vp.updateContext(modeContext(name, modes[name], store, null))

			expect(vp.currentContext.questionContext).toMatchObject({ questionId: 99 })
			expect(vp.currentContext).toMatchObject({ courseId: 42, courseTitle: 'Netzwerk' })
			expect(vp.hasCourseContext).toBe(true)
		})

		it(`${name} inside a course still sends its own course id`, async () => {
			const { App, VirtuProf, modes, store } = await load()
			const vp = virtuProf(VirtuProf)

			vp.updateContext(appPayload(App, { mainView: 'courses', selectedCourse: { id: 42, title: 'Netzwerk' } }))
			vp.updateContext(modeContext(name, modes[name], store, 42))

			expect(vp.currentContext.courseId).toBe(42)
		})

		it(`${name} in a pool opened on its own adds no course`, async () => {
			const { App, VirtuProf, modes, store } = await load()
			const vp = virtuProf(VirtuProf)

			vp.updateContext(appPayload(App, pool))
			vp.updateContext(modeContext(name, modes[name], store, null))

			expect(vp.currentContext.courseId).toBe(null)
			expect(vp.hasCourseContext).toBe(false)
		})
	}
})
