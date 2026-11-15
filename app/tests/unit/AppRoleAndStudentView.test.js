/**
 * GitHub #34: "Open the pool, then the Manage tab … I can't find this tab."
 *
 * Mounts the real App.vue (children stubbed) and checks the four ways the app hid the
 * question tools without saying why:
 *  1. the student view: the switch to leave it was only rendered in the instructor branch of
 *     the settings, so an instructor in the student view had no way back, and no banner;
 *  2. PoolList got the real role while every other view used the effective one;
 *  3. an empty pool opened in Training ("No questions") instead of Manage;
 *  4. a failed role request fell back to the student view without a word.
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
	+ '<button class="open-own" @click="$emit(\'selectPool\', { id: 7, name: \'Empty\' })">own</button>'
	+ '<button class="open-readonly" @click="$emit(\'selectPool\', { id: 8, name: \'Shared\', is_shared: true, permission: \'read\' })">ro</button>'
	+ '<button class="open-edit" @click="$emit(\'selectPool\', { id: 9, name: \'Edit share\', is_shared: true, permission: \'edit\' })">edit</button></div>'))
vi.mock('../../src/components/TrainingMode.vue', () => withProps('TrainingMode', ['poolId', 'totalQuestions', 'canManage', 'contentLanguage'],
	'<div class="training-stub" :data-can-manage="String(canManage)" :data-total="totalQuestions"><button class="add-q" @click="$emit(\'manage\')">add</button></div>'))
vi.mock('../../src/components/AnalyticsDashboard.vue', () => withProps('AnalyticsDashboard', ['poolId', 'gamificationEnabled'],
	'<div class="stats-stub" :data-gamification="String(gamificationEnabled)"><button class="stats-back" @click="$emit(\'back\')">back</button></div>'))
vi.mock('../../src/components/QuestionList.vue', () => withProps('QuestionList', ['poolId', 'poolName', 'readonly'], '<div class="question-list-stub" />'))
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

const banner = () => host.querySelector('.student-view-banner')

describe('App.vue: the student view can always be left (GitHub #34)', () => {
	it('an instructor in the student view gets the switch in the settings, with the real role', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/settings')
		const ps = host.querySelector('.ps-stub')
		expect(ps).not.toBeNull()
		expect(ps.dataset.role).toBe('instructor')
		expect(ps.dataset.override).toBe('true')

		ps.querySelector('.ps-toggle').click()
		await settle()
		expect(localStorage.getItem('learning:view-as-student')).toBeNull()
		expect(vm.effectiveUserRole).toBe('instructor')
		expect(banner()).toBeNull()
	})

	it('shows a banner with an exit button on every page while the student view is on', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/pools')
		expect(banner()).not.toBeNull()
		expect(banner().textContent).toContain('Schüler-Ansicht aktiv')

		banner().querySelector('.student-view-banner__exit').click()
		await settle()
		expect(banner()).toBeNull()
		expect(localStorage.getItem('learning:view-as-student')).toBeNull()
	})

	it('a real student with a stale localStorage flag sees no banner', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/pools')
		expect(banner()).toBeNull()
	})

	it('PoolList gets the effective role, like every other view', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/pools')
		const list = host.querySelector('.pool-list-stub')
		expect(list.dataset.role).toBe('student')
		expect(list.dataset.preview).toBe('true')
		expect(list.dataset.group).toBe('learning-instructors')
	})
})

describe('App.vue: the student view reaches the course views (GitHub #35)', () => {
	it('an instructor in the student view opens a course as a learner, with the course banner', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/courses/5/lernraum')
		const detail = host.querySelector('.course-detail-stub')
		expect(detail).not.toBeNull()
		expect(detail.dataset.course).toBe('5')
		expect(detail.dataset.role).toBe('student')
		expect(detail.dataset.preview).toBe('true')
		expect(banner().textContent).toContain('Schüler-Ansicht aktiv')
		expect(banner().textContent).toContain('diesen Kurs')
		// One banner, one exit: CourseDetail does not render a second one.
		expect(host.querySelectorAll('.student-view-banner')).toHaveLength(1)

		banner().querySelector('.student-view-banner__exit').click()
		await settle()
		expect(host.querySelector('.course-detail-stub').dataset.preview).toBe('false')
		expect(host.querySelector('.course-detail-stub').dataset.role).toBe('instructor')
	})

	it('the course list gets the effective role and the preview flag', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/courses')
		const list = host.querySelector('.course-list-stub')
		expect(list).not.toBeNull()
		expect(list.dataset.role).toBe('student')
		expect(list.dataset.preview).toBe('true')
		expect(banner().textContent).not.toContain('diesen Kurs')
	})

	it('without the student view an instructor opens a course with the instructor role', async () => {
		await mountApp('/courses/5/lernraum')
		const detail = host.querySelector('.course-detail-stub')
		expect(detail.dataset.role).toBe('instructor')
		expect(detail.dataset.preview).toBe('false')
		expect(banner()).toBeNull()
	})
})

describe('App.vue: an empty pool and the Manage mode (GitHub #34)', () => {
	it('an instructor opening an empty own pool lands in Manage', async () => {
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		expect(host.querySelector('.question-list-stub')).not.toBeNull()
	})

	it('an empty read-only share, or the student view, stays in Training', async () => {
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(host.querySelector('.training-stub').dataset.canManage).toBe('false')
	})

	// Since GitHub #46 an editor opens every pool in Manage; the empty-state button in Training
	// (reached through the practise switch) still leads there.
	it('a pool with questions opens in Manage for its editor; the empty-state button in Training switches back', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		vm.setMode('train')
		await settle()
		const training = host.querySelector('.training-stub')
		expect(training.dataset.canManage).toBe('true')
		training.querySelector('.add-q').click()
		await settle()
		expect(vm.mode).toBe('manage')
	})

	it('leaving Manage refreshes the question count Training starts from', async () => {
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		api.questions = [{ id: 1 }, { id: 2 }]
		vm.setMode('train')
		await settle()
		expect(host.querySelector('.training-stub').dataset.total).toBe('2')
	})

	it('turning on the student view while Manage is open falls back to Training', async () => {
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		vm.setStudentViewOverride(true)
		await settle()
		expect(vm.mode).toBe('train')
	})
})

// Andre's decision for GitHub #34: Manage follows the pool permission, not the instructor role.
// The backend (canEditPool) lets the owner and an edit share add, edit and import questions;
// the UI hid all of it from anyone without the instructor role.
describe('App.vue: Manage follows the pool permission (GitHub #34)', () => {
	const modeIds = () => vm.filteredModes.map((m) => m.id)
	const tabLabels = () => [...host.querySelectorAll('.mode-selector .mode-btn')].map((b) => b.textContent.trim())

	it('a student with an edit share sees Manage, and an empty shared pool opens there', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		await mountApp('/pools')
		host.querySelector('.open-edit').click()
		await settle()
		expect(modeIds()).toContain('manage')
		expect(tabLabels()).toContain('Verwalten')
		expect(vm.mode).toBe('manage')
		expect(host.querySelector('.question-list-stub')).not.toBeNull()
	})

	it('a student who owns a pool sees Manage', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		// GitHub #46: the editor's window, Manage first, no Statistics for the student role.
		expect(vm.mode).toBe('manage')
		expect(modeIds()).toEqual(['manage'])
		vm.setPoolPractise(true)
		await settle()
		expect(modeIds()).toEqual(['manage', 'train', 'leitner', 'exam', 'gameshow'])
	})

	it('a student with a read share gets neither Manage nor the read-only question list (answer keys)', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(modeIds()).not.toContain('manage')
		expect(vm.mode).toBe('train')
	})

	it('Statistics stays an instructor view: a student with an edit share does not get it', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		await mountApp('/pools')
		host.querySelector('.open-edit').click()
		await settle()
		expect(modeIds()).not.toContain('stats')
	})

	it('the student view still hides Manage, even on an own pool', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(modeIds()).not.toContain('manage')
		expect(vm.mode).toBe('train')
	})

	it('an instructor keeps the read-only question list on a read share', async () => {
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(modeIds()).toContain('manage')
		expect(vm.canManagePool).toBe(false)
	})

	it('a failed pool request from the skill map does not pretend the user owns the pool', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		await mountApp('/pools')
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockImplementationOnce(() => Promise.reject(new Error('404')))
		await vm.openPoolFromSkillMap(42)
		await settle()
		expect(vm.poolPermission).toBe('read')
		expect(modeIds()).not.toContain('manage')
	})

	it('a failed role request still leaves Manage on an edit share', async () => {
		api.roleFails = true
		await mountApp('/pools')
		host.querySelector('.open-edit').click()
		await settle()
		expect(modeIds()).toContain('manage')
	})
})

describe('App.vue: a failed role request is not silent (GitHub #34)', () => {
	it('logs the error and shows a note instead of quietly becoming a student', async () => {
		api.roleFails = true
		await mountApp('/pools')
		expect(console.error).toHaveBeenCalled()
		const note = host.querySelector('.role-load-failed')
		expect(note).not.toBeNull()
		expect(host.querySelector('.pool-list-stub').dataset.role).toBe('student')

		api.roleFails = false
		note.querySelector('button').click()
		await settle()
		expect(host.querySelector('.role-load-failed')).toBeNull()
		expect(host.querySelector('.pool-list-stub').dataset.role).toBe('instructor')
	})
})

/**
 * GitHub #35: the course's tools master switch (mode_config.tools) is read by every view that
 * lists tools. App.vue's global tools tabs went through courseToolIds() without a test, so
 * reverting it to effectiveCourseTools() left the suite green.
 */
describe('App.vue: the tools tabs follow the course master switch (GitHub #35)', () => {
	async function tabsFor(selectedCourse) {
		const { default: App } = await import('../../src/App.vue')
		const ctx = { selectedCourse, enabledTools: ['subnet', 'dns', 'firewall'] }
		ctx.toolsTabs = App.computed.toolsTabs.call(ctx)
		return App.computed.visibleToolsTabs.call(ctx).map((tab) => tab.id)
	}

	it('a selected course with the tools switched off has no visible tools', async () => {
		expect(await tabsFor({ id: 5, title: 'Netz', mode_config: { tools: false }, enabled_tools: null })).toEqual([])
	})

	it('a selected course with the switch on, or saved before it existed, keeps its tools', async () => {
		expect(await tabsFor({ id: 5, title: 'Netz', mode_config: { tools: true }, enabled_tools: ['dns'] })).toEqual(['dns'])
		expect(await tabsFor({ id: 5, title: 'Netz', mode_config: { training: true }, enabled_tools: null })).toEqual(['subnet', 'dns', 'firewall'])
	})
})

// GitHub #46: Arena (Duel, Gameshow) is a game mode. With gamification off the pool window still
// offered it, for learners and editors alike.
describe('App.vue: no Arena in the pool window with gamification off (GitHub #46)', () => {
	const modeIds = () => vm.filteredModes.map((m) => m.id)

	it('drops Arena for every role and leaves an open Arena for Training', async () => {
		for (const role of ['instructor', 'student']) {
			api.role = { role, group: 'learning-instructors' }
			api.questions = [{ id: 1 }]
			await mountApp('/pools')
			// The owner is a pool editor (GitHub #46): Arena sits behind the practise switch.
			vm.setPoolPractise(true)
			host.querySelector('.open-own').click()
			await settle()
			expect(modeIds(), role).toContain('gameshow')
			vm.setMode('gameshow')
			vm.gamificationEnabled = false
			await settle()
			expect(modeIds(), role).not.toContain('gameshow')
			expect(vm.mode, role).toBe('train')
			app.unmount()
			host.remove()
			app = null
		}
	})
})

/**
 * GitHub #46 (revised decision): "instructors / admins can't do training … why does an admin need
 * 'Training' tab when clicking on a pool". Whoever may edit the pool (owner or edit share) works
 * in Manage, the first and active tab, next to Statistics; a switch "practise as learner" brings
 * back Training, Leitner and Exam and is remembered. Learners without edit rights keep the window.
 */
describe('App.vue: the pool window of a pool editor (GitHub #46)', () => {
	const modeIds = () => vm.filteredModes.map((m) => m.id)
	const tabLabels = () => [...host.querySelectorAll('.mode-selector .mode-btn')].map((b) => b.textContent.trim())
	const practiseSwitch = () => host.querySelector('.pool-practise-switch input')

	it('an instructor opens an own pool with questions in Manage, the first tab, next to Statistics only', async () => {
		api.questions = [{ id: 1 }, { id: 2 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('manage')
		expect(tabLabels()).toEqual(['Verwalten', 'Statistik'])
		expect(host.querySelector('.mode-btn.active').textContent.trim()).toBe('Verwalten')
		expect(host.querySelector('.question-list-stub')).not.toBeNull()
		expect(host.querySelector('.training-stub')).toBeNull()
	})

	it('an edit share gets the same editor window', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-edit').click()
		await settle()
		expect(vm.mode).toBe('manage')
		expect(modeIds()).toEqual(['manage', 'stats'])
	})

	it('the switch shows Training, Leitner, Exam and Arena after Manage and Statistics, and hides them again', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(practiseSwitch()).not.toBeNull()
		expect(host.querySelector('.pool-practise-switch').textContent).toContain('Als Lernende üben')
		practiseSwitch().checked = true
		practiseSwitch().dispatchEvent(new Event('change'))
		await settle()
		expect(modeIds()).toEqual(['manage', 'stats', 'train', 'leitner', 'exam', 'gameshow'])
		expect(localStorage.getItem('learning:pool-practise')).toBe('yes')

		vm.setMode('leitner')
		await settle()
		practiseSwitch().checked = false
		practiseSwitch().dispatchEvent(new Event('change'))
		await settle()
		expect(modeIds()).toEqual(['manage', 'stats'])
		// The open Leitner tab is gone: back to Manage, not to a hidden Training.
		expect(vm.mode).toBe('manage')
		expect(localStorage.getItem('learning:pool-practise')).toBeNull()
	})

	it('the switch is remembered across visits', async () => {
		localStorage.setItem('learning:pool-practise', 'yes')
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(practiseSwitch().checked).toBe(true)
		expect(modeIds()).toContain('train')
		// Still opens in Manage: the switch adds tabs, it does not change the start tab.
		expect(vm.mode).toBe('manage')
	})

	it('a throwing localStorage leaves the switch working for the visit', async () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied') })
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied') })
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.poolPractise).toBe(false)
		vm.setPoolPractise(true)
		await settle()
		expect(modeIds()).toContain('train')
	})

	it('a path into a learner mode turns the switch on instead of opening a hidden tab', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		vm.setMode('exam')
		await settle()
		expect(vm.mode).toBe('exam')
		expect(vm.poolPractise).toBe(true)
		expect(modeIds()).toContain('exam')
		expect(host.querySelector('.mode-btn.active').textContent.trim()).toBe('Prüfung')
	})

	it('back from Statistics lands on Manage for an editor', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		vm.setMode('stats')
		await settle()
		host.querySelector('.stats-back').click()
		await settle()
		expect(vm.mode).toBe('manage')
	})

	it('Statistics gets the gamification switch', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		vm.gamificationEnabled = false
		vm.setMode('stats')
		await settle()
		expect(host.querySelector('.stats-stub').dataset.gamification).toBe('false')
	})

	it('a learner without edit rights keeps the old window, in Training, without the switch', async () => {
		api.role = { role: 'student', group: 'learning-instructors' }
		api.questions = [{ id: 1 }]
		localStorage.setItem('learning:pool-practise', 'yes')
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(modeIds()).toEqual(['train', 'leitner', 'exam', 'gameshow'])
		expect(practiseSwitch()).toBeNull()
	})

	it('an instructor on a read share keeps every tab, the read-only question list included', async () => {
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-readonly').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(modeIds()).toEqual(['train', 'leitner', 'exam', 'gameshow', 'stats', 'manage'])
		expect(practiseSwitch()).toBeNull()
	})

	it('the student view shows an own pool as learners see it', async () => {
		localStorage.setItem('learning:view-as-student', 'yes')
		api.questions = [{ id: 1 }]
		await mountApp('/pools')
		host.querySelector('.open-own').click()
		await settle()
		expect(vm.mode).toBe('train')
		expect(modeIds()).toEqual(['train', 'leitner', 'exam', 'gameshow'])
		expect(practiseSwitch()).toBeNull()
	})
})
