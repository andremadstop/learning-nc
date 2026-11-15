/**
 * GitHub #36, fresh-eyes review of the persona run "off": with gamification off a learner still got
 * confetti over the result of a mandatory exam ("Ruhige Darstellung" did not stop it either), and
 * with AI off the top bar still offered "ExplainBot", a page that only said the chat is off.
 *
 * Mounts the real App.vue (children stubbed) with Pinia, as main.js does; harness from
 * AppA11yHydration.test.js.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { personal: {}, ai: false } }))

// canvas-confetti: the instance the app creates fires this spy.
const { fire } = vi.hoisted(() => ({ fire: vi.fn() }))
vi.mock('canvas-confetti', () => {
	const lib = vi.fn()
	lib.create = vi.fn(() => fire)
	return { default: lib }
})
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
// VirtuProf reports the admin's AI availability from GET /api/virtuprof/state.
vi.doMock('../../src/components/VirtuProf.vue', () => ({
	__esModule: true,
	default: { name: 'VirtuProf', template: '<div />', mounted() { this.$emit('ai-enabled-change', api.ai) } },
}))

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
	app.mount(host)
	await settle()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})


function navLabels() {
	return Array.from(host.querySelectorAll('.main-nav-btn')).map((b) => b.textContent.trim())
}

describe('App.vue: celebrations follow gamification and calm display (GitHub #36)', () => {
	it('fires no confetti with gamification off', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: false, onboarding_acknowledged: 'yes' }
		await mountApp('/dashboard')
		const { celebratePerfectSession, celebrateBadge } = await import('../../src/confetti.js')
		fire.mockClear()
		celebrateBadge()
		celebratePerfectSession()
		await new Promise((r) => setTimeout(r, 30))
		expect(fire).not.toHaveBeenCalled()
	})

	it('fires no confetti with calm display on', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: true, animations_enabled: 'no', onboarding_acknowledged: 'yes' }
		await mountApp('/dashboard')
		const { celebrateBadge } = await import('../../src/confetti.js')
		fire.mockClear()
		celebrateBadge()
		expect(fire).not.toHaveBeenCalled()
	})

	it('keeps the confetti with gamification on and animations on', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { gamification_enabled: true, animations_enabled: 'yes', onboarding_acknowledged: 'yes' }
		await mountApp('/dashboard')
		const { celebrateBadge } = await import('../../src/confetti.js')
		fire.mockClear()
		celebrateBadge()
		expect(fire).toHaveBeenCalled()
	})
})

describe('App.vue: the ExplainBot tab needs AI (GitHub #36)', () => {
	it('is not offered when the admin switched AI off', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { onboarding_acknowledged: 'yes' }
		api.ai = false
		await mountApp('/dashboard')
		expect(navLabels()).toContain('Heute')
		expect(navLabels()).not.toContain('Erklärbot')
	})

	it('is offered with AI on', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { onboarding_acknowledged: 'yes' }
		api.ai = true
		await mountApp('/dashboard')
		expect(navLabels()).toContain('Erklärbot')
	})
})
