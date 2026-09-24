import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		patch: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import CourseTabVerwaltung from '../../src/components/CourseTabVerwaltung.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseTabVerwaltung.data === 'function' ? CourseTabVerwaltung.data() : {}
	const instance = {
		...data,
		courseId: 5,
		course: {
			is_instructor: true,
			mode_config: {
				training: true,
				leitner: true,
				exam: true,
			},
			leitner_sprint: false,
			talk_room_token: '',
			exam_date: null,
		},
		userRole: 'instructor',
		activeTab: 'mode-config',
		$emit: vi.fn(),
		$set: vi.fn((obj, key, value) => {
			obj[key] = value
		}),
		...overrides,
	}

	Object.defineProperties(instance, {
		isInstructor: { get: () => CourseTabVerwaltung.computed.isInstructor.call(instance) },
		visibleSubTabs: { get: () => CourseTabVerwaltung.computed.visibleSubTabs.call(instance) },
		modeConfigKeys: { get: () => CourseTabVerwaltung.computed.modeConfigKeys.call(instance) },
		toolConfigKeys: { get: () => CourseTabVerwaltung.computed.toolConfigKeys.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseTabVerwaltung.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseTabVerwaltung', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('has mode-config, schedule and exam-slot sub-tabs', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['mode-config', 'schedule', 'exam-slot'])
	})

	it('modeConfigKeys returns expected mode keys', () => {
		const instance = createInstance()

		const keys = instance.modeConfigKeys.map((m) => m.key)
		expect(keys).toContain('training')
		expect(keys).toContain('leitner')
		expect(keys).toContain('exam')
		expect(keys).toContain('duel')
		expect(keys).toContain('abenteuer')
		expect(keys).not.toContain('swipe')
		expect(keys).not.toContain('oldschool')
		expect(keys.length).toBe(8)
	})

	it('emits tab-change when selecting exam-slot pill', () => {
		const instance = createInstance({
			currentSubTab: 'mode-config',
		})

		instance.selectSubTab('exam-slot')

		expect(instance.$emit).toHaveBeenCalledWith('tab-change', 'exam-slot')
		expect(instance.currentSubTab).toBe('exam-slot')
	})

	it('normalizeModeConfig fills defaults', () => {
		const instance = createInstance()

		const result = instance.normalizeModeConfig({})
		expect(result.training).toBe(true)
		expect(result.abenteuer).toBe(false)
		expect(result.course_summary).toBe(false)
	})

	it('syncs exam_date from the course prop', () => {
		const instance = createInstance({
			course: {
				is_instructor: true,
				mode_config: { training: true, leitner: true, exam: true },
				leitner_sprint: false,
				talk_room_token: '',
				exam_date: '2026-04-15',
			},
		})

		CourseTabVerwaltung.watch.course.handler.call(instance, instance.course)

		expect(instance.examDateLocal).toBe('2026-04-15T09:00')
	})

	it('component has correct name', () => {
		expect(CourseTabVerwaltung.name).toBe('CourseTabVerwaltung')
	})

	describe('training mode_config toggle (UX-05)', () => {
		it('Test E: training modeConfigKeys entry label reads "Training" (not "Training (immer aktiv)")', () => {
			const instance = createInstance()

			const trainingEntry = instance.modeConfigKeys.find((m) => m.key === 'training')
			expect(trainingEntry).toBeDefined()
			expect(trainingEntry.label).toBe('Training')
			expect(trainingEntry.label).not.toContain('immer aktiv')
		})

		it('Test F: training entry in modeConfigKeys has no disabled property', () => {
			const instance = createInstance()

			const trainingEntry = instance.modeConfigKeys.find((m) => m.key === 'training')
			expect(trainingEntry).toBeDefined()
			expect(trainingEntry.disabled).toBeUndefined()
		})

		it('Test G: saveModeConfig with training=false sends PUT request with modeConfig.training === false', async () => {
			const axios = (await import('@nextcloud/axios')).default
			axios.put.mockResolvedValue({ data: { mode_config: { training: false } } })

			const instance = createInstance({
				course: {
					is_instructor: true,
					mode_config: { training: false, leitner: true, exam: true },
					leitner_sprint: false,
					talk_room_token: '',
				},
			})
			instance.modeConfigLocal = { training: false, leitner: true, exam: true }

			await instance.saveModeConfig()

			expect(axios.put).toHaveBeenCalled()
			const payload = axios.put.mock.calls[0][1]
			expect(payload.modeConfig.training).toBe(false)
		})

		it('saves exam_date via PATCH and refreshes the course detail', async () => {
			const axios = (await import('@nextcloud/axios')).default
			axios.patch.mockResolvedValue({ data: { exam_date: '2026-04-20T10:00' } })

			const instance = createInstance()
			instance.examDateLocal = '2026-04-20T10:00'

			await instance.saveExamDate()

			expect(axios.patch).toHaveBeenCalledWith('/apps/learning/api/courses/5/exam-date', {
				examDate: '2026-04-20T10:00',
			})
			expect(instance.examDateLocal).toBe('2026-04-20T10:00')
			expect(instance.$emit).toHaveBeenCalledWith('refresh-course-detail')
		})

		it('clears exam_date via PATCH with null payload', async () => {
			const axios = (await import('@nextcloud/axios')).default
			axios.patch.mockResolvedValue({ data: { exam_date: null } })

			const instance = createInstance()
			instance.examDateLocal = '2026-04-20'

			await instance.clearExamDate()

			expect(axios.patch).toHaveBeenCalledWith('/apps/learning/api/courses/5/exam-date', {
				examDate: null,
			})
			expect(instance.examDateLocal).toBe('')
		})
	})
})

/**
 * Codeberg #9 — the practice exam config. The request body is the contract: a key the
 * controller does not read answers 200 and silently saves nothing
 * ([[feedback_request_payload_contracts]]).
 */
describe('CourseTabVerwaltung practice exam config', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('PATCHes exactly the five keys CourseController::updatePracticeConfig reads', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.patch.mockResolvedValue({ data: { practice_enabled: true, practice_questions: 30, practice_minutes: 0, practice_pass_percent: 70, practice_required_only: true } })
		const instance = createInstance()
		instance.practiceEnabled = true
		instance.practiceQuestions = 30
		instance.practiceMinutes = 0
		instance.practicePassPercent = 70
		instance.practiceRequiredOnly = true

		await instance.savePracticeConfig()

		expect(axios.patch).toHaveBeenCalledWith('/apps/learning/api/courses/5/practice-exam-config', {
			practiceEnabled: true,
			practiceQuestions: 30,
			practiceMinutes: 0,
			practicePassPercent: 70,
			practiceRequiredOnly: true,
		})
		expect(instance.practiceRequiredOnly).toBe(true)
		expect(instance.practiceSaved).toBe(true)
		expect(instance.$emit).toHaveBeenCalledWith('refresh-course-detail')
	})

	// Codeberg #7: the learning space renders from the course detail, so a saved tool selection
	// has to trigger a refresh — otherwise it showed the old tools until a page reload.
	it('refreshes the course detail after saving the course tool selection', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.put.mockResolvedValue({ data: { enabled_tools: ['dns'] } })
		const instance = createInstance()
		instance.adminEnabledTools = ['subnet', 'dns']
		instance.toolConfigLocal = { subnet: false, dns: true }
		await instance.saveToolConfig()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/5/tools', { enabledTools: ['dns'] })
		expect(instance.$emit).toHaveBeenCalledWith('refresh-course-detail')
	})

	// The instructor must see that "required pools only" with no required pool locks learners out.
	it('knows whether any course pool is marked required (serialised as 0/1)', () => {
		const none = createInstance({ coursePools: [{ pool_id: 1, required: 0 }, { pool_id: 2, required: 0 }] })
		expect(CourseTabVerwaltung.computed.hasRequiredPools.call(none)).toBe(false)
		const some = createInstance({ coursePools: [{ pool_id: 1, required: 0 }, { pool_id: 2, required: 1 }] })
		expect(CourseTabVerwaltung.computed.hasRequiredPools.call(some)).toBe(true)
	})

	it('loads the practice config from the course prop', () => {
		const instance = createInstance()
		CourseTabVerwaltung.watch.course.handler.call(instance, {
			...instance.course,
			practice_enabled: true,
			practice_questions: 12,
			practice_minutes: 45,
			practice_pass_percent: 90,
			practice_required_only: true,
		})
		expect(instance.practiceEnabled).toBe(true)
		expect(instance.practiceRequiredOnly).toBe(true)
		expect(instance.practiceQuestions).toBe(12)
		expect(instance.practiceMinutes).toBe(45)
		expect(instance.practicePassPercent).toBe(90)
	})
})
