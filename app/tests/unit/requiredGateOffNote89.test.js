/**
 * GitHub #89: documents ticked "Required" hold the exam back only while "Exam only after all
 * required content is completed" is on. With the switch off the "Required" column says so and
 * links to the switch.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const { api } = vi.hoisted(() => ({ api: { documents: null, registry: [] } }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/9/material-folder') return Promise.resolve({ data: { folder: '/Kurs' } })
			if (url === '/apps/learning/api/courses/9/documents') return Promise.resolve({ data: api.documents })
			if (url === '/apps/learning/api/courses/9/videos') return Promise.resolve({ data: api.registry })
			return Promise.resolve({ data: {} })
		}),
		put: vi.fn(),
		post: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), url)),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, translatePlural: (_a, s, p, c) => (c === 1 ? s : p), getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))

import CourseMaterials from '../../src/components/CourseMaterials.vue'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const doc = (id, name) => ({ id, file_name: name, file_type: 'pdf', file_size: 10, created_at: 1700000000, downloadable: true })

describe('CourseMaterials — note when required marks do not lock the exam (GitHub #89)', () => {
	let host, app

	async function mount(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseMaterials, { courseId: 9, ...props }) })
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		api.documents = { documents: [doc(31, 'Policy.pdf'), doc(32, 'Handbook.pdf')], can_manage_files: true }
		api.registry = [{ id: 7, source_type: 'document', video_ref: '31', document_id: 31 }]
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		document.getElementById('learning-required-content-gate')?.remove()
	})

	it('names the switch when a document is required and the switch is off', async () => {
		await mount({ isInstructor: true, examGateOff: true })
		const note = host.querySelector('[data-test="required-gate-off"]')
		expect(note).not.toBeNull()
		expect(note.textContent).toContain('Prüfung erst nach Abschluss aller Pflichtinhalte')
	})

	it('says nothing while the switch is on', async () => {
		await mount({ isInstructor: true, examGateOff: false })
		expect(host.querySelector('[data-test="required-gate-off"]')).toBeNull()
	})

	it('says nothing while no document is required', async () => {
		api.registry = []
		await mount({ isInstructor: true, examGateOff: true })
		expect(host.querySelector('[data-test="required-gate-off"]')).toBeNull()
	})

	it('says nothing to learners (student view)', async () => {
		await mount({ isInstructor: false, examGateOff: true })
		expect(host.querySelector('[data-test="required-gate-off"]')).toBeNull()
	})

	it('the link moves the focus to the switch', async () => {
		const input = document.createElement('input')
		input.id = 'learning-required-content-gate'
		input.scrollIntoView = vi.fn()
		document.body.appendChild(input)
		await mount({ isInstructor: true, examGateOff: true })
		host.querySelector('[data-test="required-gate-off-link"]').click()
		expect(document.activeElement).toBe(input)
		expect(input.scrollIntoView).toHaveBeenCalled()
	})
})
