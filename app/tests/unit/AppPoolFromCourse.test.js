/**
 * Audit 2026-11 (S33): a pool opened from a course lost its course context. openPoolFromCourse()
 * set _skipRouteReset, pushed the 'pools' route and cleared the flag in $nextTick — but vue-router
 * applies the navigation several promise steps later, so the $route watcher saw the flag already
 * false and ran backToPools(): poolFromCourse=false, poolFromCourseObj=null. The header said
 * "Zurück zu Pools" and the course was gone.
 *
 * Mounts the real App.vue (children stubbed) and opens a pool from the CourseDetail stub.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/role')) {
				return Promise.resolve({ data: { role: 'student', group: 'learning-instructors' } })
			}
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: { onboarding_acknowledged: 'yes' } })
			}
			if (/\/api\/pools\/\d+$/.test(url)) {
				return Promise.resolve({ data: { id: 5, name: 'Kurs-Pool', is_shared: true, permission: 'read' } })
			}
			if (/\/api\/pools\/\d+\/questions$/.test(url)) {
				return Promise.resolve({ data: [{ id: 1 }] })
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
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button type="button" class="nc-btn"><slot /></button>' } }))
vi.mock('../../src/components/CourseDetail.vue', () => ({ default: { name: 'CourseDetail', props: ['courseId'],
	template: '<div class="course-detail-stub"><button class="open-pool" @click="$emit(\'openPool\', 5)">pool</button></div>' } }))
for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
	'AnalyticsDashboard', 'CourseList', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
	'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
	'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
	'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'StudentDashboard', 'TeamLeadDashboard',
]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}
vi.doMock('../../src/components/VirtuProf.vue', () => ({ __esModule: true, ...plain('VirtuProf') }))

const Shell = { render: () => h('div') }
let host, app, vm, router

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountApp(path) {
	const { default: App } = await import('../../src/App.vue')
	router = createRouter({
		history: createMemoryHistory(),
		routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
			.map((name) => ({ path: '/' + name, name, component: Shell }))
			.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
	})
	router.push(path)
	await router.isReady()
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(App)
	app.use(router)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	vm = app.mount(host)
	await settle()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('App.vue: a pool opened from a course keeps the course', () => {
	it('shows "back to course" and returns to the course it came from', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await mountApp('/courses/12/lernraum')
		const open = host.querySelector('.course-detail-stub .open-pool')
		expect(open).not.toBeNull()
		open.click()
		await settle()

		expect(router.currentRoute.value.name).toBe('pools')
		expect(vm.selectedPool?.id).toBe(5)
		expect(vm.poolFromCourse).toBe(true)
		expect(vm.poolFromCourseObj?.id).toBe(12)
		const labels = [...host.querySelectorAll('.nc-btn')].map((b) => b.textContent.trim())
		expect(labels).toContain('← Zurück zum Kurs')

		vm.backToCourse()
		await settle()
		expect(router.currentRoute.value.name).toBe('course-tab')
		expect(Number(router.currentRoute.value.params.id)).toBe(12)
	})

	it('a later visit to the pool list still resets the pool view', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await mountApp('/courses/12/lernraum')
		host.querySelector('.course-detail-stub .open-pool').click()
		await settle()
		router.push('/dashboard')
		await settle()
		router.push('/pools')
		await settle()
		expect(vm.selectedPool).toBeNull()
		expect(vm.poolFromCourse).toBe(false)
	})
})
