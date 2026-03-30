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

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcEmptyContent.js', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcModal.js', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))

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
				swipe: true,
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
		selectedLearningPoolAllowsWfMode: { get: () => CourseTabLernraum.computed.selectedLearningPoolAllowsWfMode.call(instance) },
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

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['training', 'leitner', 'exam', 'materials'])
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

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['pools', 'curriculum', 'materials', 'knowledge'])
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
})
