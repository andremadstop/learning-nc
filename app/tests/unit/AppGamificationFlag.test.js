/**
 * GitHub #36: the admin's gamification switch reaches the frontend once, through
 * GET /api/settings/personal, and App.vue keeps the only copy. Missing or unreadable means on,
 * so instances without the new key (and a failed read) look exactly as before.
 *
 * Same harness as AppA11yHydration.test.js: the real App.vue, children stubbed.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { personal: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/role')) {
				return Promise.resolve({ data: { role: 'student', group: 'learning-instructors' } })
			}
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: api.personal })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const { plain } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => plain('NcAppContent'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => plain('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcButton', () => plain('NcButton'))
for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
	'AnalyticsDashboard', 'CourseList', 'CourseDetail', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'StudentDashboard', 'TeamLeadDashboard',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}
vi.doMock('../../src/components/VirtuProf.vue', () => ({ __esModule: true, ...plain('VirtuProf') }))

const Shell = { render: () => h('div') }
let host, app

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountApp(path) {
	const { default: App } = await import('../../src/App.vue')
	const router = createRouter({
		history: createMemoryHistory(),
		routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
			.map((name) => ({ path: '/' + name, name, component: Shell }))
			.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
	})
	router.push(path)
	await router.isReady()
	const pinia = createPinia()
	setActivePinia(pinia)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(App)
	app.use(pinia)
	app.use(router)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	const vm = app.mount(host)
	await settle()
	return vm
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('App.vue: the global gamification switch (GitHub #36)', () => {
	it('reads gamification_enabled = false from the personal settings', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: false, onboarding_acknowledged: 'yes' }
		const vm = await mountApp('/dashboard')
		expect(vm.gamificationEnabled).toBe(false)
	})

	it('counts a missing key as on', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { onboarding_acknowledged: 'yes' }
		const vm = await mountApp('/dashboard')
		expect(vm.gamificationEnabled).toBe(true)
	})

	// GitHub #36: the VirtuProf view guides must not promise what the views no longer show
	it('the view guides of Heute, My progress and the course summary name no streak, XP or badges with gamification off', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: false, onboarding_acknowledged: 'yes' }
		const off = await mountApp('/dashboard')
		for (const key of ['view:dashboard', 'view:course-my-progress', 'view:course-summary']) {
			const guide = off.viewGuidePayload(key)
			expect(`${guide.text} ${guide.shortText}`, key).not.toMatch(/Streak|XP|Level|Badge|Challenge/)
		}
		app.unmount()
		host.remove()

		api.personal = { onboarding_acknowledged: 'yes' }
		const on = await mountApp('/dashboard')
		expect(on.viewGuidePayload('view:dashboard').text).toMatch(/Daily Challenge, Streak/)
		expect(on.viewGuidePayload('view:course-summary').text).toMatch(/Streaks, Badges/)
	})
})

describe('App.vue: the Skill-Map guide (fresh-eyes C3, GitHub #36)', () => {
	it('the Skill-Map has its own guide instead of keeping the dashboard one', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: false, onboarding_acknowledged: 'yes' }
		const vm = await mountApp('/skill-map')
		expect(vm.mainView).toBe('skillmap')
		expect(vm.currentViewKey()).toBe('view:skillmap')
		const guide = vm.viewGuidePayload('view:skillmap')
		expect(guide.title).toBe('Skill-Map')
		expect(`${guide.text} ${guide.shortText}`).not.toMatch(/Tagesübersicht|Lernübersicht|Leitner|XP/)
	})
})

describe('App.vue: a view-only pool tells an instructor how to get the edit right (fresh-eyes C3, GitHub #69)', () => {
	const HINT = 'Um Fragen zu ändern, bitte die Person, der der Pool gehört, ihn mit dir zum Bearbeiten zu teilen.'

	async function openReadOnlyPool(role, studentView = false) {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { onboarding_acknowledged: 'yes' }
		const vm = await mountApp('/pools')
		vm.userRole = role
		vm.studentViewOverride = studentView
		vm.selectedPool = { id: 3, name: 'Fire safety', is_shared: true, permission: 'read' }
		vm.poolPermission = 'read'
		vm.currentView = 'questions'
		await settle()
		return vm
	}

	it('names the owner share for an instructor', async () => {
		await openReadOnlyPool('instructor')
		expect(host.textContent).toContain('Dieser Pool wurde mit dir geteilt (nur Ansicht)')
		expect(host.textContent).toContain(HINT)
	})

	it('not for a learner or in the student view', async () => {
		await openReadOnlyPool('student')
		expect(host.textContent).toContain('Dieser Pool wurde mit dir geteilt (nur Ansicht)')
		expect(host.textContent).not.toContain(HINT)
		app.unmount()
		host.remove()
		await openReadOnlyPool('instructor', true)
		expect(host.textContent).not.toContain(HINT)
	})
})

// C5 persona sweep: a learner on "Learning materials" was guided with the instructor's
// "Upload reference materials for students." The learner guide names reading and confirming;
// a co-instructor of the course (outside the student view) keeps the instructor's guide.
describe('App.vue: the Learning materials guide matches who is looking (persona sweep C5, GitHub #75)', () => {
	it('a learner gets the learner guide, a co-instructor the instructor guide, the student view the learner one', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: false, onboarding_acknowledged: 'yes' }
		const vm = await mountApp('/courses')
		vm.mainView = 'courses'
		vm.courseTab = 'materials'
		vm.selectedCourse = { id: 1, title: 'Annual safety training', is_instructor: false }
		expect(vm.currentViewKey()).toBe('view:course-materials-learner')
		const learner = vm.viewGuidePayload('view:course-materials-learner')
		expect(`${learner.text} ${learner.shortText}`).not.toMatch(/hochladen|Studierende/)
		expect(learner.text).toMatch(/bestätigst sie als gelesen/)

		vm.selectedCourse = { id: 1, title: 'Annual safety training' }
		expect(vm.currentViewKey()).toBe('view:course-materials-learner')

		vm.selectedCourse = { id: 1, title: 'Annual safety training', is_instructor: true }
		expect(vm.currentViewKey()).toBe('view:course-materials')
		vm.studentViewOverride = true
		expect(vm.currentViewKey()).toBe('view:course-materials-learner')
	})
})
