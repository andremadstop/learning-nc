/**
 * Review of GitHub #36: gamificationEnabled starts as true in App.vue and only turns false once
 * GET /api/settings/personal answers. The dashboard route rendered StudentDashboard right away,
 * so on a reload of "Heute" the daily challenge mounted (and sent its request) and streak and
 * level flashed with gamification off. The dashboard now waits for the settings.
 *
 * Same harness as AppGamificationFlag.test.js: the real App.vue, children stubbed.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { personal: null } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/role')) {
				return Promise.resolve({ data: { role: 'student', group: 'learning-instructors' } })
			}
			if (url.endsWith('/api/settings/personal')) {
				return api.personal
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const { plain, seen } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
	seen: [],
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => plain('NcAppContent'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => plain('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcButton', () => plain('NcButton'))
for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
	'AnalyticsDashboard', 'CourseList', 'CourseDetail', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'TeamLeadDashboard',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}
// records the gamification flag every StudentDashboard instance was created with
vi.doMock('../../src/components/StudentDashboard.vue', () => ({
	default: {
		name: 'StudentDashboard',
		props: { gamificationEnabled: { type: Boolean, default: true } },
		created() { seen.push(this.gamificationEnabled) },
		template: '<div class="stub-dashboard" />',
	},
}))
vi.doMock('../../src/components/VirtuProf.vue', () => ({ __esModule: true, ...plain('VirtuProf') }))

const Shell = { render: () => h('div') }
let host, app

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('App.vue: the dashboard waits for the gamification switch (GitHub #36)', () => {
	it('mounts no dashboard with gamification on before the settings arrive', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		let answer
		api.personal = new Promise((resolve) => { answer = resolve })
		seen.length = 0

		const { default: App } = await import('../../src/App.vue')
		const router = createRouter({
			history: createMemoryHistory(),
			routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
				.map((name) => ({ path: '/' + name, name, component: Shell }))
				.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
		})
		router.push('/dashboard')
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

		expect(host.querySelector('.stub-dashboard'), 'no dashboard while the switch is unknown').toBeNull()
		expect(seen).toEqual([])

		answer({ data: { gamification_enabled: false, onboarding_acknowledged: 'yes' } })
		await settle()

		expect(host.querySelector('.stub-dashboard')).not.toBeNull()
		expect(seen).toEqual([false])
	})
})
