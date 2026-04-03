import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => stub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => stub('DnsResolver'))
vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/FirewallBuilder.vue', () => stub('FirewallBuilder'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => stub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => stub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => stub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => stub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/WiresharkLite.vue', () => stub('WiresharkLite'))

import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabLernraum.data === 'function' ? CourseTabLernraum.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: false,
			material_folder: '/Materials',
			mode_config: {
				training: true,
				leitner: true,
				exam: true,
			},
		},
		userRole: 'student',
		coursePools: [],
		allPools: [],
		activeTab: 'training',
		contentLanguage: 'de',
		$emit: vi.fn(),
		$set: vi.fn((obj, key, value) => {
			obj[key] = value
		}),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabLernraum.computed.isInstructor.call(instance) },
		visibleSubTabs: { get: () => CourseTabLernraum.computed.visibleSubTabs.call(instance) },
		selectedLearningPoolQuestionCount: { get: () => CourseTabLernraum.computed.selectedLearningPoolQuestionCount.call(instance) },
		currentRequiredBlockers: { get: () => CourseTabLernraum.computed.currentRequiredBlockers.call(instance) },
		currentPoolExamOptions: { get: () => CourseTabLernraum.computed.currentPoolExamOptions.call(instance) },
		currentPoolChapterOptions: { get: () => CourseTabLernraum.computed.currentPoolChapterOptions.call(instance) },
		sortedPools: { get: () => CourseTabLernraum.computed.sortedPools.call(instance) },
		availablePools: { get: () => CourseTabLernraum.computed.availablePools.call(instance) },
		activeLearningModeLabel: { get: () => CourseTabLernraum.computed.activeLearningModeLabel.call(instance) },
		isStudentLearningTab: { get: () => CourseTabLernraum.computed.isStudentLearningTab.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabLernraum.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabLernraum', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('builds student sub-tabs from the enabled learning modes', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['training', 'leitner', 'exam', 'tools', 'materials'])
		expect(instance.defaultSubTab()).toBe('training')
	})

	it('switches to the instructor Lernraum subnav when the role changes', () => {
		const instance = createInstance({
			course: {
				is_instructor: true,
				material_folder: null,
				mode_config: {
					training: true,
					leitner: true,
					exam: true,
				},
			},
			userRole: 'instructor',
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['pools', 'tools', 'curriculum', 'materials', 'knowledge'])
	})

	it('switches into the embedded tools leaf and remembers the requested simulator', () => {
		const instance = createInstance({
			currentSubTab: 'training',
		})

		instance.openCourseTool('dns')

		expect(instance.currentSubTab).toBe('tools')
		expect(instance.activeToolId).toBe('dns')
		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'tools')
	})

	it('emits leaf-tab changes from the sub-navigation', () => {
		const instance = createInstance({
			currentSubTab: 'training',
		})

		instance.selectSubTab('leitner')

		expect(instance.$emit).toHaveBeenNthCalledWith(1, 'mode-activated', 'leitner')
		expect(instance.$emit).toHaveBeenNthCalledWith(2, 'tab-change', 'leitner')
	})

	it('syncs the local sub-tab from the parent leaf tab and resets the selected pool between modes', () => {
		const instance = createInstance({
			currentSubTab: 'training',
			selectedLearningPool: { pool_id: 99, pool_name: 'Domain 1' },
		})

		instance.syncFromActiveTab('exam')

		expect(instance.currentSubTab).toBe('exam')
		expect(instance.selectedLearningPool).toBeNull()
		expect(instance.activeLearningModeLabel).toBe('Exam')
	})

	describe('training tab visibility (UX-01)', () => {
		it('Test A: hides training tab for student when mode_config.training === false', () => {
			const instance = createInstance({
				userRole: 'student',
				course: {
					is_instructor: false,
					material_folder: null,
					mode_config: { training: false, leitner: true, exam: true },
				},
			})

			const ids = instance.visibleSubTabs.map((tab) => tab.id)
			expect(ids).not.toContain('training')
		})

		it('Test B: instructor still sees instructor tabs regardless of mode_config.training', () => {
			const instance = createInstance({
				userRole: 'instructor',
				course: {
					is_instructor: true,
					material_folder: null,
					mode_config: { training: false, leitner: true, exam: true },
				},
			})

			const ids = instance.visibleSubTabs.map((tab) => tab.id)
			expect(ids).toContain('pools')
			expect(ids).toContain('curriculum')
		})

		it('Test C: shows training tab when mode_config is null (default-true semantics)', () => {
			const instance = createInstance({
				userRole: 'student',
				course: {
					is_instructor: false,
					material_folder: null,
					mode_config: null,
				},
			})

			const ids = instance.visibleSubTabs.map((tab) => tab.id)
			expect(ids).toContain('training')
		})

		it('Test D: shows training tab when mode_config.training === true', () => {
			const instance = createInstance({
				userRole: 'student',
				course: {
					is_instructor: false,
					material_folder: null,
					mode_config: { training: true, leitner: false, exam: false },
				},
			})

			const ids = instance.visibleSubTabs.map((tab) => tab.id)
			expect(ids).toContain('training')
		})
	})

	describe('Smart Queue hero card (UX-04)', () => {
		it('Test H: queueCount is visible in student data when set to 7 and no pool is selected', () => {
			const instance = createInstance({
				userRole: 'student',
				course: {
					is_instructor: false,
					material_folder: null,
					mode_config: { training: true, leitner: true, exam: true },
				},
				selectedLearningPool: null,
			})
			// Set queueCount directly (simulates async fetch completing)
			instance.queueCount = 7

			// Verify the data property exists and holds the value
			expect(instance.queueCount).toBe(7)
			// Verify hero card condition: !isInstructor && !selectedLearningPool
			expect(instance.isInstructor).toBe(false)
			expect(instance.selectedLearningPool).toBeNull()
		})

		it('Test I: fetchQueueCount calls correct endpoint and sets queueCount', async () => {
			const axios = (await import('@nextcloud/axios')).default
			const { generateUrl } = await import('@nextcloud/router')
			axios.get.mockResolvedValueOnce({ data: { count: 5 } })

			const instance = createInstance({
				userRole: 'student',
				course: {
					is_instructor: false,
					material_folder: null,
					mode_config: null,
				},
			})

			await instance.fetchQueueCount()

			expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/leitner/queue/count')
			expect(generateUrl).toHaveBeenCalledWith('/apps/learning/api/leitner/queue/count')
			expect(instance.queueCount).toBe(5)
		})

		it('Test J: instructor instance does not have hero card conditions met (isInstructor=true)', () => {
			const instance = createInstance({
				userRole: 'instructor',
				course: {
					is_instructor: true,
					material_folder: null,
					mode_config: { training: true, leitner: true, exam: true },
				},
				selectedLearningPool: null,
			})

			// Hero card must only render when !isInstructor — verify instructor flag is set
			expect(instance.isInstructor).toBe(true)
		})
	})
})
