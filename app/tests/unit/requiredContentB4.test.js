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
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import RequiredContentSettings from '../../src/components/RequiredContentSettings.vue'
import {
	addableDocuments,
	documentIdFromRef,
	documentPayload,
	EMBED_ID_PATTERNS,
	formatContentProgress,
	providerVideoId,
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

	it('uses the same embed id formats as the server', () => {
		const php = readFileSync(resolve(__dirname, '../../lib/Controller/CourseVideoController.php'), 'utf8')
		expect(php).toContain("'youtube-nocookie' => '/^[A-Za-z0-9_-]{11}$/D'")
		expect(php).toContain("'vimeo' => '/^\\\\d{1,12}$/D'")
		expect(EMBED_ID_PATTERNS['youtube-nocookie'].source).toBe('^[A-Za-z0-9_-]{11}$')
		expect(EMBED_ID_PATTERNS.vimeo.source).toBe('^\\d{1,12}$')
	})

	it('takes the provider id out of a pasted address', () => {
		for (const ref of [
			'dQw4w9WgXcQ',
			' dQw4w9WgXcQ ',
			'https://youtu.be/dQw4w9WgXcQ',
			'https://youtu.be/dQw4w9WgXcQ?t=42',
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL1',
			'youtube.com/watch?v=dQw4w9WgXcQ',
			'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
			'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0',
			'https://www.youtube.com/shorts/dQw4w9WgXcQ',
		]) {
			expect(providerVideoId('youtube-nocookie', ref)).toBe('dQw4w9WgXcQ')
		}
		for (const ref of ['76979871', 'https://vimeo.com/76979871', 'vimeo.com/channels/staffpicks/76979871', 'https://player.vimeo.com/video/76979871?h=abc']) {
			expect(providerVideoId('vimeo', ref)).toBe('76979871')
		}
	})

	it('finds no id in anything else, and leaves other source types alone', () => {
		for (const ref of ['', 'https://example.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXc', 'dQw4w9WgXcQ/x', 'https://youtu.be/', 'https://www.youtube.com/watch?v=short']) {
			expect(providerVideoId('youtube-nocookie', ref)).toBeNull()
		}
		for (const ref of ['abc', 'https://vimeo.com/', 'https://example.com/76979871', '1234567890123']) {
			expect(providerVideoId('vimeo', ref)).toBeNull()
		}
		expect(providerVideoId('nc-files', ' /Videos/intro.mp4 ')).toBe('/Videos/intro.mp4')
	})

	it('sends the id, not the pasted address, and only a form with a valid id', () => {
		const form = { sourceType: 'youtube-nocookie', ref: 'https://youtu.be/dQw4w9WgXcQ', title: '', minutes: '5', seconds: '' }
		expect(videoFormComplete(form)).toBe(true)
		expect(videoPayload(form).videoRef).toBe('dQw4w9WgXcQ')
		expect(videoFormComplete({ ...form, ref: 'https://example.com/x' })).toBe(false)
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

	it('flags a document row from before 5.13 that names a URL instead of a material file', async () => {
		// Up to 5.12.0 the API stored any reference; such a row gets no link in the Lernraum now,
		// so the administration must say that it has to be chosen again from the material folder.
		api.videos = [
			{ id: 9, source_type: 'document', video_ref: '31', title: '' },
			{ id: 10, source_type: 'document', video_ref: 'https://intranet/policy.pdf', title: 'Intranet-Richtlinie' },
		]
		await mountSettings()
		const rows = [...host.querySelectorAll('.required-content-item')]
		expect(rows).toHaveLength(2)
		expect(rows[0].querySelector('.required-content-warning')).toBeNull()
		expect(rows[1].querySelector('.required-content-warning')?.textContent).toContain('Nicht im Materialordner')
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

	/**
	 * A fake of the @nextcloud/dialogs 8 picker: like the real dialog it hands back a choice only
	 * through a button callback. Without a button factory there is no button, closing picks nothing
	 * and pickNodes() rejects (FilePickerClosed).
	 */
	function fakePicker(node) {
		const state = { factory: null }
		const builder = {
			setMultiSelect: () => builder,
			setMimeTypeFilter: () => builder,
			setButtonFactory: (factory) => {
				state.factory = factory
				return builder
			},
			build: () => ({
				pickNodes: async () => {
					const buttons = state.factory ? state.factory([node]) : []
					const button = buttons.find((b) => !b.disabled)
					if (!button) {
						throw new Error('FilePicker: No nodes selected')
					}
					await button.callback([node])
					return [node]
				},
				pick: async () => {
					throw new Error('FilePicker: No nodes selected')
				},
			}),
		}
		getFilePickerBuilder.mockImplementation(() => builder)
		return state
	}

	it('takes the video the owner picks in the file picker and sends it', async () => {
		const picker = fakePicker({ path: '/Videos/intro.mp4' })
		await mountSettings()
		const source = host.querySelector('[data-test="required-video-source"]')
		expect(source.value).toBe('nc-files')
		const choose = [...host.querySelectorAll('button')].find((b) => b.textContent.includes('Video auswählen'))
		choose.click()
		await flush()
		expect(picker.factory).not.toBeNull()
		expect(host.querySelector('.required-content-picked').textContent).toContain('/Videos/intro.mp4')

		const minutes = host.querySelector('[data-test="required-video-minutes"]')
		minutes.value = '5'
		minutes.dispatchEvent(new Event('input'))
		await flush()
		const add = host.querySelector('[data-test="required-video-add"]')
		expect(add.disabled).toBe(false)
		add.click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/5/videos', {
			sourceType: 'nc-files', videoRef: '/Videos/intro.mp4', title: '', durationSeconds: 300, sortOrder: 1,
		})
	})

	it('keeps the form empty when the picker is closed without a choice', async () => {
		fakePicker({ path: '' })
		await mountSettings()
		const choose = [...host.querySelectorAll('button')].find((b) => b.textContent.includes('Video auswählen'))
		choose.click()
		await flush()
		expect(host.querySelector('.required-content-picked').textContent).toContain('Kein Video gewählt')
		expect(host.querySelector('[data-test="required-video-add"]').disabled).toBe(true)
	})

	it('warns about an embed reference without a valid id and keeps the add button off', async () => {
		await mountSettings()
		const source = host.querySelector('[data-test="required-video-source"]')
		source.value = 'youtube-nocookie'
		source.dispatchEvent(new Event('change'))
		await flush()
		const id = host.querySelector('[data-test="required-video-id"]')
		const minutes = host.querySelector('[data-test="required-video-minutes"]')
		minutes.value = '5'
		minutes.dispatchEvent(new Event('input'))
		id.value = 'https://example.com/video'
		id.dispatchEvent(new Event('input'))
		await flush()
		expect(host.querySelector('[data-test="required-video-id-invalid"]')).not.toBeNull()
		expect(host.querySelector('[data-test="required-video-add"]').disabled).toBe(true)

		id.value = 'https://youtu.be/dQw4w9WgXcQ'
		id.dispatchEvent(new Event('input'))
		await flush()
		expect(host.querySelector('[data-test="required-video-id-invalid"]')).toBeNull()
		host.querySelector('[data-test="required-video-add"]').click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/5/videos', expect.objectContaining({
			sourceType: 'youtube-nocookie', videoRef: 'dQw4w9WgXcQ', durationSeconds: 300,
		}))
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
