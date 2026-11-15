/**
 * Persona sweep (GitHub #36): an instructor who is not a Nextcloud administrator opened the
 * app's Settings and landed on "Course management" — the administration settings. Every request
 * of that page answered 403 (GET /api/settings/admin, …/instructors, …/audit, …/operator,
 * …/support-tickets) and every Save failed with 403 as well. Those settings are admin-only on
 * the server; the tab is shown only to Nextcloud administrators (getCurrentUser().isAdmin).
 *
 * Same harness as AppDashboardGate36.test.js: the real App.vue, children stubbed.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { who } = vi.hoisted(() => ({ who: { isAdmin: false } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'ines', isAdmin: who.isAdmin })) }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/role')) return Promise.resolve({ data: { role: 'instructor', group: 'learning-instructors' } })
			if (url.endsWith('/api/settings/personal')) return Promise.resolve({ data: { gamification_enabled: false, onboarding_acknowledged: 'yes' } })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const { plain } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: `<div class="stub-${name}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => plain('NcAppContent'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => plain('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcButton', () => plain('NcButton'))
for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
	'AnalyticsDashboard', 'CourseList', 'CourseDetail', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'TeamLeadDashboard', 'StudentDashboard',
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

async function mountSettings() {
	const { default: App } = await import('../../src/App.vue')
	const router = createRouter({
		history: createMemoryHistory(),
		routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
			.map((name) => ({ path: '/' + name, name, component: Shell }))
			.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
	})
	router.push('/settings')
	await router.isReady()
	setActivePinia(createPinia())
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(App)
	app.use(createPinia())
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
})

describe('App.vue: the administration settings tab is for Nextcloud administrators only (GitHub #36)', () => {
	it('an instructor who is not an administrator gets the personal settings, no admin page', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		who.isAdmin = false
		await mountSettings()
		expect(host.querySelector('.stub-AdminSettings')).toBeNull()
		expect(host.textContent).not.toContain('Kurs-Verwaltung')
		expect(host.querySelector('.stub-PersonalSettings')).not.toBeNull()
	})

	it('a Nextcloud administrator keeps both tabs, administration first', async () => {
		who.isAdmin = true
		await mountSettings()
		expect(host.textContent).toContain('Kurs-Verwaltung')
		expect(host.querySelector('.stub-AdminSettings')).not.toBeNull()
	})
})
