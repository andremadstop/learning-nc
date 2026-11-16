/**
 * GitHub #72 (comment 07:42): the error at the top of the course page stayed after a new attempt.
 * Course rules report a failed save (or a refused value) as "error" to the page; a new attempt
 * now first reports '' so a corrected retry does not leave "Failed to save" above "Saved.".
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn((url, body) => Promise.resolve({ data: { mode_config: body?.modeConfig } })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('../../src/components/CertLayoutSettings.vue', () => ({ default: { name: 'CertLayoutSettings', template: '<div />' } }))

import axios from '@nextcloud/axios'
import CourseTabVerwaltung from '../../src/components/CourseTabVerwaltung.vue'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('course rules: a new attempt clears the page error (GitHub #72)', () => {
	let host, app, errors

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.clearAllMocks()
	})

	async function mountTab() {
		errors = []
		host = document.createElement('div')
		document.body.appendChild(host)
		const tabRef = ref(null)
		const course = { id: 5, is_instructor: true, mode_config: { training: true, exam: true }, leitner_sprint: false, exam_date: null }
		app = createApp({
			render: () => h(CourseTabVerwaltung, {
				ref: tabRef,
				courseId: 5,
				course,
				coursePools: [],
				userRole: 'instructor',
				actsAsInstructor: true,
				activeTab: 'mode-config',
				onError: (m) => errors.push(m),
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		return tabRef.value
	}

	it('a failed save, then a successful one: the page error is cleared', async () => {
		const tab = await mountTab()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		axios.put.mockRejectedValueOnce(new Error('500'))
		await tab.saveModeConfig()
		expect(errors.at(-1)).toBe('Failed to save mode config')

		await tab.saveModeConfig()
		expect(errors.at(-1)).toBe('')
	})

	it('a refused value, then the corrected one: the validation message is cleared too', async () => {
		const tab = await mountTab()
		tab.examScopeQuestions = '9999'
		await tab.saveCertConfig()
		expect(errors.at(-1)).toBe('Fragen pro Prüfung: eine ganze Zahl von 1 bis 500.')

		tab.examScopeQuestions = '20'
		await tab.saveCertConfig()
		expect(errors).not.toContain('Failed to save cert config')
		expect(errors.at(-1)).toBe('')
	})
})
