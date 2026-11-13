/**
 * GitHub #35: the course materials page, mounted for real.
 *
 * - learners open a file through the download endpoint (the file names used to be plain text);
 * - a row that is no longer in the material folder is no dead link;
 * - text extraction only serves the AI assistant: with AI off its column and buttons are gone,
 *   and when the server has no pdftotext the page says so up front and does not offer PDFs;
 * - the folder can be chosen in the Nextcloud file picker (the text field stays).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const { api, picker } = vi.hoisted(() => ({
	api: { documents: null, folder: '/Kurs/Material', posts: [] },
	picker: { choose: null, closed: false, built: null },
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/9/material-folder') return api.folder instanceof Error ? Promise.reject(api.folder) : Promise.resolve({ data: { folder: api.folder } })
			if (url === '/apps/learning/api/courses/9/documents') return api.documents instanceof Error ? Promise.reject(api.documents) : Promise.resolve({ data: api.documents })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn((url, body) => {
			api.posts.push({ url, body })
			if (url.endsWith('/documents/scan')) return Promise.resolve({ data: [] })
			return Promise.resolve({ data: {} })
		}),
	},
}))
// Keeps the placeholders visible so the test sees which parameters reach the URL.
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), url)),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('@nextcloud/dialogs', () => ({
	getFilePickerBuilder: vi.fn((title) => {
		const config = { title }
		const builder = {
			setMultiSelect: (v) => { config.multiSelect = v; return builder },
			allowDirectories: (v) => { config.directories = v; return builder },
			setMimeTypeFilter: (v) => { config.mime = v; return builder },
			setButtonFactory: (f) => { config.factory = f; return builder },
			build: () => {
				picker.built = config
				return {
					pickNodes: async () => {
						if (picker.closed) throw new Error('FilePicker closed')
						const nodes = picker.choose ? [{ path: picker.choose }] : []
						const [button] = config.factory(nodes, '/')
						button.callback(nodes)
						return nodes
					},
				}
			},
		}
		return builder
	}),
}))

import axios from '@nextcloud/axios'
import CourseMaterials from '../../src/components/CourseMaterials.vue'
import { parseDocumentsResponse } from '../../src/utils/courseMaterials.js'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const PDF = { id: 3, file_name: 'Skript.pdf', file_type: 'pdf', file_size: 2048, created_at: 1700000000, downloadable: true }
const OLD = { id: 4, file_name: 'Alt.md', file_type: 'markdown', file_size: 10, created_at: 1700000000, downloadable: false }

function instructorRow(row, status = 'uploaded') {
	return { ...row, status, error_message: null, chunking_status: null, file_path: '/Kurs/Material/' + row.file_name }
}

describe('CourseMaterials (GitHub #35)', () => {
	let host, app, vm

	async function mount(props) {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseMaterials, { courseId: 9, ...props }) })
		vm = app.mount(host)
		await flush()
		return vm
	}

	beforeEach(() => {
		api.folder = '/Kurs/Material'
		api.posts = []
		picker.choose = null
		picker.closed = false
		picker.built = null
		vi.clearAllMocks()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('a learner opens a file through the download endpoint of this course', async () => {
		api.documents = { documents: [PDF], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		const link = host.querySelector('a.material-file-link')
		expect(link).not.toBeNull()
		expect(link.getAttribute('href')).toBe('/apps/learning/api/courses/9/documents/3/download')
		expect(link.getAttribute('target')).toBe('_blank')
		expect(link.getAttribute('rel')).toContain('noopener')
		expect(link.textContent).toBe('Skript.pdf')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/9/documents')
	})

	it('a learner sees no extraction status and no folder or extraction controls', async () => {
		api.documents = { documents: [PDF], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelector('a.material-file-link')).not.toBeNull() // the row is rendered
		expect(host.querySelector('.status-badge')).toBeNull()
		expect(host.querySelector('.material-folder-section')).toBeNull()
		expect(host.querySelector('.extract-btn')).toBeNull()
		expect(host.querySelector('.extract-explanation')).toBeNull()
	})

	it('a row that is no longer in the material folder is plain text, not a dead link', async () => {
		api.documents = { documents: [PDF, OLD], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelectorAll('a.material-file-link')).toHaveLength(1)
		expect(host.querySelector('.material-file-unavailable').textContent).toBe('Alt.md')
	})

	it('with AI switched off the owner keeps folder and scan but sees nothing of the extraction', async () => {
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: false })

		expect(host.querySelector('a.material-file-link')).not.toBeNull() // the row is rendered
		expect(host.querySelector('.material-folder-section')).not.toBeNull()
		expect(host.querySelector('.scan-btn')).not.toBeNull()
		expect(host.querySelector('.extract-btn')).toBeNull()
		expect(host.querySelector('.extract-all-btn')).toBeNull()
		expect(host.querySelector('.status-badge')).toBeNull()
		expect(host.querySelector('.extract-explanation')).toBeNull()
		expect(host.textContent).not.toContain('Extrahieren')
	})

	it('with AI on the owner gets the extraction, explained as context for VirtuProf', async () => {
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: true })

		expect(host.querySelector('.extract-explanation').textContent).toContain('VirtuProf')
		expect(host.querySelector('.extract-btn').disabled).toBe(false)
		expect(host.querySelector('.extract-all-btn').disabled).toBe(false)
		expect(host.querySelector('.pdf-unavailable-note')).toBeNull()
	})

	it('without pdftotext the page says so up front and does not offer PDFs for extraction', async () => {
		const md = instructorRow({ ...PDF, id: 5, file_name: 'Notizen.md', file_type: 'markdown' })
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: false }
		await mount({ isInstructor: true, aiEnabled: true })

		expect(host.querySelector('.pdf-unavailable-note').textContent).toContain('pdftotext')
		expect(host.querySelector('.extract-btn').disabled).toBe(true)
		expect(host.querySelector('.extract-all-btn').disabled).toBe(true)

		vm.$.subTree.component.proxy.documents = [instructorRow(PDF), md]
		await flush()
		const buttons = host.querySelectorAll('.extract-btn')
		expect(buttons[0].disabled).toBe(true)
		expect(buttons[1].disabled).toBe(false)
		expect(host.querySelector('.extract-all-btn').disabled).toBe(false)
	})

	it('a co-instructor (not the owner) sees the status but gets no folder, scan or extract controls', async () => {
		api.documents = { documents: [instructorRow(PDF, 'extracted')], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: true })

		expect(host.querySelector('.status-badge')).not.toBeNull()
		expect(host.querySelector('.material-folder-section')).toBeNull()
		expect(host.querySelector('.scan-btn')).toBeNull()
		expect(host.querySelector('.extract-btn')).toBeNull()
	})

	// The server knows nothing of the student view: it tells the owner can_manage_files=true while
	// the owner previews the course as a learner (isInstructor=false here).
	it('the owner in the student view sees the learner page, not the folder and extraction controls', async () => {
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelector('a.material-file-link')).not.toBeNull() // the row is rendered
		expect(host.querySelector('.material-folder-section')).toBeNull()
		expect(host.querySelector('.pick-folder-btn')).toBeNull()
		expect(host.querySelector('.scan-btn')).toBeNull()
		expect(host.querySelector('.extract-btn')).toBeNull()
		expect(host.querySelector('.extract-all-btn')).toBeNull()
		expect(host.querySelector('.extract-explanation')).toBeNull()
		expect(host.querySelector('.status-badge')).toBeNull()
	})

	it('a learner in a course without materials yet gets no instruction to upload or scan', async () => {
		api.documents = { documents: [], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelector('.learner-empty-state').textContent).toContain('noch keine Lernmaterialien')
		expect(host.textContent).not.toContain('Ordner scannen')
	})

	it('the owner of an empty folder is told to upload and scan, and that members can open the files', async () => {
		api.documents = { documents: [], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: false })

		expect(host.querySelector('.learner-empty-state')).toBeNull()
		expect(host.querySelector('.empty-state').textContent).toContain('Ordner scannen')
		expect(host.querySelector('.folder-visibility-hint').textContent).toContain('Kursmitglieder')
	})

	it('picking the linked folder again (picker spells it with a leading slash) links and scans nothing', async () => {
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		api.folder = 'Kurs/Material'
		await mount({ isInstructor: true, aiEnabled: true })
		picker.choose = '/Kurs/Material'

		await host.querySelector('.pick-folder-btn').click()
		await flush()

		expect(api.posts).toEqual([])
	})

	it('the folder is chosen in the Nextcloud file picker (directories only) and linked', async () => {
		api.documents = { documents: [], can_manage_files: true, pdf_text_extraction: true }
		api.folder = null
		await mount({ isInstructor: true, aiEnabled: true })
		picker.choose = '/Kurse/Security'

		await host.querySelector('.pick-folder-btn').click()
		await flush()

		expect(picker.built.multiSelect).toBe(false)
		expect(picker.built.directories).toBe(true)
		expect(picker.built.mime).toEqual(['httpd/unix-directory'])
		expect(api.posts[0]).toEqual({ url: '/apps/learning/api/courses/9/material-folder', body: { folder: '/Kurse/Security' } })
		expect(host.querySelector('.folder-input').value).toBe('/Kurse/Security')
	})

	// A failed load used to land in the empty state: "no materials released yet" for learners,
	// "no folder linked" for the owner — and the owner was invited to link a folder over the
	// linked one.
	it('a failed document list is an error with a retry, not "no materials yet"', async () => {
		api.documents = new Error('500')
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelector('.load-error').textContent).toContain('konnten nicht geladen werden')
		expect(host.querySelector('.empty-state')).toBeNull()
		expect(host.textContent).not.toContain('noch keine Lernmaterialien')

		api.documents = { documents: [PDF], can_manage_files: false, pdf_text_extraction: true }
		host.querySelector('.retry-btn').click()
		await flush()

		expect(host.querySelector('.load-error')).toBeNull()
		expect(host.querySelector('a.material-file-link').textContent).toBe('Skript.pdf')
	})

	it('a failed folder read shows the owner an error, not "no folder linked" and an empty link field', async () => {
		api.folder = new Error('404')
		api.documents = { documents: [], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: true })

		expect(host.querySelector('.load-error')).not.toBeNull()
		expect(host.querySelector('.empty-state')).toBeNull()
		expect(host.querySelector('.material-folder-section')).toBeNull()

		api.folder = '/Kurs/Material'
		host.querySelector('.retry-btn').click()
		await flush()

		expect(host.querySelector('.load-error')).toBeNull()
		expect(host.querySelector('.folder-input').value).toBe('/Kurs/Material')
		expect(host.querySelector('.empty-state').textContent).toContain('Ordner scannen')
	})

	// The document list carries can_manage_files: while it is missing, managesFiles is true for any
	// instructor, and a co-instructor got scan and extract buttons the server refuses.
	it('a failed document list shows an instructor no scan, extract or extraction note', async () => {
		api.documents = new Error('500')
		await mount({ isInstructor: true, aiEnabled: true })

		expect(host.querySelector('.load-error')).not.toBeNull()
		expect(host.querySelector('.material-actions')).toBeNull()
		expect(host.querySelector('.scan-btn')).toBeNull()
		expect(host.querySelector('.extract-all-btn')).toBeNull()
		expect(host.querySelector('.extract-explanation')).toBeNull()

		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		host.querySelector('.retry-btn').click()
		await flush()

		expect(host.querySelector('.load-error')).toBeNull()
		expect(host.querySelector('.scan-btn')).not.toBeNull()
		expect(host.querySelector('.extract-explanation')).not.toBeNull()
	})

	it('a retry whose folder read fails keeps no folder from the earlier read', async () => {
		api.documents = new Error('500')
		await mount({ isInstructor: true, aiEnabled: true })
		const component = app._instance.subTree.component.proxy
		expect(component.materialFolder).toBe('/Kurs/Material')

		api.folder = new Error('500')
		api.documents = { documents: [], can_manage_files: true, pdf_text_extraction: true }
		host.querySelector('.retry-btn').click()
		await flush()

		expect(host.querySelector('.load-error')).not.toBeNull()
		expect(component.materialFolder).toBeNull()
	})

	it('a retry whose document list fails keeps no rows or extract buttons from the earlier read', async () => {
		api.folder = new Error('500')
		api.documents = { documents: [instructorRow(PDF)], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: true })
		expect(host.querySelector('.load-error')).not.toBeNull()
		expect(host.querySelector('.material-table')).not.toBeNull()

		api.folder = '/Kurs/Material'
		api.documents = new Error('500')
		host.querySelector('.retry-btn').click()
		await flush()

		expect(host.querySelector('.load-error')).not.toBeNull()
		expect(host.querySelector('.material-table')).toBeNull()
		expect(host.querySelector('.extract-btn')).toBeNull()
	})

	it('a course without a linked folder (folder: null) is the normal empty state, not an error', async () => {
		api.folder = null
		api.documents = { documents: [], can_manage_files: false, pdf_text_extraction: true }
		await mount({ isInstructor: false, aiEnabled: true })

		expect(host.querySelector('.load-error')).toBeNull()
		expect(host.querySelector('.empty-state')).not.toBeNull()
	})

	it('closing the picker or choosing the whole home folder links nothing', async () => {
		api.documents = { documents: [], can_manage_files: true, pdf_text_extraction: true }
		await mount({ isInstructor: true, aiEnabled: true })

		picker.closed = true
		await host.querySelector('.pick-folder-btn').click()
		await flush()
		picker.closed = false
		picker.choose = null // nothing selected at "/" → the home folder
		await host.querySelector('.pick-folder-btn').click()
		await flush()

		expect(api.posts).toEqual([])
	})
})

describe('parseDocumentsResponse', () => {
	it('reads the envelope', () => {
		expect(parseDocumentsResponse({ documents: [PDF], can_manage_files: true, pdf_text_extraction: false }))
			.toEqual({ documents: [PDF], canManageFiles: true, pdfTextExtraction: false })
	})

	it('an older plain array leaves the flags unknown', () => {
		expect(parseDocumentsResponse([PDF])).toEqual({ documents: [PDF], canManageFiles: null, pdfTextExtraction: null })
		expect(parseDocumentsResponse(null)).toEqual({ documents: [], canManageFiles: null, pdfTextExtraction: null })
	})
})
