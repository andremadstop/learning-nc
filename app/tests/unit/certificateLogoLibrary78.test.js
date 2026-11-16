/**
 * GitHub #78: the certificate logo library. Admins upload named logo variants (PNG, JPEG, WebP; no
 * SVG) with the inline error of #72; a course picks one under "Certificate design" or keeps the
 * Theming logo; a certificate is drawn with the logo it was issued with.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	const fill = (text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
	globalThis.t = (_app, text, vars) => fill(text, vars)
	globalThis.n = (_app, one, many, count, vars) => fill((count === 1 ? one : many).replace('%n', String(count)), vars)
})
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => (params ? url.replace(/{(\w+)}/g, (m, k) => (k in params ? String(params[k]) : m)) : url),
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

const A = 'a'.repeat(64)
const B = 'b'.repeat(64)
const JPEG_URL = 'data:image/jpeg;base64,/9j/4AAQ'

let host, app, axios

beforeEach(async () => {
	axios = (await import('@nextcloud/axios')).default
	for (const verb of ['get', 'post', 'put', 'patch', 'delete']) {
		axios[verb].mockReset()
		axios[verb].mockImplementation(() => Promise.resolve({ data: {} }))
	}
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(component, props = {}) {
	let vm
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, { ...props, ref: (r) => { vm = r } }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	await flush()
	return vm
}

describe('a certificate is drawn with its own logo (GitHub #78)', () => {
	it('accepts PNG, JPEG and WebP data URLs as the logo, nothing else', async () => {
		const { normalizePresentation } = await import('../../src/utils/certificateLayout.js')
		expect(normalizePresentation({ logo: JPEG_URL }).logo).toBe(JPEG_URL)
		expect(normalizePresentation({ logo: 'data:image/webp;base64,UklGRg==' }).logo).toBe('data:image/webp;base64,UklGRg==')
		expect(normalizePresentation({ logo: 'data:image/svg+xml;base64,PHN2Zz4=' }).logo).toBe('')
		expect(normalizePresentation({ logo: 'https://evil.example/logo.png' }).logo).toBe('')
		expect(normalizePresentation(null).logo).toBe('')
	})

	it('the issued logo replaces the Theming logo of the credential on the card', async () => {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		const fields = { courseTitle: 'Kurs', recipientName: 'Alex', courseElements: [], score: 90, threshold: 80, issuerName: 'Coca Cola', issuerLogo: 'https://cloud.example/theming-logo.png', validFrom: '', validUntil: '' }
		await mount(CertificateCard, { fields, presentation: { logo: JPEG_URL } })
		expect(host.querySelector('[data-test="certificate-logo"]').getAttribute('src')).toBe(JPEG_URL)
		// on white like stamp and signature, so a dark wordmark stays visible on the dark-mode card
		expect(host.querySelector('[data-test="certificate-logo"]').classList.contains('certificate-logo--library')).toBe(true)
		app.unmount()
		host.remove()
		await mount(CertificateCard, { fields, presentation: null })
		expect(host.querySelector('[data-test="certificate-logo"]').getAttribute('src')).toBe('https://cloud.example/theming-logo.png')
		// the Theming logo keeps exactly the look it had
		expect(host.querySelector('[data-test="certificate-logo"]').classList.contains('certificate-logo--library')).toBe(false)
	})

	it('the copy of the Theming logo of the issuance replaces the live one, without the library plate', async () => {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		const fields = { courseTitle: 'Kurs', recipientName: 'Alex', courseElements: [], score: 90, threshold: 80, issuerName: 'Coca Cola', issuerLogo: 'https://cloud.example/theming-logo.png', validFrom: '', validUntil: '' }
		await mount(CertificateCard, { fields, presentation: { logo: JPEG_URL, logo_library: false } })
		const logo = host.querySelector('[data-test="certificate-logo"]')
		expect(logo.getAttribute('src')).toBe(JPEG_URL)
		expect(logo.classList.contains('certificate-logo--library')).toBe(false)
	})
})

describe('course settings: the logo choice (GitHub #78)', () => {
	async function settings(selected = null) {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/cert-layout/logos')) {
				return Promise.resolve({ data: { logos: [{ id: A, name: 'Primary' }, { id: B, name: 'Wordmark' }], selected } })
			}
			return Promise.resolve({ data: { stamp: null, signature: null, logo: selected ? JPEG_URL : null, issuer_name_fallback: 'Akademie' } })
		})
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		const { certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		const updates = []
		await mount(CertLayoutSettings, {
			courseId: 7,
			modelValue: certLayoutFromServer({ logo_id: selected }),
			'onUpdate:modelValue': (v) => updates.push(v),
		})
		return updates
	}

	it('offers the Theming logo and the library logos with their images through the course', async () => {
		await settings()
		const theming = host.querySelector('input[data-test="cert-layout-logo-theming"]')
		expect(theming.checked).toBe(true)
		expect(host.querySelector(`input[data-test="cert-layout-logo-${A}"]`)).not.toBeNull()
		const thumbs = Array.from(host.querySelectorAll('.cert-layout-logo-thumb')).map((img) => img.getAttribute('src'))
		expect(thumbs).toEqual([`/apps/learning/api/courses/7/cert-layout/logos/${A}`, `/apps/learning/api/courses/7/cert-layout/logos/${B}`])
		expect(host.querySelector('[data-test="cert-layout-logo"]').textContent).toContain('Wordmark')
	})

	it('picking a logo takes it over at once and the preview shows it', async () => {
		axios.put.mockResolvedValueOnce({ data: { stamp: null, signature: null, logo: JPEG_URL } })
		const updates = await settings()

		host.querySelector(`input[data-test="cert-layout-logo-${B}"]`).click()
		await flush()

		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/7/cert-layout/logo', { logoId: B })
		expect(host.querySelector(`input[data-test="cert-layout-logo-${B}"]`).checked).toBe(true)
		expect(host.querySelector('.cert-layout-preview .certificate-logo').getAttribute('src')).toBe(JPEG_URL)
		expect(updates.at(-1).logo_id).toBe(B)
	})

	it('a logo deleted meanwhile is named next to the choice and the choice goes back', async () => {
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'logo_not_found' } } })
		await settings(A)

		host.querySelector(`input[data-test="cert-layout-logo-${B}"]`).click()
		await flush()

		expect(host.querySelector('[data-test="cert-layout-logo-error"]').textContent.trim()).toBe('Dieses Logo gibt es in der Bibliothek nicht mehr.')
		expect(host.querySelector(`input[data-test="cert-layout-logo-${A}"]`).checked).toBe(true)
		const group = host.querySelector('[data-test="cert-layout-logo"]')
		expect(group.getAttribute('aria-describedby')).toContain('cert-layout-logo-error-7')
	})
})

describe('admin: the logo library (GitHub #78)', () => {
	const LIBRARY = {
		logos: [
			{ id: A, name: 'Primary', mime: 'image/png', size: 100, courses: 2 },
			{ id: B, name: 'Square', mime: 'image/webp', size: 50, courses: 0 },
		],
		max: 20,
	}

	async function library(data = LIBRARY) {
		axios.get.mockResolvedValue({ data })
		const { default: CertLogoLibrary } = await import('../../src/components/CertLogoLibrary.vue')
		return mount(CertLogoLibrary)
	}

	it('lists the logos with their images, names and how many courses use them', async () => {
		await library()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/cert-logos')
		expect(host.querySelector(`[data-test="cert-logo-${A}"] img`).getAttribute('src')).toBe(`/apps/learning/api/settings/admin/cert-logos/${A}/image`)
		expect(host.querySelector(`[data-test="cert-logo-name-${A}"]`).value).toBe('Primary')
		expect(host.querySelector(`[data-test="cert-logo-usage-${A}"]`).textContent.trim()).toBe('Von 2 Kursen verwendet')
		expect(host.querySelector(`[data-test="cert-logo-usage-${B}"]`).textContent.trim()).toBe('Von keinem Kurs verwendet')
	})

	it('without a name the upload is refused inline, before the file dialog', async () => {
		const vm = await library()
		const click = vi.fn()
		vm.$refs.file.click = click
		host.querySelector('[data-test="cert-logo-pick"]').click()
		await flush()
		expect(click).not.toHaveBeenCalled()
		expect(host.querySelector('[data-test="cert-logo-new-error"]').textContent.trim()).toBe('Bitte zuerst einen Namen für das Logo eingeben.')
	})

	it('an SVG or a file over 1 MB is refused in the field without a request', async () => {
		const vm = await library()
		vm.newName = 'Logo'
		await vm.upload(new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' }))
		await flush()
		expect(host.querySelector('[data-test="cert-logo-new-error"]').textContent.trim()).toBe('Die Datei ist kein PNG-, JPEG- oder WebP-Bild. SVG wird nicht angenommen.')

		const big = new File([new Uint8Array(1048577)], 'big.png', { type: 'image/png' })
		await vm.upload(big)
		await flush()
		expect(host.querySelector('[data-test="cert-logo-new-error"]').textContent.trim()).toBe('Die Datei ist zu groß (höchstens 1 MB und 3000 × 3000 Pixel).')
		expect(axios.post).not.toHaveBeenCalled()
		// the error is tied to the upload button (#72)
		const pick = host.querySelector('[data-test="cert-logo-pick"]')
		expect(pick.getAttribute('aria-describedby')).toContain('cert-logo-new-error')
	})

	it('the server refusal by content is shown in the field', async () => {
		const vm = await library()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'unsupported_type' } } })
		vm.newName = 'Logo'
		await vm.upload(new File(['<svg/>'], 'logo.png', { type: 'image/png' }))
		await flush()
		expect(host.querySelector('[data-test="cert-logo-new-error"]').textContent.trim()).toBe('Die Datei ist kein PNG-, JPEG- oder WebP-Bild. SVG wird nicht angenommen.')
	})

	it('uploads name and file as a form and lists the new logo', async () => {
		const vm = await library({ logos: [], max: 20 })
		expect(host.querySelector('[data-test="cert-logo-library-empty"]')).not.toBeNull()
		axios.post.mockResolvedValueOnce({ data: { logos: [{ id: A, name: 'Primary', mime: 'image/png', size: 9, courses: 0 }], max: 20, added: A } })
		vm.newName = '  Primary '
		await vm.upload(new File(['png'], 'logo.png', { type: 'image/png' }))
		await flush()

		const [url, form] = axios.post.mock.calls.at(-1)
		expect(url).toBe('/apps/learning/api/settings/admin/cert-logos')
		expect(form.get('name')).toBe('Primary')
		expect(form.get('logo')).toBeInstanceOf(File)
		expect(host.querySelector(`[data-test="cert-logo-${A}"]`)).not.toBeNull()
		expect(host.querySelector('[data-test="cert-logo-library-status"]').textContent.trim()).toBe('„Primary“ wurde hinzugefügt.')
		expect(vm.newName).toBe('')
	})

	it('deleting a used logo warns first and says how many courses went back to the Theming logo', async () => {
		await library()
		host.querySelector(`[data-test="cert-logo-delete-${A}"]`).click()
		await flush()
		const confirm = host.querySelector(`[data-test="cert-logo-confirm-${A}"]`)
		expect(confirm.textContent).toContain('2 Kurse verwenden dieses Logo.')
		expect(confirm.textContent).toContain('Bereits ausgestellte Zertifikate behalten ihr Logo.')
		expect(axios.delete).not.toHaveBeenCalled()

		axios.delete.mockResolvedValueOnce({ data: { logos: [LIBRARY.logos[1]], max: 20, courses_reset: 2 } })
		host.querySelector(`[data-test="cert-logo-confirm-delete-${A}"]`).click()
		await flush()

		expect(axios.delete).toHaveBeenCalledWith(`/apps/learning/api/settings/admin/cert-logos/${A}`)
		expect(host.querySelector(`[data-test="cert-logo-${A}"]`)).toBeNull()
		expect(host.querySelector('[data-test="cert-logo-library-status"]').textContent.trim()).toBe('„Primary“ wurde gelöscht. 2 Kurse tragen jetzt wieder das Logo dieser Nextcloud.')
	})

	it('a full library takes no more uploads', async () => {
		const logos = Array.from({ length: 3 }, (_, i) => ({ id: String(i).repeat(64), name: 'L' + i, mime: 'image/png', size: 1, courses: 0 }))
		await library({ logos, max: 3 })
		expect(host.querySelector('[data-test="cert-logo-library-full"]')).not.toBeNull()
		expect(host.querySelector('[data-test="cert-logo-pick"]').disabled).toBe(true)
	})
})
