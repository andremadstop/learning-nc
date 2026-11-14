/**
 * Audit #41: the dashboard's maintenance widget emits openSmartQueue({ maintenance: true }), and
 * App.openSmartQueue ignored the payload, so the widget opened the ordinary queue of all pools.
 */
import { describe, expect, it, vi } from 'vitest'

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

// Importing App.vue is slow on a loaded machine; the first import alone can exceed 5 s.
describe('App.openSmartQueue', { timeout: 30000 }, () => {
	it('opens the maintenance queue when the widget asks for it', async () => {
		const { default: App } = await import('../../src/App.vue')
		const ctx = { smartQueueMode: 'remediation', currentView: 'dashboard' }
		App.methods.openSmartQueue.call(ctx, { maintenance: true })
		expect(ctx).toEqual({ smartQueueMode: 'maintenance', currentView: 'smartQueue' })
	})

	it('opens the ordinary queue without a payload', async () => {
		const { default: App } = await import('../../src/App.vue')
		const ctx = { smartQueueMode: 'remediation', currentView: 'dashboard' }
		App.methods.openSmartQueue.call(ctx)
		expect(ctx).toEqual({ smartQueueMode: 'queue', currentView: 'smartQueue' })
	})
})
