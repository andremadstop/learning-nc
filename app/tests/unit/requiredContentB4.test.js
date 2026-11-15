/**
 * GitHub #35 (Lückencheck B4): required content in the course administration, the learner view
 * and the coverage list. The server half (validation, gate scope) is in PHPUnit.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

const { api, user } = vi.hoisted(() => ({
	api: { videos: [], documents: [] },
	user: { uid: 'owner' },
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/5/videos') return Promise.resolve({ data: api.videos })
			if (url === '/apps/learning/api/courses/5/documents') return Promise.resolve({ data: { documents: api.documents } })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn((url, body) => Promise.resolve({ data: { id: 77, source_type: body.sourceType, video_ref: body.videoRef, title: body.title } })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => user }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" class="switch" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))

import axios from '@nextcloud/axios'
import RequiredContentSettings from '../../src/components/RequiredContentSettings.vue'
import {
	addableDocuments,
	documentIdFromRef,
	documentPayload,
	formatContentProgress,
	REQUIRED_CONTENT_SOURCE_TYPES,
	requiredDocumentUrl,
	videoFormComplete,
	videoPayload,
} from '../../src/utils/requiredContent.js'
import { requiredContentMessage } from '../../src/utils/modeGate.js'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('required content helpers', () => {
	it('accepts the same source types as the server', () => {
		const php = readFileSync(resolve(__dirname, '../../lib/Controller/CourseVideoController.php'), 'utf8')
		expect(php).toMatch(/SOURCE_TYPES = \[NcFilesVideoAdapter::SOURCE_TYPE, 'vimeo', 'youtube-nocookie', 'document'\]/)
		expect(REQUIRED_CONTENT_SOURCE_TYPES).toEqual(['nc-files', 'vimeo', 'youtube-nocookie', 'document'])
	})

	it('reads a document id only from a plain positive number', () => {
		expect(documentIdFromRef('31')).toBe(31)
		expect(documentIdFromRef(' 31 ')).toBe(31)
		expect(documentIdFromRef(31)).toBe(31)
		for (const ref of ['', '0', '-3', '31/../32', '/Kurs/a.pdf', '../x', 'abc', null, undefined]) {
			expect(documentIdFromRef(ref)).toBeNull()
		}
	})

	it('sends a material document by its id, named after the file without a title', () => {
		expect(documentPayload({ id: 31, file_name: 'policy.pdf' }, '', 2)).toEqual({
			sourceType: 'document', videoRef: '31', title: 'policy.pdf', durationSeconds: null, sortOrder: 2,
		})
		expect(documentPayload({ id: 31, file_name: 'policy.pdf' }, ' Richtlinie ').title).toBe('Richtlinie')
	})

	it('sends a video only with a reference and a positive length', () => {
		const form = { sourceType: 'youtube-nocookie', ref: ' dQw4w9WgXcQ ', title: 'Intro', minutes: '2', seconds: '5' }
		expect(videoFormComplete(form)).toBe(true)
		expect(videoPayload(form, 1)).toEqual({ sourceType: 'youtube-nocookie', videoRef: 'dQw4w9WgXcQ', title: 'Intro', durationSeconds: 125, sortOrder: 1 })
		expect(videoFormComplete({ ...form, minutes: '', seconds: '' })).toBe(false)
		expect(videoFormComplete({ ...form, ref: ' ' })).toBe(false)
		expect(videoFormComplete({ ...form, sourceType: 'document' })).toBe(false)
	})

	it('offers only servable material documents that are not required yet', () => {
		const docs = [
			{ id: 31, file_name: 'a.pdf', downloadable: true },
			{ id: 32, file_name: 'b.pdf', downloadable: true },
			{ id: 33, file_name: 'old.pdf', downloadable: false },
		]
		const items = [{ source_type: 'document', video_ref: '31' }, { source_type: 'vimeo', video_ref: '32' }]
		expect(addableDocuments(docs, items).map((d) => d.id)).toEqual([32])
	})

	it('links a required document through the material download, never a path', () => {
		expect(requiredDocumentUrl(5, 31)).toBe('/apps/learning/api/courses/5/documents/31/download')
		expect(requiredDocumentUrl(5, null)).toBeNull()
		expect(requiredDocumentUrl(5, 'Docs/handbook.pdf')).toBeNull()
	})

	it('shows confirmed/total in the coverage list, a dash without required content', () => {
		expect(formatContentProgress(1, 3)).toBe('1/3')
		expect(formatContentProgress(5, 3)).toBe('3/3')
		expect(formatContentProgress(0, 0)).toBe('—')
	})

	it('explains the exam lock in the reader\'s language instead of the server text', () => {
		const locked = { response: { status: 403, data: { gate: 'video', error: 'Required video/material not completed: content 5' } } }
		expect(requiredContentMessage(locked)).toContain('Pflichtinhalte')
		expect(requiredContentMessage({ response: { status: 403, data: { gate: 'mode' } } })).toBeNull()
		expect(requiredContentMessage({ response: { status: 400, data: {} } })).toBeNull()
	})
})

describe('RequiredContentSettings rendered', () => {
	let host, app

	async function mountSettings(course = {}) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(RequiredContentSettings, {
				courseId: 5,
				course: { instructor_id: 'owner', material_folder: '/Kurs', video_gate_enabled: false, ...course },
			}),
		})
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		vi.clearAllMocks()
		user.uid = 'owner'
		api.videos = [{ id: 9, source_type: 'document', video_ref: '31', title: '' }]
		api.documents = [
			{ id: 31, file_name: 'policy.pdf', downloadable: true },
			{ id: 32, file_name: 'rules.md', downloadable: true },
		]
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('lists the required content and offers only the remaining material files', async () => {
		await mountSettings()
		expect(host.querySelector('[data-test="required-content-list"]').textContent).toContain('policy.pdf')
		const options = [...host.querySelectorAll('[data-test="required-doc-select"] option')].map((o) => o.value)
		expect(options).toEqual(['', '32'])
	})

	it('adds a material document by its id', async () => {
		await mountSettings()
		const select = host.querySelector('[data-test="required-doc-select"]')
		select.value = '32'
		select.dispatchEvent(new Event('change'))
		await flush()
		host.querySelector('[data-test="required-doc-add"]').click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/5/videos', {
			sourceType: 'document', videoRef: '32', title: 'rules.md', durationSeconds: null, sortOrder: 1,
		})
		expect(host.querySelector('[data-test="required-content-list"]').textContent).toContain('rules.md')
	})

	it('switches the exam gate with the request body the controller reads', async () => {
		await mountSettings()
		const sw = host.querySelector('[data-test="required-content-gate"] .switch') || host.querySelector('.switch')
		sw.checked = true
		sw.dispatchEvent(new Event('change'))
		await flush()
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/5/video-gate', { enabled: true })
	})

	it('warns that the switch locks nothing while the list is empty', async () => {
		api.videos = []
		await mountSettings({ video_gate_enabled: true })
		expect(host.querySelector('[data-test="required-content-gate-empty"]')).not.toBeNull()
	})

	it('offers videos from the files to the course owner only', async () => {
		await mountSettings()
		const ownerTypes = [...host.querySelectorAll('[data-test="required-video-source"] option')].map((o) => o.value)
		expect(ownerTypes).toContain('nc-files')
		app.unmount()
		host.remove()

		user.uid = 'co-instructor'
		await mountSettings()
		const coTypes = [...host.querySelectorAll('[data-test="required-video-source"] option')].map((o) => o.value)
		expect(coTypes).toEqual(['youtube-nocookie', 'vimeo'])
	})

	it('removes a required content row', async () => {
		await mountSettings()
		const remove = [...host.querySelectorAll('[data-test="required-content-list"] button')][0]
		remove.click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith('/apps/learning/api/course-videos/9')
		expect(host.querySelector('[data-test="required-content-list"]')).toBeNull()
	})
})
