/**
 * GitHub #90: stale entries in the learning materials.
 *
 * - an entry whose file is gone or from an earlier folder says so, and the instructor can take it
 *   out of the list (×); a required one is asked about first;
 * - a file that exists but cannot be opened says why instead of linking to a "Not found" page;
 * - learners (and the student view) never see the stale entries;
 * - after a scan or a × the required-content settings reload (rows may have gone dormant).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const { api } = vi.hoisted(() => ({ api: { documents: null, registry: [], deletes: [], deleteStatus: 200, scanned: null } }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/9/material-folder') return Promise.resolve({ data: { folder: '/Kurs' } })
			if (url === '/apps/learning/api/courses/9/documents') return Promise.resolve({ data: api.documents })
			if (url === '/apps/learning/api/courses/9/videos') return Promise.resolve({ data: api.registry })
			return Promise.resolve({ data: {} })
		}),
		delete: vi.fn((url) => {
			api.deletes.push(url)
			if (api.deleteStatus !== 200) {
				return Promise.reject(Object.assign(new Error('x'), { response: { status: api.deleteStatus, data: {} } }))
			}
			const id = Number(url.split('/').pop())
			api.registry = api.registry.filter((row) => row.document_id !== id)
			return Promise.resolve({ data: api.documents.documents.filter((doc) => doc.id !== id) })
		}),
		post: vi.fn((url) => {
			if (url.endsWith('/documents/scan')) {
				api.registry = []
				return Promise.resolve({ data: api.scanned })
			}
			return Promise.resolve({ data: {} })
		}),
		put: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), url)),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, v), text), translatePlural: (_a, s, p, c) => (c === 1 ? s : p), getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))

import axios from '@nextcloud/axios'
import CourseMaterials from '../../src/components/CourseMaterials.vue'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const doc = (id, name, reason = null) => ({ id, file_name: name, file_type: 'pdf', file_size: 10, created_at: 1700000000, downloadable: reason === null, unavailable_reason: reason })

describe('CourseMaterials — stale entries (GitHub #90)', () => {
	let host, app, emitted

	async function mount(props) {
		emitted = { error: [], required: [] }
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(CourseMaterials, {
				courseId: 9,
				...props,
				onError: (m) => emitted.error.push(m),
				onRequiredChanged: (r) => emitted.required.push(r),
			}),
		})
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		api.documents = {
			documents: [doc(1, 'Live.pdf'), doc(2, 'Deleted.pdf', 'missing'), doc(3, 'Old folder.pdf', 'outside_folder'), doc(4, 'View only.pdf', 'no_download')],
			can_manage_files: true,
		}
		api.registry = [{ id: 70, source_type: 'document', video_ref: '2', document_id: 2 }]
		api.deletes = []
		api.deleteStatus = 200
		api.scanned = null
		vi.clearAllMocks()
		vi.unstubAllGlobals()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('names why each unavailable entry cannot be opened, as visible text', async () => {
		await mount({ isInstructor: true })
		expect(host.querySelector('[data-test="unavailable-doc-1"]')).toBeNull()
		expect(host.querySelector('[data-test="unavailable-doc-2"]').textContent).toContain('nicht mehr im Materialordner')
		expect(host.querySelector('[data-test="unavailable-doc-3"]').textContent).toContain('früher verknüpften Ordner')
		expect(host.querySelector('[data-test="unavailable-doc-4"]').textContent).toContain('nur zum Ansehen')
		// no link that ends on "Not found"
		expect([...host.querySelectorAll('a.material-file-link')].map((a) => a.textContent)).toEqual(['Live.pdf'])
	})

	it('offers × only on entries whose file left the folder', async () => {
		await mount({ isInstructor: true })
		expect(host.querySelector('[data-test="remove-doc-1"]')).toBeNull()
		expect(host.querySelector('[data-test="remove-doc-2"]')).not.toBeNull()
		expect(host.querySelector('[data-test="remove-doc-3"]')).not.toBeNull()
		// still in the folder: the next scan would bring it back
		expect(host.querySelector('[data-test="remove-doc-4"]')).toBeNull()
	})

	it('× takes an optional entry out without asking', async () => {
		const confirm = vi.fn(() => true)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		host.querySelector('[data-test="remove-doc-3"]').click()
		await flush()
		expect(confirm).not.toHaveBeenCalled()
		expect(api.deletes).toEqual(['/apps/learning/api/courses/9/documents/3'])
		expect(host.textContent).not.toContain('Old folder.pdf')
	})

	it('× on a required entry asks first, keeps it on cancel, and reloads the required settings', async () => {
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		await mount({ isInstructor: true })
		host.querySelector('[data-test="remove-doc-2"]').click()
		await flush()
		expect(confirm).toHaveBeenCalledTimes(1)
		expect(api.deletes).toEqual([])

		confirm.mockReturnValue(true)
		host.querySelector('[data-test="remove-doc-2"]').click()
		await flush()
		expect(api.deletes).toEqual(['/apps/learning/api/courses/9/documents/2'])
		expect(host.textContent).not.toContain('Deleted.pdf')
		expect(emitted.required).toHaveLength(1)
	})

	it('says why a × was refused', async () => {
		api.deleteStatus = 409
		await mount({ isInstructor: true })
		host.querySelector('[data-test="remove-doc-3"]').click()
		await flush()
		expect(emitted.error.at(-1)).toContain('noch im Materialordner')
		expect(host.textContent).toContain('Old folder.pdf')
	})

	it('learners and the student view see neither stale entries nor ×', async () => {
		await mount({ isInstructor: false })
		expect(host.textContent).toContain('Live.pdf')
		expect(host.textContent).not.toContain('Deleted.pdf')
		expect(host.textContent).not.toContain('Old folder.pdf')
		expect(host.querySelector('.material-remove-btn')).toBeNull()
		// an existing file that cannot be opened says so in words a learner can act on
		expect(host.querySelector('[data-test="unavailable-doc-4"]').textContent).toContain('wende dich an deine Lehrkraft')
	})

	it('a scan shows the cleaned list and reloads the required settings', async () => {
		api.scanned = [doc(1, 'Live.pdf'), doc(4, 'View only.pdf', 'no_download')]
		await mount({ isInstructor: true })
		host.querySelector('.scan-btn').click()
		await flush()
		expect(host.textContent).not.toContain('Deleted.pdf')
		expect(host.textContent).not.toContain('Old folder.pdf')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/9/videos')
		expect(emitted.required).toEqual([[]])
	})
})

describe('CourseMaterials — a learner gets no folder path (GitHub #90, U2)', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('knows a folder is linked from "linked" alone and says nothing is shared yet', async () => {
		const get = axios.get.getMockImplementation()
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/courses/9/material-folder') return Promise.resolve({ data: { folder: null, linked: true } })
			if (url === '/apps/learning/api/courses/9/documents') return Promise.resolve({ data: { documents: [], can_manage_files: false } })
			return get(url)
		})
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseMaterials, { courseId: 9, isInstructor: false }) })
		app.mount(host)
		await flush()
		axios.get.mockImplementation(get)

		expect(host.querySelector('.learner-empty-state')).not.toBeNull()
		expect(host.textContent).not.toContain('Kein Materialordner verknüpft')
	})
})
