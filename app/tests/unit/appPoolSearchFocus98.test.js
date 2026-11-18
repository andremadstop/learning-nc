/**
 * GitHub #98: a question picked in the Pools search opened its pool on the default tab — Manage
 * for an editor, but Training for a "View only" share — and the question had to be found with
 * Ctrl+F. App.vue now hands the question to the question list: the editor's Manage tab, and
 * "Fragen ansehen" for a read share; a learner (or the student view) keeps the default tab,
 * because "Fragen ansehen" would show a read share's answer keys.
 *
 * Harness from AppRoleAndStudentView.test.js (real App.vue, children stubbed).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { role: { role: 'instructor', group: 'learning-instructors' }, roleFails: false, questions: [] } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/role')) {
				return api.roleFails ? Promise.reject(new Error('503')) : Promise.resolve({ data: api.role })
			}
			if (/\/api\/pools\/\d+\/questions$/.test(url)) {
				return Promise.resolve({ data: api.questions })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const { plain, withProps } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
	withProps: (name, props, template) => ({ default: { name, props, template } }),
}))
vi.mock('@nextcloud/vue/components/NcAppContent', () => plain('NcAppContent'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => withProps('NcNoteCard', ['type'], '<div class="nc-note" :data-type="type"><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => withProps('NcCheckboxRadioSwitch', ['modelValue', 'type'],
	'<label class="nc-switch"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>'))
vi.mock('@nextcloud/vue/components/NcButton', () => withProps('NcButton', ['variant'], '<button type="button"><slot /></button>'))
vi.mock('../../src/components/PersonalSettings.vue', () => withProps('PersonalSettings', ['userRole', 'studentViewOverride'],
	'<div class="ps-stub" :data-role="userRole" :data-override="String(studentViewOverride)">'
	+ '<button class="ps-toggle" @click="$emit(\'student-view-override-changed\', !studentViewOverride)">toggle</button></div>'))
vi.mock('../../src/components/PoolList.vue', () => withProps('PoolList', ['userRole', 'instructorGroup', 'studentViewActive', 'roleLoadFailed'],
	'<div class="pool-list-stub" :data-role="userRole" :data-group="instructorGroup" :data-preview="String(studentViewActive)">'
	+ '<button class="hit-own" @click="$emit(\'selectPool\', { id: 7, name: \'Own\' }, { focusQuestionId: 71 })">own</button>'
	+ '<button class="hit-readonly" @click="$emit(\'selectPool\', { id: 8, name: \'Shared\', is_shared: true, permission: \'read\' }, { focusQuestionId: 81 })">ro</button>'
	+ '<button class="open-readonly" @click="$emit(\'selectPool\', { id: 8, name: \'Shared\', is_shared: true, permission: \'read\' })">ro card</button></div>'))
vi.mock('../../src/components/TrainingMode.vue', () => withProps('TrainingMode', ['poolId', 'totalQuestions', 'canManage', 'contentLanguage'],
	'<div class="training-stub" :data-can-manage="String(canManage)" :data-total="totalQuestions"><button class="add-q" @click="$emit(\'manage\')">add</button></div>'))
vi.mock('../../src/components/AnalyticsDashboard.vue', () => withProps('AnalyticsDashboard', ['poolId', 'gamificationEnabled', 'studentViewActive'],
	'<div class="stats-stub" :data-gamification="String(gamificationEnabled)" :data-preview="String(studentViewActive)"><button class="stats-back" @click="$emit(\'back\')">back</button></div>'))
vi.mock('../../src/components/QuestionList.vue', () => withProps('QuestionList', ['poolId', 'poolName', 'readonly', 'focusQuestionId'],
	'<div class="question-list-stub" :data-readonly="String(readonly)" :data-focus="String(focusQuestionId)"><button class="focus-done" @click="$emit(\'focus-done\')">done</button></div>'))
// GitHub #35: the course views get the effective role and the student-view flag.
vi.mock('../../src/components/CourseList.vue', () => withProps('CourseList', ['userRole', 'studentViewActive'],
	'<div class="course-list-stub" :data-role="userRole" :data-preview="String(studentViewActive)" />'))
vi.mock('../../src/components/CourseDetail.vue', () => withProps('CourseDetail', ['courseId', 'userRole', 'studentViewActive'],
	'<div class="course-detail-stub" :data-course="courseId" :data-role="userRole" :data-preview="String(studentViewActive)" />'))
for (const name of ['LeitnerMode', 'ExamMode', 'StudentDetail',
	'InstructorDashboard', 'SmartQueue', 'AdminSettings', 'GameshowMode', 'DuelMode', 'ArenaSelector', 'AbenteuerMode',
	'SubnetCalculator', 'DnsResolver', 'FirewallBuilder', 'PortScanner', 'RoutingTable', 'NatTable', 'WiresharkLite',
	'AuthFlowSimulator', 'OnboardingIntro', 'OnboardingRedesign', 'SkillMap', 'StudentDashboard', 'TeamLeadDashboard',
	]) {
	vi.doMock(`../../src/components/${name}.vue`, () => plain(name))
}
// Loaded through defineAsyncComponent(() => import(…)), which reads the module's default
// export only when the namespace is marked as an ES module.
vi.doMock('../../src/components/VirtuProf.vue', () => ({ __esModule: true, ...plain('VirtuProf') }))

const Shell = { render: () => h('div') }
let host, app, router, vm

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
	return host
}

beforeEach(() => {
	api.role = { role: 'instructor', group: 'learning-instructors' }
	api.roleFails = false
	api.questions = []
	localStorage.clear()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})


describe('App.vue: a question from the pool search opens in the question list (GitHub #98)', () => {
	it('a pool the instructor may edit opens Manage with the question handed on', async () => {
		api.questions = [{ id: 71 }]
		await mountApp('/pools')
		host.querySelector('.hit-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		const list = host.querySelector('.question-list-stub')
		expect(list.dataset.focus).toBe('71')
		expect(list.dataset.readonly).toBe('false')
		list.querySelector('.focus-done').click()
		await settle()
		expect(host.querySelector('.question-list-stub').dataset.focus).toBe('null')
	})

	it('a "View only" share opens "Fragen ansehen" with the question, not Training', async () => {
		api.questions = [{ id: 81 }]
		await mountApp('/pools')
		host.querySelector('.hit-readonly').click()
		await settle()
		expect(vm.mode).toBe('manage')
		const list = host.querySelector('.question-list-stub')
		expect(list.dataset.readonly).toBe('true')
		expect(list.dataset.focus).toBe('81')
	})

	it('the pool card of a read share still opens in Training, without a question', async () => {
		api.questions = [{ id: 81 }]
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(vm.poolFocusQuestionId).toBeNull()
	})

	it('in the student view a read share keeps Training and hands on no question', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		api.questions = [{ id: 81 }]
		await mountApp('/pools')
		host.querySelector('.hit-readonly').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(vm.poolFocusQuestionId).toBeNull()
		expect(host.querySelector('.question-list-stub')).toBeNull()
	})
})
