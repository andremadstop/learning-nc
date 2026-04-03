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

	it('has mode-config and exam-slot sub-tabs', () => {
		const instance = createInstance()

		expect(instance.visibleSubTabs.map((tab) => tab.id)).toEqual(['mode-config', 'exam-slot'])
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

		expect(instance.examDateLocal).toBe('2026-04-15')
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
			axios.patch.mockResolvedValue({ data: { exam_date: '2026-04-20' } })

			const instance = createInstance()
			instance.examDateLocal = '2026-04-20'

			await instance.saveExamDate()

			expect(axios.patch).toHaveBeenCalledWith('/apps/learning/api/courses/5/exam-date', {
				examDate: '2026-04-20',
			})
			expect(instance.examDateLocal).toBe('2026-04-20')
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
