/**
 * Codeberg #7, third round: "Regardless of the selection, all tools remain visible."
 *
 * 5.4.6 fixed the settings form, but nothing checked who READS the setting. The learning space
 * — the only place tools appear — ignored the admin's global selection entirely and treated an
 * empty course selection as "all". The unit harness for CourseTabLernraum carried its own copy
 * of that logic, so it could never see the defect.
 *
 * These tests mount the real component in the learner view and count what reaches the DOM.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
for (const name of ['CourseKnowledgeImport', 'CourseMaterials', 'AuthFlowSimulator', 'DnsResolver', 'ExamMode',
	'FirewallBuilder', 'KnowledgeModeration', 'LeitnerMode', 'NatTable', 'PortScanner', 'RoutingTable',
	'SubnetCalculator', 'TrainingMode', 'WiresharkLite']) {
	vi.doMock(`../../src/components/${name}.vue`, () => stub(name))
}

let host, app

async function mountLearner({ enabledTools, adminEnabledTools }) {
	const { default: CourseTabLernraum } = await import('../../src/components/CourseTabLernraum.vue')
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(CourseTabLernraum, {
		courseId: 5,
		course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: enabledTools },
		userRole: 'student',
		activeTab: 'tools',
		adminEnabledTools,
	})
	app.config.globalProperties.t = (_app, str) => str
	app.config.globalProperties.n = (_app, s) => s
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	return host
}

const chipCount = (root) => root.querySelectorAll('.course-tool-chip, .sim-nav__item').length

describe('tools in the rendered learner view (Codeberg #7)', () => {
	beforeEach(() => {
		globalThis.t = (_app, str) => str
	})
	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('renders no tool when the course switched every tool off', async () => {
		const root = await mountLearner({ enabledTools: [], adminEnabledTools: null })
		expect(chipCount(root)).toBe(0)
	})

	it('renders no tool when the admin switched every tool off', async () => {
		const root = await mountLearner({ enabledTools: null, adminEnabledTools: [] })
		expect(chipCount(root)).toBe(0)
	})

	it('renders exactly the tools both levels allow', async () => {
		const root = await mountLearner({ enabledTools: ['subnet', 'nat'], adminEnabledTools: ['subnet', 'dns'] })
		const labels = [...root.querySelectorAll('.sim-nav__item .sim-nav__label')].map((el) => el.textContent.trim())
		expect(labels).toEqual(['Subnetz'])
	})
})
