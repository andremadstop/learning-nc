/**
 * GitHub #82: a link whose target is gone, archived, not (any longer) shared or never existed says
 * so and offers a way on, instead of an empty page or an error card without one.
 *
 * - Course (notifications, bookmarks): 404 and 403 show the same notice, without the course name,
 *   with "Zurück zu meinen Kursen"; the header no longer says "Laden..." forever. Other errors offer
 *   a retry. /courses/abc and /courses/0 show the same notice without asking the server.
 * - Archived course: still opens, with a note for learners and one for instructors.
 * - Pool (search results, ?poolId=): before, a dead id showed the plain pool list without a word
 *   (the error was only rendered inside an open pool). Now a notice; "back" drops the id.
 * - ?learningView=abenteuer with gamification off: a notice instead of the adventure.
 * Every notice is role="alert", so a screen reader announces it.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { course: null, courseStatus: 200, poolStatus: 200, gamification: true } }))
const reject = (status) => Promise.reject(Object.assign(new Error('http'), { response: { status } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') {
				return api.courseStatus === 200 ? Promise.resolve({ data: api.course }) : reject(api.courseStatus)
			}
			if (/\/api\/pools\/\d+$/.test(url)) {
				return api.poolStatus === 200 ? Promise.resolve({ data: { id: 7, name: 'Pool', permission: 'read' } }) : reject(api.poolStatus)
			}
			if (url.endsWith('/api/role')) return Promise.resolve({ data: { role: 'student', group: '' } })
			if (url.endsWith('/api/settings/personal')) return Promise.resolve({ data: { gamification_enabled: api.gamification, onboarding_acknowledged: 'yes' } })
			if (url === '/apps/learning/api/settings/tools') return Promise.resolve({ data: { enabled_tools: null } })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'lena', isAdmin: false })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { plain } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: `<div class="stub-${name}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => plain('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => plain('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcAppContent', () => plain('NcAppContent'))
for (const name of ['CourseTabLernraum', 'CourseTabTeilnehmer', 'CourseTabWettbewerb', 'CourseTabKommunikation',
	'CourseTabVerwaltung', 'ExamReadiness', 'CourseSummary', 'CourseRecentSessions']) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}

const notice = (root) => root.querySelector('[data-test="dead-link"]')

async function flush() {
	for (let i = 0; i < 8; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app
afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	api.course = null
	api.courseStatus = 200
	api.poolStatus = 200
	api.gamification = true
	window.history.replaceState(null, '', '/')
	vi.clearAllMocks()
})

describe('CourseDetail: a dead course link (GitHub #82)', () => {
	let detail, back

	async function mountDetail(courseId = 5, userRole = 'student') {
		const { default: CourseDetail } = await import('../../src/components/CourseDetail.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		const detailRef = ref(null)
		back = vi.fn()
		app = createApp({ render: () => h(CourseDetail, { ref: detailRef, courseId, userRole, onBack: back }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		detail = detailRef.value
	}

	it('a 404 shows one announced notice with a way back, and no "Laden..." heading', async () => {
		api.courseStatus = 404
		await mountDetail()
		const box = notice(host)
		expect(box).not.toBeNull()
		expect(box.getAttribute('role')).toBe('alert')
		expect(box.textContent).toContain('Dieser Kurs ist nicht verfügbar')
		expect(box.textContent).toContain('frag deine Kursleitung')
		expect(host.textContent).not.toContain('Laden...')
		box.querySelector('[data-test="dead-link-primary"]').click()
		await flush()
		expect(back).toHaveBeenCalledTimes(1)
	})

	it('a 403 looks exactly like a 404: nothing tells whether the course exists', async () => {
		api.courseStatus = 404
		await mountDetail()
		const notFound = notice(host).textContent
		app.unmount(); host.remove()
		api.courseStatus = 403
		await mountDetail()
		expect(notice(host).textContent).toBe(notFound)
	})

	it('a server error offers a retry, which loads the course', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		api.courseStatus = 500
		await mountDetail()
		expect(notice(host).textContent).toContain('Der Kurs konnte gerade nicht geladen werden')
		api.courseStatus = 200
		api.course = { id: 5, title: 'Erste Hilfe', status: 'active', is_instructor: false, mode_config: {}, pools: [], members: [] }
		notice(host).querySelector('[data-test="dead-link-primary"]').click()
		await flush()
		expect(notice(host)).toBeNull()
		expect(host.querySelector('h3')?.textContent).toBe('Erste Hilfe')
	})

	it('course id 0 (from /courses/abc) is a course that never existed, without a request', async () => {
		const axios = (await import('@nextcloud/axios')).default
		await mountDetail(0)
		expect(notice(host)?.textContent).toContain('Dieser Kurs ist nicht verfügbar')
		expect(axios.get).not.toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}')
	})

	it('an archived course still opens and says so, for learners and for instructors', async () => {
		api.course = { id: 5, title: 'Altkurs', status: 'archived', is_instructor: false, mode_config: {}, pools: [], members: [] }
		await mountDetail()
		expect(notice(host)).toBeNull()
		expect(host.querySelector('[data-test="course-archived"]')?.textContent).toContain('Du kannst die Inhalte weiter ansehen')
		app.unmount(); host.remove()
		api.course = { ...api.course, is_instructor: true }
		await mountDetail(5, 'instructor')
		expect(host.querySelector('[data-test="course-archived"]')?.textContent).toContain('wieder aktivieren')
		expect(detail.loadState).toBe('')
	})

	it('an active course shows no archive note', async () => {
		api.course = { id: 5, title: 'Neu', status: 'active', is_instructor: false, mode_config: {}, pools: [], members: [] }
		await mountDetail()
		expect(host.querySelector('[data-test="course-archived"]')).toBeNull()
	})
})

describe('App.vue: dead pool, course and adventure links (GitHub #82)', () => {
	const Shell = { render: () => h('div') }
	let router

	beforeEach(() => {
		for (const name of ['PersonalSettings', 'PoolList', 'TrainingMode', 'QuestionList', 'LeitnerMode', 'ExamMode',
			'AnalyticsDashboard', 'CourseList', 'StudentDetail', 'InstructorDashboard', 'SmartQueue',
			'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode', 'SubnetCalculator', 'DnsResolver',
			'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite', 'AuthFlowSimulator',
			'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'TeamLeadDashboard', 'StudentDashboard',
		]) {
			vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
		}
		// records the course id it is handed
		vi.doMock('../../src/components/CourseDetail.vue', () => ({
			default: { name: 'CourseDetail', props: { courseId: Number }, template: '<div class="stub-CourseDetail" :data-course="String(courseId)" />' },
		}))
		vi.doMock('../../src/components/VirtuProf.vue', () => ({ __esModule: true, ...plain('VirtuProf') }))
	})

	async function mountApp(path) {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const { default: App } = await import('../../src/App.vue')
		router = createRouter({
			history: createMemoryHistory(),
			routes: ['dashboard', 'courses', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']
				.map((name) => ({ path: '/' + name, name, component: Shell }))
				.concat([{ path: '/', name: 'home', component: Shell }, { path: '/courses/:id/:tab?', name: 'course-tab', component: Shell }]),
		})
		router.push(path)
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
		await flush()
	}

	it('a pool link that leads nowhere says so instead of showing the plain pool list', async () => {
		api.poolStatus = 404
		await mountApp('/pools?poolId=99')
		const box = notice(host)
		expect(box, 'a notice is shown').not.toBeNull()
		expect(box.getAttribute('role')).toBe('alert')
		expect(box.textContent).toContain('Dieser Pool ist nicht verfügbar')
		expect(host.querySelector('.stub-PoolList')).toBeNull()

		box.querySelector('[data-test="dead-link-primary"]').click()
		await flush()
		expect(router.currentRoute.value.name).toBe('pools')
		expect(router.currentRoute.value.query.poolId).toBeUndefined()
		expect(notice(host)).toBeNull()
		expect(host.querySelector('.stub-PoolList')).not.toBeNull()
	})

	it('a pool that is not shared (403) looks like one that does not exist', async () => {
		api.poolStatus = 403
		await mountApp('/pools?poolId=99')
		expect(notice(host)?.textContent).toContain('Dieser Pool ist nicht verfügbar')
	})

	it('a failed pool load offers a retry', async () => {
		api.poolStatus = 500
		await mountApp('/pools?poolId=99')
		expect(notice(host)?.textContent).toContain('Pool konnte nicht geöffnet werden')
		expect(notice(host).querySelector('[data-test="dead-link-secondary"]')?.textContent).toContain('Zurück zu den Pools')
	})

	it('/courses/abc opens the course view with id 0 (which says the course is not there)', async () => {
		await mountApp('/courses/abc')
		expect(host.querySelector('.stub-CourseDetail')?.getAttribute('data-course')).toBe('0')
	})

	it('?learningView=abenteuer with gamification off explains instead of opening the adventure', async () => {
		api.gamification = false
		window.history.replaceState(null, '', '/?learningView=abenteuer')
		await mountApp('/')
		expect(host.querySelector('.stub-AbenteuerMode')).toBeNull()
		expect(notice(host)?.textContent).toContain('Das Abenteuer ist hier ausgeschaltet')
	})

	it('with gamification on the adventure link opens the adventure as before', async () => {
		api.gamification = true
		window.history.replaceState(null, '', '/?learningView=abenteuer')
		await mountApp('/')
		expect(host.querySelector('.stub-AbenteuerMode')).not.toBeNull()
		expect(notice(host)).toBeNull()
	})
})
