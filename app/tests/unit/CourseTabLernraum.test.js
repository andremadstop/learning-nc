import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

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
import { ALL_TOOL_IDS } from '../../src/utils/toolCatalog.js'

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
		// The prop CourseDetail hands down (course membership minus the student view).
		actsAsInstructor: false,
		coursePools: [],
		allPools: [],
		activeTab: 'training',
		contentLanguage: 'de',
		$emit: vi.fn(),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabLernraum.computed.isInstructor.call(instance) },
		// Calls the real computed. This harness used to carry its own copy of the logic, so the
		// tests checked the copy and never the component — how Codeberg #7 stayed green.
		courseToolTabs: { get: () => CourseTabLernraum.computed.courseToolTabs.call(instance) },
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

		// GitHub #35: the learning material comes before practice and exam, and is the landing tab
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['materials', 'training', 'leitner', 'exam', 'tools'])
		expect(instance.defaultSubTab()).toBe('materials')
		expect(instance.visibleSubTabs[0].label).toBe('Lernmaterial')
	})

	it('without a material folder the first learning mode stays the landing tab', () => {
		const instance = createInstance({ course: {
			is_instructor: false, material_folder: null, mode_config: { training: true, leitner: true, exam: true },
		} })

		expect(instance.defaultSubTab()).toBe('training')
	})

	// Codeberg #9: the practice exam tab follows practice_enabled alone — a course may switch the
	// CompTIA preset exam off (mode_config.exam) and still offer its own practice exam.
	it('shows the practice exam tab when the course offers one, even with the preset exam off', () => {
		const instance = createInstance({
			course: {
				is_instructor: false,
				material_folder: null,
				mode_config: { training: true, leitner: true, exam: false },
				practice_enabled: true,
				practice_questions: 25,
				practice_minutes: 0,
				practice_pass_percent: 70,
			},
		})
		const ids = instance.visibleSubTabs.map((tab) => tab.id)
		expect(ids).toContain('practice')
		expect(ids).not.toContain('exam')
		expect(CourseTabLernraum.computed.practiceConfig.call(instance)).toEqual({ questions: 25, minutes: 0, passPercent: 70, requiredOnly: false })
	})

	// Codeberg #7: tool selections were stored correctly but never applied to what learners see.
	describe('tool selection reaches the learning space', () => {
		const ids = (instance) => instance.courseToolTabs.map((tool) => tool.id)

		// GitHub #35: mode_config.tools is the course's master switch; it beats the selection.
		it('shows no tools, no tool hints and no tools tab while the master switch is off', () => {
			const student = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true, tools: false }, enabled_tools: null },
			})
			expect(ids(student)).toEqual([])
			expect(student.visibleSubTabs.map((tab) => tab.id)).not.toContain('tools')

			const instructor = createInstance({
				actsAsInstructor: true,
				course: { is_instructor: true, material_folder: null, mode_config: { tools: false }, enabled_tools: ['dns'] },
			})
			expect(ids(instructor)).toEqual([])
			expect(instructor.visibleSubTabs.map((tab) => tab.id)).not.toContain('tools')
		})

		it('keeps the tools of a course saved before the master switch existed', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: ['dns'] },
			})
			expect(ids(instance)).toEqual(['dns'])
		})

		it('shows no tools and no tools tab when the course switched every tool off', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: [] },
			})
			expect(ids(instance)).toEqual([])
			expect(instance.visibleSubTabs.map((tab) => tab.id)).not.toContain('tools')
		})

		it('honours the admin selection when the course inherits it', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: null },
				adminEnabledTools: ['dns'],
			})
			expect(ids(instance)).toEqual(['dns'])
		})

		it('shows nothing when the admin switched every tool off, whatever the course says', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: ['subnet', 'dns'] },
				adminEnabledTools: [],
			})
			expect(ids(instance)).toEqual([])
			expect(instance.visibleSubTabs.map((tab) => tab.id)).not.toContain('tools')
		})

		it('narrows the admin selection by the course selection', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: ['subnet', 'nat'] },
				adminEnabledTools: ['subnet', 'dns'],
			})
			expect(ids(instance)).toEqual(['subnet'])
		})

		it('offers every tool when neither level restricts', () => {
			const instance = createInstance({
				course: { is_instructor: false, material_folder: null, mode_config: { training: true }, enabled_tools: null },
				adminEnabledTools: null,
			})
			expect(ids(instance)).toEqual([...ALL_TOOL_IDS])
		})
	})

	// Codeberg #9 follow-up: the server sends `required` as 0/1. Reading 0 as "required" pre-ticked
	// the box for supplementary pools, so saving any other rule silently re-marked them required.
	it('opens the pool rules with the stored required flag, 0/1 included', () => {
		const instance = createInstance()
		instance.openPoolRulesModal({ pool_id: 2, pool_name: 'Praxis', required: 0, required_enforced: false })
		expect(instance.poolRulesForm.required).toBe(false)
		instance.openPoolRulesModal({ pool_id: 1, pool_name: 'Theorie', required: 1, required_enforced: false })
		expect(instance.poolRulesForm.required).toBe(true)
		instance.openPoolRulesModal({ pool_id: 3, pool_name: 'Legacy' })
		expect(instance.poolRulesForm.required).toBe(true)
	})

	it('hides the practice exam tab when the course does not offer one', () => {
		const instance = createInstance()
		expect(instance.visibleSubTabs.map((tab) => tab.id)).not.toContain('practice')
	})

	it('shows the instructor Lernraum subnav to an instructor of the course', () => {
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
			actsAsInstructor: true,
			aiEnabled: true,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['pools', 'tools', 'curriculum', 'materials', 'knowledge'])
	})

	// GitHub #35: the knowledge import only feeds the AI assistant
	it('leaves out the knowledge tab while the admin has AI switched off', () => {
		const instance = createInstance({
			course: { is_instructor: true, material_folder: null, mode_config: { training: true, leitner: true, exam: true } },
			userRole: 'instructor',
			actsAsInstructor: true,
			aiEnabled: false,
		})

		expect(instance.visibleSubTabs.map((tab) => tab.id)).not.toContain('knowledge')
		expect(instance.visibleSubTabs.map((tab) => tab.id)).toContain('materials')
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
				actsAsInstructor: true,
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
				actsAsInstructor: true,
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

	// GitHub #35: isInstructor was `userRole === 'instructor' || course.is_instructor`, so a global
	// instructor enrolled as a student in a colleague's course got Pools/Themen/Wissen there, and
	// the student view could not hide them. CourseDetail now hands down one flag.
	describe('course-level role and student view (GitHub #35)', () => {
		const studentTabs = ['materials', 'training', 'leitner', 'exam']

		it('a global instructor who is a student of this course gets the learner sub-tabs', () => {
			const instance = createInstance({ userRole: 'instructor', actsAsInstructor: false, course: {
				is_instructor: false, material_folder: '/Materials', mode_config: { training: true, leitner: true, exam: true }, enabled_tools: [],
			} })

			expect(instance.isInstructor).toBe(false)
			expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(studentTabs)
		})

		it('the student view shows an own course with the learner sub-tabs', () => {
			const instance = createInstance({ userRole: 'student', actsAsInstructor: false, course: {
				is_instructor: true, material_folder: '/Materials', mode_config: { training: true, leitner: true, exam: true }, enabled_tools: [],
			} })

			expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(studentTabs)
		})

		it('lists required content first once it is loaded and reports it to CourseDetail', async () => {
			const axios = (await import('@nextcloud/axios')).default
			axios.get.mockResolvedValueOnce({ data: { videos: [{ id: 3, title: 'Unterweisung', sort_order: 1 }] } })
			const instance = createInstance({ course: {
				is_instructor: false, material_folder: null, mode_config: { training: true, leitner: false, exam: false }, enabled_tools: [],
			} })

			await instance.fetchCourseVideos()

			expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['videos', 'training'])
			expect(instance.$emit).toHaveBeenCalledWith('videos-available', true)
		})

		// A deep link to Pflichtinhalte arrives before the list: the sub-tab falls back to the
		// default first and must resolve to the requested leaf once the list is known.
		it('resolves a requested Pflichtinhalte leaf once the required content is loaded', async () => {
			const axios = (await import('@nextcloud/axios')).default
			axios.get.mockResolvedValueOnce({ data: { videos: [{ id: 3, title: 'Unterweisung', sort_order: 1 }] } })
			const instance = createInstance({ activeTab: 'videos', course: {
				is_instructor: false, material_folder: null, mode_config: { training: true, leitner: false, exam: false }, enabled_tools: [],
			} })
			instance.syncFromActiveTab('videos')
			expect(instance.currentSubTab).toBe('training')

			await instance.fetchCourseVideos()

			expect(instance.currentSubTab).toBe('videos')
		})

		it('tells CourseDetail on unmount that its required-content list is gone', () => {
			const instance = createInstance()

			CourseTabLernraum.beforeUnmount.call(instance)

			expect(instance.$emit).toHaveBeenCalledWith('videos-available', false)
		})

		// Review: the watcher that follows the student view switched on or off while the course
		// stays open was untested.
		it('switching into the student view fetches the learner data and lands on a learner sub-tab', () => {
			const instance = createInstance({ activeTab: 'pools', actsAsInstructor: true, course: {
				is_instructor: true, material_folder: null, mode_config: { training: true, leitner: true, exam: true }, enabled_tools: [],
			} })
			instance.syncFromActiveTab('pools')
			expect(instance.currentSubTab).toBe('pools')
			instance.fetchQueueCount = vi.fn()
			instance.fetchCourseVideos = vi.fn()

			instance.actsAsInstructor = false
			CourseTabLernraum.watch.isInstructor.call(instance, false)

			expect(instance.fetchQueueCount).toHaveBeenCalledTimes(1)
			expect(instance.fetchCourseVideos).toHaveBeenCalledTimes(1)
			expect(instance.currentSubTab).toBe('training')
		})

		it('leaving the student view lands on an instructor sub-tab without learner fetches', () => {
			const instance = createInstance({ activeTab: 'training', actsAsInstructor: false, course: {
				is_instructor: true, material_folder: null, mode_config: { training: true, leitner: true, exam: true }, enabled_tools: [],
			} })
			instance.syncFromActiveTab('training')
			expect(instance.currentSubTab).toBe('training')
			instance.fetchQueueCount = vi.fn()
			instance.fetchCourseVideos = vi.fn()

			instance.actsAsInstructor = true
			CourseTabLernraum.watch.isInstructor.call(instance, true)

			expect(instance.fetchQueueCount).not.toHaveBeenCalled()
			expect(instance.fetchCourseVideos).not.toHaveBeenCalled()
			expect(instance.currentSubTab).toBe('pools')
		})
	})
})

describe('pool rules dialog: certificate hint (GitHub #35)', () => {
	const HINT = 'Only the "Required pools" selection under Administration → Course rules → Certification decides about the certificate. These switches do not change that.'
	const sfc = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')

	it('sits in the Pool Rules dialog, after the required and exam-relevant switches', () => {
		const dialog = sfc.slice(sfc.indexOf("t('learning', 'Pool Rules')"), sfc.indexOf("t('learning', 'Exam key filter')"))
		expect(dialog).toContain(HINT)
		expect(dialog.indexOf(HINT)).toBeGreaterThan(dialog.indexOf("'Show this pool as required'"))
		expect(dialog.indexOf(HINT)).toBeGreaterThan(dialog.indexOf("'Exam relevant (counts towards exam readiness)'"))
	})

	it.each(['de', 'en', 'fr', 'ru', 'ar', 'uk'])('is translated in %s and names that language\'s labels', (lang) => {
		const tr = JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
		expect(tr[HINT]).toBeTruthy()
		for (const label of ['Pflicht-Pools', 'Verwaltung', 'Kursregeln', 'Zertifizierung']) {
			expect(tr[HINT]).toContain(tr[label])
		}
	})
})

describe('CourseTabLernraum: saving the chapter selection (audit #179)', () => {
	it('emits an error when the save fails instead of stopping silently', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.put.mockRejectedValueOnce({ response: { status: 403 } })
		const instance = createInstance({ curriculumEnabled: true, selectedChapterKeys: ['k1'] })

		await instance.saveCurriculumScope()

		expect(instance.$emit).toHaveBeenCalledWith('error', 'Die Kapitelauswahl konnte nicht gespeichert werden.')
		expect(instance.curriculumSaved).toBe(false)
		expect(instance.savingCurriculum).toBe(false)
		expect(instance.selectedChapterKeys).toEqual(['k1'])
	})
})
