/**
 * Fresh-eyes C5: in a pool shared view-only, the tab "View questions" said "Add, edit, delete and
 * import questions." above a note that the pool can only be viewed. Same harness as
 * AppDashboardGate36.test.js (the real App.vue, children stubbed).
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

const App = (await import('../../src/App.vue')).default

describe('pool window: the "View questions" help line of a view-only share (fresh-eyes C5)', () => {
	const describeManage = (poolPermission) => App.computed.modeDescriptions.call({ poolPermission, poolStatsBasis: 'boxes' }).manage

	it('does not promise adding, editing or deleting to someone who may only look', () => {
		expect(describeManage('read')).toBe('Die Fragen dieses Pools ansehen. Ändern kann sie nur, wer den Pool besitzt oder zum Bearbeiten geteilt bekommen hat.')
	})

	it('keeps the old line for the owner and an edit share', () => {
		expect(describeManage('owner')).toBe('Fragen hinzufügen, bearbeiten, löschen und importieren.')
		expect(describeManage('edit')).toBe('Fragen hinzufügen, bearbeiten, löschen und importieren.')
	})
})
