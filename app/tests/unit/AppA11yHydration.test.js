/**
 * Audit 2026-11 (S34): "Ruhige Darstellung" (animations off) was ignored after every page load
 * until the Settings tab was opened. App.fetchPersonalSettings() reads GET /api/settings/personal,
 * which carries animations_enabled, but only PersonalSettings.vue hydrated the a11y store — and
 * that component renders only on the settings route. Every avatar on the dashboard and in
 * VirtuProf read the store default (true) and animated again.
 *
 * Mounts the real App.vue (children stubbed) with Pinia installed, as main.js does.
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
	app.mount(host)
	await settle()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('App.vue: the calm-display setting applies from the first page', () => {
	it('animations_enabled = no reaches the a11y store without visiting Settings', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { animations_enabled: 'no', onboarding_acknowledged: 'yes' }
		await mountApp('/dashboard')
		const { useA11yStore } = await import('../../src/stores/a11yStore.js')
		expect(useA11yStore().animationsEnabled).toBe(false)
	})

	it('animations stay on when the setting is yes', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.personal = { animations_enabled: 'yes', onboarding_acknowledged: 'yes' }
		await mountApp('/dashboard')
		const { useA11yStore } = await import('../../src/stores/a11yStore.js')
		expect(useA11yStore().animationsEnabled).toBe(true)
	})
})
