/**
 * GitHub #75/#77/#78 (review): @nextcloud/l10n escapes placeholder values by default, and Vue
 * escapes them again in {{ }} and attributes; window.confirm shows the text as it is. File names,
 * logo names and the organisation name are passed raw ({ escape: false, sanitize: false }), so
 * "Müller & Söhne GmbH" does not read "Müller &amp; Söhne GmbH".
 *
 * The real translate() of @nextcloud/l10n, not a mock: a mock that never escapes cannot see this.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => (params ? url.replace(/{(\w+)}/g, (m, k) => (k in params ? String(params[k]) : m)) : url),
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'anna' }) }))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

const NAME = 'Datenschutz & IT\'s Guide.pdf'
let host, app, axios

beforeEach(async () => {
	globalThis.t = translate
	globalThis.n = translatePlural
	axios = (await import('@nextcloud/axios')).default
	axios.get.mockReset()
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.unstubAllGlobals()
})

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(component, props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.mount(host)
	await flush()
}

describe('placeholder values are shown as written', () => {
	it('a file name in the "Required" column and in the question before it becomes optional', async () => {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/material-folder')) return Promise.resolve({ data: { folder: '/Kurs' } })
			if (url.endsWith('/documents')) return Promise.resolve({ data: { documents: [{ id: 31, file_name: NAME, file_type: 'pdf', file_size: 1, created_at: 1700000000, downloadable: true }], can_manage_files: true } })
			if (url.endsWith('/videos')) return Promise.resolve({ data: [{ id: 7, source_type: 'document', video_ref: '31', document_id: 31 }] })
			return Promise.resolve({ data: {} })
		})
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		const { default: CourseMaterials } = await import('../../src/components/CourseMaterials.vue')
		await mount(CourseMaterials, { courseId: 9, isInstructor: true })

		const box = host.querySelector('[data-test="required-doc-31"]')
		expect(box.getAttribute('aria-label')).toBe(`„${NAME}“ ist Pflicht`)
		expect(host.querySelector('[data-test="reconfirm-doc-31"]').getAttribute('aria-label')).toBe(`„${NAME}“: alle erneut bestätigen lassen`)
		box.checked = false
		box.dispatchEvent(new Event('change'))
		await flush()
		expect(confirm.mock.calls[0][0]).toContain(`„${NAME}“ wird optional.`)
		host.querySelector('[data-test="reconfirm-doc-31"]').click()
		await flush()
		expect(confirm.mock.calls[1][0]).toContain(`„${NAME}“ erneut`)
	})

	it('a logo name in the logo library', async () => {
		axios.get.mockResolvedValue({ data: { logos: [{ id: 'a'.repeat(64), name: 'Logo A&B', mime: 'image/png', size: 1, courses: 0 }], max: 20 } })
		const { default: CertLogoLibrary } = await import('../../src/components/CertLogoLibrary.vue')
		await mount(CertLogoLibrary)
		expect(host.querySelector(`[data-test="cert-logo-delete-${'a'.repeat(64)}"]`).getAttribute('aria-label')).toBe('„Logo A&B“ löschen')
	})

	it('the organisation name in the certificate design hint', async () => {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/cert-layout/logos')) return Promise.resolve({ data: { logos: [], selected: null } })
			return Promise.resolve({ data: { stamp: null, signature: null, logo: null, issuer_name_fallback: 'Müller & Söhne GmbH' } })
		})
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		const { certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		await mount(CertLayoutSettings, { courseId: 7, modelValue: certLayoutFromServer({}) })
		const hint = host.querySelector('[data-test="cert-layout-org"]').textContent
		expect(hint).toContain('„Müller & Söhne GmbH“')
		expect(hint).not.toContain('&amp;')
	})
})
