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
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

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
		expect(keys.length).toBe(10)
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

	it('component has correct name', () => {
		expect(CourseTabVerwaltung.name).toBe('CourseTabVerwaltung')
	})
})
