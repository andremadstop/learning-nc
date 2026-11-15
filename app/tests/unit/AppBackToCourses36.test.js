/**
 * Persona sweep (GitHub #36): "← Back to courses" showed the course list but left the address bar
 * on /courses/<id>/<tab>, so a reload or a bookmark reopened the course. The back event must go
 * through the router, whose watcher clears the selected course.
 *
 * Same harness as AppSettingsAdminOnly36.test.js: the real App.vue, CourseDetail stubbed with a
 * button that emits `back` like the real one.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'ines', isAdmin: false })) }))
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
	'AnalyticsDashboard', 'CourseList', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'TeamLeadDashboard', 'StudentDashboard',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}
// CourseDetail's back button, as the real component emits it
vi.doMock('../../src/components/CourseDetail.vue', () => ({
	default: { name: 'CourseDetail', emits: ['back'], template: '<button class="stub-back" @click="$emit(\'back\')">back</button>' },
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
})

describe('App.vue: "Back to courses" updates the route (GitHub #36)', () => {
	it('leaves /courses/<id>/<tab> for the course list', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const { default: App } = await import('../../src/App.vue')
		const router = createRouter({
			history: createMemoryHistory(),
			routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
				.map((name) => ({ path: '/' + name, name, component: Shell }))
				.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
		})
		router.push('/courses/1/lernraum')
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

		const back = host.querySelector('.stub-back')
		expect(back, 'the course is shown').not.toBeNull()
		back.click()
		await settle()

		expect(router.currentRoute.value.name).toBe('courses')
		expect(host.querySelector('.stub-back'), 'the course is closed').toBeNull()
	})
})
