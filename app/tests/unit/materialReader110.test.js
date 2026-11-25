// @vitest-environment jsdom
/**
 * GitHub #110: the reader and the two entry points (material list, required documents).
 * jsdom: the reader renders through DOMPurify (see materialMarkdown110.test.js).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, p) => url.replace('{courseId}', p?.courseId).replace('{documentId}', p?.documentId)) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str, translatePlural: (app, s) => s, getLanguage: () => 'en' }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({ default: { name: 'AccessibleDialog', template: '<div><slot /><slot name="actions" /></div>' } }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import MaterialReader from '../../src/components/MaterialReader.vue'
import CourseMaterials from '../../src/components/CourseMaterials.vue'
import { isPlainLeftClick } from '../../src/utils/plainClick.js'
import { MAX_RENDER_BYTES } from '../../src/utils/materialMarkdown.js'

function readerInstance(url = '/apps/learning/api/courses/7/documents/31/download') {
	const instance = { ...MaterialReader.data(), url, fileName: 'guide.md', $emit: vi.fn() }
	for (const [name, fn] of Object.entries(MaterialReader.methods)) {
		instance[name] = typeof fn === 'function' ? fn.bind(instance) : fn
	}
	return instance
}

function click(overrides = {}) {
	return { button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, defaultPrevented: false, preventDefault: vi.fn(), ...overrides }
}

describe('MaterialReader', () => {
	beforeEach(() => vi.clearAllMocks())

	it('loads the file as text from the download route and renders it', async () => {
		axios.get.mockResolvedValue({ data: '# Policy\n\n| a | b |\n|---|---|\n| 1 | 2 |' })
		const r = readerInstance()
		await r.load()
		const [url, options] = axios.get.mock.calls[0]
		expect(url).toBe('/apps/learning/api/courses/7/documents/31/download')
		expect(options.responseType).toBe('text')
		expect(options.transformResponse[0]('{"a":1}')).toBe('{"a":1}')
		expect(r.state).toBe('ready')
		expect(r.html).toContain('<h1>Policy</h1>')
		expect(r.html).toContain('<table>')
		expect(r.$emit).toHaveBeenCalledWith('loaded')
	})

	it('never renders raw HTML from the file', async () => {
		axios.get.mockResolvedValue({ data: '<img src=x onerror=alert(1)><script>alert(1)</script>' })
		const r = readerInstance()
		await r.load()
		expect(r.html).not.toMatch(/<img|<script/i)
	})

	it('offers a very large file as plain text instead of rendering it', async () => {
		axios.get.mockResolvedValue({ data: 'x'.repeat(MAX_RENDER_BYTES + 1) })
		const r = readerInstance()
		await r.load()
		expect(r.state).toBe('too_large')
		expect(r.html).toBe('')
		expect(r.$emit).toHaveBeenCalledWith('loaded')
	})

	it('shows an error and does not report an open when the request fails', async () => {
		axios.get.mockRejectedValue({ response: { status: 403, data: { error: 'Forbidden' } } })
		const r = readerInstance()
		await r.load()
		expect(r.state).toBe('error')
		expect(r.$emit).not.toHaveBeenCalledWith('loaded')
	})
})

describe('isPlainLeftClick', () => {
	it('is true only for an unmodified left click', () => {
		expect(isPlainLeftClick(click())).toBe(true)
		expect(isPlainLeftClick(click({ button: 1 }))).toBe(false)
		expect(isPlainLeftClick(click({ ctrlKey: true }))).toBe(false)
		expect(isPlainLeftClick(click({ metaKey: true }))).toBe(false)
		expect(isPlainLeftClick(click({ shiftKey: true }))).toBe(false)
		expect(isPlainLeftClick(click({ altKey: true }))).toBe(false)
		expect(isPlainLeftClick(click({ defaultPrevented: true }))).toBe(false)
	})
})

describe('CourseMaterials: Markdown opens in the reader', () => {
	function materials() {
		const instance = { ...CourseMaterials.data.call({ courseId: 7 }), courseId: 7 }
		for (const [name, fn] of Object.entries(CourseMaterials.methods)) {
			instance[name] = fn.bind(instance)
		}
		return instance
	}

	it('opens a Markdown material in the reader on a plain click', () => {
		const m = materials()
		const event = click()
		m.openMaterial(event, { id: 31, file_type: 'markdown', file_name: 'guide.md' })
		expect(event.preventDefault).toHaveBeenCalled()
		expect(m.readerDoc.id).toBe(31)
	})

	it('keeps the browser for a PDF and for a modified click', () => {
		const m = materials()
		const pdf = click()
		m.openMaterial(pdf, { id: 30, file_type: 'pdf', file_name: 'policy.pdf' })
		const ctrl = click({ ctrlKey: true })
		m.openMaterial(ctrl, { id: 31, file_type: 'markdown', file_name: 'guide.md' })
		expect(pdf.preventDefault).not.toHaveBeenCalled()
		expect(ctrl.preventDefault).not.toHaveBeenCalled()
		expect(m.readerDoc).toBeNull()
	})
})
