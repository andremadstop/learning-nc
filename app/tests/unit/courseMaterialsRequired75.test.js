/**
 * GitHub #75: required content in the learning materials.
 *
 * - instructors tick "Required" per file or for all files in the header (no dropdown per file);
 * - a ticked file can be confirmed again by everyone ("Ask everyone to confirm again");
 * - learners see optional files with an "Optional" hint, the required ones are left out there;
 * - the mandatory-training privacy notice folds to one line once it has been seen;
 * - the certificate validity in days, months or years, a year as calendar year.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const { api } = vi.hoisted(() => ({
	api: { documents: null, registry: [], puts: [], posts: [], putFails: false, putHold: null },
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/9/material-folder') return Promise.resolve({ data: { folder: '/Kurs' } })
			if (url === '/apps/learning/api/courses/9/documents') return Promise.resolve({ data: api.documents })
			if (url === '/apps/learning/api/courses/9/videos') return Promise.resolve({ data: api.registry })
			return Promise.resolve({ data: {} })
		}),
		put: vi.fn(async (url, body) => {
			api.puts.push({ url, body })
			if (api.putHold) await api.putHold
			if (api.putFails) throw new Error('500')
			const ids = new Set(body.documentIds)
			const kept = api.registry.filter((row) => !ids.has(Number(row.document_id)))
			const added = body.required ? body.documentIds.map((id, i) => ({ id: 100 + i, source_type: 'document', video_ref: String(id), document_id: id })) : []
			api.registry = [...kept, ...added]
			return Promise.resolve({ data: api.registry })
		}),
		post: vi.fn((url, body) => {
			api.posts.push({ url, body })
			return Promise.resolve({ data: { confirm_after: 1700000000 } })
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), url)),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, v), text), translatePlural: (_a, s, p, c) => (c === 1 ? s : p), getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'anna' }) }))

import CourseMaterials from '../../src/components/CourseMaterials.vue'
import TrainingPrivacyNotice from '../../src/components/TrainingPrivacyNotice.vue'
import { addValidity, validityFromCourse, validityPayload } from '../../src/utils/certValidity.js'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const doc = (id, name, downloadable = true) => ({ id, file_name: name, file_type: 'pdf', file_size: 10, created_at: 1700000000, downloadable })

describe('CourseMaterials — the "Required" column (GitHub #75)', () => {
	let host, app

	async function mount(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseMaterials, { courseId: 9, ...props }) })
		app.config.globalProperties.t = (_a, s) => s
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		api.documents = { documents: [doc(31, 'Chapter 1.pdf'), doc(32, 'Chapter 2.pdf'), doc(33, 'Old.pdf', false)], can_manage_files: true }
		api.registry = [{ id: 7, source_type: 'document', video_ref: '31', document_id: 31 }]
		api.puts = []
		api.posts = []
		api.putFails = false
		api.putHold = null
		vi.clearAllMocks()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('shows a ticked box for each required file and a header box that is half ticked', async () => {
		await mount({ isInstructor: true })
		expect(host.querySelector('[data-test="required-doc-31"]').checked).toBe(true)
		expect(host.querySelector('[data-test="required-doc-32"]').checked).toBe(false)
		// a file members cannot open cannot be made required
		expect(host.querySelector('[data-test="required-doc-33"]').disabled).toBe(true)
		const header = host.querySelector('[data-test="required-all"]')
		expect(header.checked).toBe(false)
		expect(header.indeterminate).toBe(true)
	})

	it('ticks one file with one request, explicitly required', async () => {
		await mount({ isInstructor: true })
		const box = host.querySelector('[data-test="required-doc-32"]')
		box.checked = true
		box.dispatchEvent(new Event('change'))
		await flush()
		expect(api.puts).toEqual([{ url: '/apps/learning/api/courses/9/required-documents', body: { documentIds: [32], required: true } }])
		expect(host.querySelector('[data-test="required-all"]').checked).toBe(true)
	})

	it('the header box marks every openable file at once and unmarks all again', async () => {
		await mount({ isInstructor: true })
		const header = host.querySelector('[data-test="required-all"]')
		header.checked = true
		header.dispatchEvent(new Event('change'))
		await flush()
		expect(api.puts[0].body).toEqual({ documentIds: [31, 32], required: true })

		vi.stubGlobal('confirm', vi.fn(() => true))
		const again = host.querySelector('[data-test="required-all"]')
		again.checked = false
		again.dispatchEvent(new Event('change'))
		await flush()
		expect(api.puts[1].body).toEqual({ documentIds: [31, 32], required: false })
		vi.unstubAllGlobals()
	})

	// GitHub #75 (review): unticking ends the count of the confirmations — asked first.
	it('asks before a file becomes optional and changes nothing when cancelled', async () => {
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		const box = host.querySelector('[data-test="required-doc-31"]')
		box.checked = false
		box.dispatchEvent(new Event('change'))
		await flush()
		expect(confirm).toHaveBeenCalledTimes(1)
		expect(confirm.mock.calls[0][0]).toContain('Chapter 1.pdf')
		expect(api.puts).toEqual([])
		expect(host.querySelector('[data-test="required-doc-31"]').checked).toBe(true)
		vi.unstubAllGlobals()
	})

	it('the header box asks once for all files and keeps them required when cancelled', async () => {
		api.registry = [
			{ id: 7, source_type: 'document', video_ref: '31', document_id: 31 },
			{ id: 8, source_type: 'document', video_ref: '32', document_id: 32 },
		]
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		const header = host.querySelector('[data-test="required-all"]')
		expect(header.checked).toBe(true)
		header.checked = false
		header.dispatchEvent(new Event('change'))
		await flush()
		expect(confirm).toHaveBeenCalledTimes(1)
		expect(api.puts).toEqual([])
		expect(host.querySelector('[data-test="required-all"]').checked).toBe(true)

		confirm.mockReturnValue(true)
		header.checked = false
		header.dispatchEvent(new Event('change'))
		await flush()
		expect(api.puts).toEqual([{ url: '/apps/learning/api/courses/9/required-documents', body: { documentIds: [31, 32], required: false } }])
		vi.unstubAllGlobals()
	})

	it('ticking a file needs no confirmation', async () => {
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		const box = host.querySelector('[data-test="required-doc-32"]')
		box.checked = true
		box.dispatchEvent(new Event('change'))
		await flush()
		expect(confirm).not.toHaveBeenCalled()
		expect(api.puts).toHaveLength(1)
		vi.unstubAllGlobals()
	})

	it('asks everyone to confirm a required file again after a confirmation', async () => {
		const confirm = vi.fn(() => true)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		expect(host.querySelector('[data-test="reconfirm-doc-32"]')).toBeNull()
		host.querySelector('[data-test="reconfirm-doc-31"]').click()
		await flush()
		expect(confirm).toHaveBeenCalled()
		expect(api.posts).toEqual([{ url: '/apps/learning/api/course-videos/7/reconfirm', body: undefined }])
		vi.unstubAllGlobals()
	})

	// GitHub #75 (review): nothing stored, so the box must not show the click.
	it('a failed save shows the stored state again and announces the error each time', async () => {
		api.putFails = true
		const errors = []
		await mount({ isInstructor: true, onError: (message) => errors.push(message) })
		for (let attempt = 0; attempt < 2; attempt++) {
			const box = host.querySelector('[data-test="required-doc-32"]')
			box.checked = true
			box.dispatchEvent(new Event('change'))
			await flush()
			expect(host.querySelector('[data-test="required-doc-32"]').checked).toBe(false)
			expect(host.querySelector('[data-test="required-all"]').indeterminate).toBe(true)
		}
		// cleared before each attempt: a repeated identical message is a new announcement
		expect(errors).toEqual(['', 'Die Pflicht-Auswahl konnte nicht gespeichert werden.', '', 'Die Pflicht-Auswahl konnte nicht gespeichert werden.'])
	})

	it('a failed save of the header box unticks it again', async () => {
		api.putFails = true
		await mount({ isInstructor: true })
		const header = host.querySelector('[data-test="required-all"]')
		header.checked = true
		header.dispatchEvent(new Event('change'))
		await flush()
		expect(header.checked).toBe(false)
		expect(header.indeterminate).toBe(true)
	})

	// GitHub #75 (review): a control that turns disabled drops the keyboard focus to the page.
	it('keeps the boxes enabled while saving and ignores a click meanwhile', async () => {
		let release
		api.putHold = new Promise((resolve) => { release = resolve })
		await mount({ isInstructor: true })
		const box = host.querySelector('[data-test="required-doc-32"]')
		box.focus()
		box.checked = true
		box.dispatchEvent(new Event('change'))
		await nextTick()
		for (const selector of ['[data-test="required-doc-32"]', '[data-test="required-all"]', '[data-test="reconfirm-doc-31"]']) {
			const control = host.querySelector(selector)
			expect(control.disabled, selector).toBe(false)
			expect(control.getAttribute('aria-disabled'), selector).toBe('true')
		}
		expect(document.activeElement).toBe(box)
		const other = host.querySelector('[data-test="required-doc-31"]')
		other.checked = false
		other.dispatchEvent(new Event('change'))
		await nextTick()
		expect(other.checked).toBe(true)
		release()
		await flush()
		expect(api.puts).toHaveLength(1)
		expect(host.querySelector('[data-test="required-doc-32"]').getAttribute('aria-disabled')).toBeNull()
	})

	// GitHub #75 (review): WCAG 2.5.3 and one name per button.
	it('names the header box with its visible word and each "ask again" button with its file', async () => {
		await mount({ isInstructor: true })
		expect(host.querySelector('[data-test="required-all"]').getAttribute('aria-label')).toBe('Pflicht: alle Dateien')
		expect(host.querySelector('[data-test="reconfirm-doc-31"]').getAttribute('aria-label')).toBe('„Chapter 1.pdf“: alle erneut bestätigen lassen')
	})

	it('learners get no column; with required content the optional files carry a hint', async () => {
		await mount({ isInstructor: false, excludeDocumentIds: [31], optionalHint: true, hideEmpty: true })
		expect(host.querySelector('[data-test="required-all"]')).toBeNull()
		const names = [...host.querySelectorAll('.cell-filename')].map((cell) => cell.textContent)
		expect(names.some((text) => text.includes('Chapter 1.pdf'))).toBe(false)
		expect(names.every((text) => text.includes('Optional'))).toBe(true)
	})

	it('an IT course without required content looks as before: no hint', async () => {
		await mount({ isInstructor: false })
		expect(host.querySelector('.material-optional-hint')).toBeNull()
		expect(host.querySelectorAll('.cell-filename')).toHaveLength(3)
	})
})

describe('TrainingPrivacyNotice — folded after the first view (GitHub #75)', () => {
	let host, app

	function mount(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(TrainingPrivacyNotice, props) })
		app.config.globalProperties.t = (_a, s) => s
		app.mount(host)
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
		window.localStorage.clear()
	})

	// A stand-in for IntersectionObserver that the test drives.
	function stubObserver() {
		const observers = []
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback, options) {
				this.callback = callback
				this.options = options
				this.disconnected = false
				observers.push(this)
			}

			observe(el) {
				this.el = el
			}

			disconnect() {
				this.disconnected = true
			}
		})
		return observers
	}

	it('is shown in full until half of it was on screen, and as one line with "Details anzeigen" afterwards', async () => {
		const observers = stubObserver()
		mount({ collapsible: true })
		expect(host.querySelector('#training-privacy-details').style.display).not.toBe('none')
		expect(observers[0].options.threshold).toBe(0.5)
		observers[0].callback([{ isIntersecting: true, intersectionRatio: 0.6 }])
		expect(observers[0].disconnected).toBe(true)
		app.unmount()
		host.remove()

		mount({ collapsible: true })
		const toggle = host.querySelector('[data-test="training-privacy-toggle"]')
		expect(toggle.getAttribute('aria-expanded')).toBe('false')
		expect(host.querySelector('#training-privacy-details').style.display).toBe('none')
		toggle.click()
		await nextTick()
		expect(host.querySelector('#training-privacy-details').style.display).not.toBe('none')
		vi.unstubAllGlobals()
	})

	// GitHub #75 (review): learners find the notice last, below the materials. Marking it on
	// mount folded it for good for someone who never scrolled down to it (DSGVO Art. 13).
	it('a notice that never came into view stays expanded next time', () => {
		const observers = stubObserver()
		mount({ collapsible: true })
		observers[0].callback([{ isIntersecting: true, intersectionRatio: 0.1 }])
		app.unmount()
		host.remove()

		mount({ collapsible: true })
		expect(host.querySelector('#training-privacy-details').style.display).not.toBe('none')
		vi.unstubAllGlobals()
	})

	it('a learner who opens or closes the details has seen it', async () => {
		stubObserver()
		mount({ collapsible: true })
		host.querySelector('[data-test="training-privacy-toggle"]').click()
		await nextTick()
		app.unmount()
		host.remove()

		mount({ collapsible: true })
		expect(host.querySelector('[data-test="training-privacy-toggle"]').getAttribute('aria-expanded')).toBe('false')
		vi.unstubAllGlobals()
	})

	it('stays expanded when the browser keeps no storage', () => {
		const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
		mount({ collapsible: true })
		expect(host.querySelector('#training-privacy-details').style.display).not.toBe('none')
		spy.mockRestore()
	})

	it('without collapsible it has no toggle (other callers unchanged)', () => {
		mount({})
		expect(host.querySelector('[data-test="training-privacy-toggle"]')).toBeNull()
	})
})

describe('certificate validity in days, months or years (GitHub #75)', () => {
	it('reads a course: whole years of months as years, days when no months are set', () => {
		expect(validityFromCourse(365, null)).toEqual({ amount: 365, unit: 'days' })
		expect(validityFromCourse(0, 12)).toEqual({ amount: 1, unit: 'years' })
		expect(validityFromCourse(0, 18)).toEqual({ amount: 18, unit: 'months' })
		expect(validityFromCourse(90, 0)).toEqual({ amount: 0, unit: 'months' })
	})

	it('sends years as months and days with the unit that clears the months', () => {
		expect(validityPayload({ amount: 2, unit: 'years' })).toEqual({ certValidityMonths: 24, certValidityUnit: 'months' })
		expect(validityPayload({ amount: 6, unit: 'months' })).toEqual({ certValidityMonths: 6, certValidityUnit: 'months' })
		expect(validityPayload({ amount: 365, unit: 'days' })).toEqual({ certValidityDays: 365, certValidityUnit: 'days' })
	})

	it('the course settings send the validity fields the controller reads', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.patch = vi.fn(() => Promise.resolve({ data: { certValidityDays: 0, certValidityMonths: 24 } }))
		const { default: CourseTabVerwaltung, certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		const ctx = {
			courseId: 7,
			certEnabled: true,
			certPassPercent: 80,
			certRequiredPoolIds: [],
			certValidity: { amount: 2, unit: 'years' },
			examScopeQuestions: '',
			examScopeMinutes: '',
			certLayout: certLayoutFromServer({}),
			$emit: vi.fn(),
			parseExamScopeDefault: CourseTabVerwaltung.methods.parseExamScopeDefault,
		}
		await CourseTabVerwaltung.methods.saveCertConfig.call(ctx)
		const [url, body] = axios.patch.mock.calls.at(-1)
		expect(url).toBe('/apps/learning/api/courses/7/cert-config')
		expect(body.certValidityMonths).toBe(24)
		expect(body.certValidityUnit).toBe('months')
		expect(body).not.toHaveProperty('certValidityDays')
		expect(ctx.certValidity).toEqual({ amount: 2, unit: 'years' })

		ctx.certValidity = { amount: 90, unit: 'days' }
		axios.patch.mockResolvedValueOnce({ data: { certValidityDays: 90, certValidityMonths: null } })
		await CourseTabVerwaltung.methods.saveCertConfig.call(ctx)
		const [, daysBody] = axios.patch.mock.calls.at(-1)
		expect(daysBody).toMatchObject({ certValidityDays: 90, certValidityUnit: 'days' })
		expect(ctx.certValidity).toEqual({ amount: 90, unit: 'days' })
	})

	it('a year ends on the same date next year; 29 February falls back to 28 February', () => {
		const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
		expect(iso(addValidity(new Date(2026, 2, 16), { amount: 1, unit: 'years' }))).toBe('2027-03-16')
		expect(iso(addValidity(new Date(2027, 2, 16), { amount: 1, unit: 'years' }))).toBe('2028-03-16')
		expect(iso(addValidity(new Date(2028, 1, 29), { amount: 1, unit: 'years' }))).toBe('2029-02-28')
		expect(iso(addValidity(new Date(2026, 0, 31), { amount: 1, unit: 'months' }))).toBe('2026-02-28')
		expect(iso(addValidity(new Date(2027, 2, 16), { amount: 365, unit: 'days' }))).toBe('2028-03-15')
		expect(addValidity(new Date(), { amount: 0, unit: 'years' })).toBeNull()
	})
})
