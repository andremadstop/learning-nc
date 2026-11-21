/**
 * GitHub #93: certificate backgrounds. Admins keep a library (PNG, JPEG, WebP; no SVG; 2 MB), three
 * backgrounds ship with the app, a course picks one with a strength under "Certificate design", and
 * a certificate is drawn with the background it was issued with. Without background the card is
 * exactly the card it was before.
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
const FIELDS = { courseTitle: 'Kurs', recipientName: 'Alex', courseElements: [], score: 90, threshold: 80, issuerName: 'Northwind', issuerLogo: '', validFrom: '', validUntil: '' }

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

function remount() {
	app.unmount()
	host.remove()
}

describe('the presentation: only data URLs and known built-in ids (GitHub #93)', () => {
	it('accepts PNG, JPEG and WebP data URLs as the background, nothing else', async () => {
		const { normalizePresentation } = await import('../../src/utils/certificateLayout.js')
		expect(normalizePresentation({ background: JPEG_URL }).background).toBe(JPEG_URL)
		expect(normalizePresentation({ background: 'data:image/svg+xml;base64,PHN2Zz4=' }).background).toBe('')
		expect(normalizePresentation({ background: 'https://evil.example/bg.png' }).background).toBe('')
		expect(normalizePresentation({ background: 'javascript:alert(1)' }).background).toBe('')
		expect(normalizePresentation(null).background).toBe('')
	})

	it('a built-in background only by its whitelisted id: it becomes a CSS class', async () => {
		const { normalizePresentation } = await import('../../src/utils/certificateLayout.js')
		for (const id of ['frame', 'corners', 'lines']) {
			expect(normalizePresentation({ background_builtin: id }).backgroundBuiltin).toBe(id)
		}
		for (const id of ['frame x', 'frame" onload="x', '../frame', 'FRAME', 'builtin:frame', 7, null]) {
			expect(normalizePresentation({ background_builtin: id }).backgroundBuiltin).toBe('')
		}
		// a library image wins: at most one background
		expect(normalizePresentation({ background: JPEG_URL, background_builtin: 'frame' }).backgroundBuiltin).toBe('')
	})

	it('the strength is 10–100 in steps of 10, else 100; without background always 100', async () => {
		const { normalizePresentation } = await import('../../src/utils/certificateLayout.js')
		expect(normalizePresentation({ background_builtin: 'frame', background_intensity: 40 }).backgroundIntensity).toBe(40)
		for (const value of [0, 5, 15, 110, '40', 40.5, null]) {
			expect(normalizePresentation({ background_builtin: 'frame', background_intensity: value }).backgroundIntensity).toBe(100)
		}
		expect(normalizePresentation({ background_intensity: 40 }).backgroundIntensity).toBe(100)
	})
})

describe('the card (GitHub #93)', () => {
	it('without background nothing changes: no layer, no modifier class', async () => {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		for (const presentation of [null, { intro: 'x' }, { background_builtin: 'nope' }]) {
			await mount(CertificateCard, { fields: FIELDS, presentation })
			const card = host.querySelector('.certificate-card')
			expect(card.className).toBe('certificate-card')
			expect(card.querySelector('.certificate-bg')).toBeNull()
			expect(card.firstElementChild.classList.contains('certificate-brand')).toBe(true)
			remount()
		}
	})

	it('a library background is a decorative image behind a fixed paper plate', async () => {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		await mount(CertificateCard, { fields: FIELDS, presentation: { background: JPEG_URL, background_intensity: 100 } })
		const card = host.querySelector('.certificate-card')
		expect(card.classList.contains('certificate-card--background')).toBe(true)
		const layer = card.querySelector('[data-test="certificate-background"]')
		expect(layer.getAttribute('aria-hidden')).toBe('true')
		const image = layer.querySelector('[data-test="certificate-background-image"]')
		expect(image.getAttribute('src')).toBe(JPEG_URL)
		expect(image.getAttribute('alt')).toBe('')
		expect(layer.querySelector('.certificate-bg-plate')).not.toBeNull()
		// full strength: no veil
		expect(layer.querySelector('[data-test="certificate-background-veil"]')).toBeNull()
		// the layer comes first, the print grid's sibling rules (signoff + verify) are untouched
		expect(card.firstElementChild).toBe(layer)
	})

	it('a built-in background is drawn by its class; the strength is a white veil above it', async () => {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		await mount(CertificateCard, { fields: FIELDS, presentation: { background_builtin: 'corners', background_intensity: 30 } })
		const builtin = host.querySelector('[data-test="certificate-background-corners"]')
		expect(builtin.classList.contains('certificate-bg--corners')).toBe(true)
		expect(host.querySelector('[data-test="certificate-background-image"]')).toBeNull()
		expect(host.querySelector('[data-test="certificate-background-veil"]').style.opacity).toBe('0.7')
	})
})

describe('course settings: the background choice (GitHub #93)', () => {
	async function settings({ selected = '', intensity = 100, library = [{ id: A, name: 'Gold frame' }, { id: B, name: 'Photo' }] } = {}) {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/cert-layout/backgrounds')) {
				return Promise.resolve({ data: { backgrounds: library, builtins: ['frame', 'corners', 'lines'], selected, intensity } })
			}
			if (url.endsWith('/cert-layout/logos')) {
				return Promise.resolve({ data: { logos: [], selected: null } })
			}
			return Promise.resolve({ data: { stamp: null, signature: null, logo: null, background: selected === A ? JPEG_URL : null, issuer_name_fallback: 'Akademie' } })
		})
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		const { certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		const updates = []
		const vm = await mount(CertLayoutSettings, {
			courseId: 7,
			modelValue: certLayoutFromServer({
				background_id: selected && !selected.startsWith('builtin:') ? selected : null,
				background_builtin: selected.startsWith('builtin:') ? selected.slice(8) : null,
				background_intensity: intensity,
			}),
			'onUpdate:modelValue': (v) => updates.push(v),
		})
		return { updates, vm }
	}

	it('offers none, the three built-in backgrounds and the library with images through the course', async () => {
		await settings()
		expect(host.querySelector('input[data-test="cert-layout-background-none"]').checked).toBe(true)
		for (const key of ['frame', 'corners', 'lines']) {
			expect(host.querySelector(`input[data-test="cert-layout-background-${key}"]`)).not.toBeNull()
		}
		const thumbs = Array.from(host.querySelectorAll('.cert-layout-background-thumb')).map((img) => img.getAttribute('src'))
		expect(thumbs).toEqual([`/apps/learning/api/courses/7/cert-layout/backgrounds/${A}`, `/apps/learning/api/courses/7/cert-layout/backgrounds/${B}`])
		// no background: no slider, the preview stays closed
		expect(host.querySelector('[data-test="cert-layout-background-intensity"]')).toBeNull()
		expect(host.querySelector('[data-test="cert-layout-preview"]').open).toBe(false)
	})

	it('choosing a built-in background saves it at once, opens the preview and shows it there', async () => {
		axios.put.mockResolvedValueOnce({ data: { stamp: null, signature: null, logo: null, background: null, background_builtin: 'frame', background_intensity: 100 } })
		const { updates } = await settings()

		host.querySelector('input[data-test="cert-layout-background-frame"]').click()
		await flush()

		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/7/cert-layout/background', { backgroundId: 'builtin:frame', intensity: 100 })
		expect(host.querySelector('[data-test="cert-layout-preview"]').open).toBe(true)
		expect(host.querySelector('.cert-layout-preview [data-test="certificate-background-frame"]')).not.toBeNull()
		expect(updates.at(-1)).toMatchObject({ background_id: null, background_builtin: 'frame', background_intensity: 100 })
	})

	it('the slider changes the preview while it moves and saves once when let go', async () => {
		axios.put.mockResolvedValue({ data: { stamp: null, signature: null, logo: null, background: JPEG_URL, background_intensity: 40 } })
		const { updates } = await settings({ selected: A })
		expect(host.querySelector('.cert-layout-preview [data-test="certificate-background-image"]').getAttribute('src')).toBe(JPEG_URL)

		const slider = host.querySelector('[data-test="cert-layout-background-intensity"]')
		for (const value of ['90', '70', '50', '40']) {
			slider.value = value
			slider.dispatchEvent(new Event('input'))
			await nextTick()
		}
		expect(axios.put).not.toHaveBeenCalled()
		expect(host.querySelector('.cert-layout-preview [data-test="certificate-background-veil"]').style.opacity).toBe('0.6')

		slider.dispatchEvent(new Event('change'))
		await flush()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/courses/7/cert-layout/background', { backgroundId: A, intensity: 40 })
		expect(updates.at(-1)).toMatchObject({ background_id: A, background_builtin: null, background_intensity: 40 })
	})

	it('while a save runs, the last choice wins and nothing in between is sent', async () => {
		let release
		axios.put.mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve({ data: { background_builtin: 'frame' } }) }))
		axios.put.mockResolvedValueOnce({ data: { background_builtin: 'lines' } })
		await settings()

		host.querySelector('input[data-test="cert-layout-background-frame"]').click()
		await nextTick()
		host.querySelector('input[data-test="cert-layout-background-corners"]').click()
		await nextTick()
		host.querySelector('input[data-test="cert-layout-background-lines"]').click()
		await nextTick()
		expect(axios.put).toHaveBeenCalledTimes(1)
		release()
		await flush()

		expect(axios.put).toHaveBeenCalledTimes(2)
		expect(axios.put.mock.calls[1][1]).toEqual({ backgroundId: 'builtin:lines', intensity: 100 })
		expect(host.querySelector('input[data-test="cert-layout-background-lines"]').checked).toBe(true)
	})

	it('a background deleted meanwhile is named next to the choice and the choice goes back', async () => {
		axios.put.mockRejectedValueOnce({ response: { data: { error: 'background_not_found' } } })
		await settings({ selected: 'builtin:corners', intensity: 60 })

		host.querySelector(`input[data-test="cert-layout-background-${B}"]`).click()
		await flush()

		expect(host.querySelector('[data-test="cert-layout-background-error"]').textContent.trim()).toBe('Diesen Hintergrund gibt es in der Bibliothek nicht mehr.')
		expect(host.querySelector('input[data-test="cert-layout-background-corners"]').checked).toBe(true)
		expect(host.querySelector('[data-test="cert-layout-background"]').getAttribute('aria-describedby')).toContain('cert-layout-background-error-7')
	})

	it('choosing none again sends the choice and hides the slider', async () => {
		axios.put.mockResolvedValueOnce({ data: { background: null, background_builtin: null, background_intensity: 100 } })
		const { updates } = await settings({ selected: 'builtin:lines', intensity: 50 })
		expect(host.querySelector('[data-test="cert-layout-background-intensity"]').value).toBe('50')

		host.querySelector('input[data-test="cert-layout-background-none"]').click()
		await flush()

		expect(axios.put.mock.calls[0][1].backgroundId).toBe('')
		expect(host.querySelector('[data-test="cert-layout-background-intensity"]')).toBeNull()
		expect(updates.at(-1)).toMatchObject({ background_id: null, background_builtin: null, background_intensity: 100 })
	})
})

describe('admin: the background library (GitHub #93)', () => {
	const LIBRARY = {
		backgrounds: [
			{ id: A, name: 'Gold frame', mime: 'image/png', size: 100, courses: 3 },
			{ id: B, name: 'Photo', mime: 'image/jpeg', size: 50, courses: 0 },
		],
		max: 20,
	}

	async function library(data = LIBRARY) {
		axios.get.mockResolvedValue({ data })
		const { default: CertBackgroundLibrary } = await import('../../src/components/CertBackgroundLibrary.vue')
		return mount(CertBackgroundLibrary)
	}

	it('lists the backgrounds with their images, names and how many courses use them', async () => {
		await library()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/cert-backgrounds')
		expect(host.querySelector(`[data-test="cert-background-${A}"] img`).getAttribute('src')).toBe(`/apps/learning/api/settings/admin/cert-backgrounds/${A}/image`)
		expect(host.querySelector(`[data-test="cert-background-name-${A}"]`).value).toBe('Gold frame')
		expect(host.querySelector(`[data-test="cert-background-usage-${A}"]`).textContent.trim()).toBe('Von 3 Kursen verwendet')
		expect(host.querySelector(`[data-test="cert-background-usage-${B}"]`).textContent.trim()).toBe('Von keinem Kurs verwendet')
	})

	it('an SVG or a file over 2 MB is refused with an explanation, without a request', async () => {
		const vm = await library()
		vm.newName = 'Frame'
		await vm.upload(new File(['<svg/>'], 'frame.svg', { type: 'image/svg+xml' }))
		await flush()
		const error = () => host.querySelector('[data-test="cert-background-new-error"]').textContent.trim()
		expect(error()).toContain('SVG wird nicht angenommen.')
		expect(error()).toContain('exportiere ihn in einem Grafikprogramm als PNG oder JPEG')

		await vm.upload(new File([new Uint8Array(2097153)], 'big.jpg', { type: 'image/jpeg' }))
		await flush()
		expect(error()).toBe('Die Datei ist zu groß (höchstens 2 MB und 4000 Pixel je Seite). Als JPEG gespeichert ist ein Foto meist deutlich kleiner.')
		expect(axios.post).not.toHaveBeenCalled()
		// 1.5 MB is fine for a background (the logo limit is 1 MB)
		axios.post.mockResolvedValueOnce({ data: { ...LIBRARY, added: A } })
		await vm.upload(new File([new Uint8Array(1572864)], 'photo.jpg', { type: 'image/jpeg' }))
		await flush()
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(host.querySelector('[data-test="cert-background-pick"]').getAttribute('aria-describedby')).toContain('cert-background-new-error')
	})

	it('the server refusal by content is shown as an explanation, not as "error"', async () => {
		const vm = await library()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'unsupported_type' } } })
		vm.newName = 'Frame'
		await vm.upload(new File(['<svg/>'], 'frame.png', { type: 'image/png' }))
		await flush()
		expect(host.querySelector('[data-test="cert-background-new-error"]').textContent.trim()).toContain('SVG wird nicht angenommen.')
	})

	it('uploads name and file as a form', async () => {
		const vm = await library({ backgrounds: [], max: 20 })
		expect(host.querySelector('[data-test="cert-background-library-empty"]')).not.toBeNull()
		axios.post.mockResolvedValueOnce({ data: { backgrounds: [LIBRARY.backgrounds[0]], max: 20, added: A } })
		vm.newName = '  Gold frame '
		await vm.upload(new File(['png'], 'frame.png', { type: 'image/png' }))
		await flush()

		const [url, form] = axios.post.mock.calls.at(-1)
		expect(url).toBe('/apps/learning/api/settings/admin/cert-backgrounds')
		expect(form.get('name')).toBe('Gold frame')
		expect(form.get('background')).toBeInstanceOf(File)
		expect(form.get('logo')).toBeNull()
		expect(host.querySelector('[data-test="cert-background-library-status"]').textContent.trim()).toBe('„Gold frame“ wurde hinzugefügt.')
	})

	it('deleting a used background says beforehand how many courses lose it, and afterwards how many did', async () => {
		await library()
		host.querySelector(`[data-test="cert-background-delete-${A}"]`).click()
		await flush()
		const confirm = host.querySelector(`[data-test="cert-background-confirm-${A}"]`)
		expect(confirm.textContent).toContain('Wird in 3 Kursen verwendet. Diese Kurse erhalten keinen Hintergrund mehr; ausgestellte Zertifikate behalten ihren.')
		expect(axios.delete).not.toHaveBeenCalled()

		axios.delete.mockResolvedValueOnce({ data: { backgrounds: [LIBRARY.backgrounds[1]], max: 20, courses_reset: 3 } })
		host.querySelector(`[data-test="cert-background-confirm-delete-${A}"]`).click()
		await flush()

		expect(axios.delete).toHaveBeenCalledWith(`/apps/learning/api/settings/admin/cert-backgrounds/${A}`, { params: { revision: '' } })
		expect(host.querySelector(`[data-test="cert-background-${A}"]`)).toBeNull()
		expect(host.querySelector('[data-test="cert-background-library-status"]').textContent.trim()).toBe('„Gold frame“ wurde gelöscht. 3 Kurse haben jetzt keinen Hintergrund mehr.')
	})

	it('an unused background is deleted with the plain note', async () => {
		await library()
		host.querySelector(`[data-test="cert-background-delete-${B}"]`).click()
		await flush()
		expect(host.querySelector(`[data-test="cert-background-confirm-${B}"]`).textContent).toContain('Bereits ausgestellte Zertifikate behalten ihren Hintergrund.')
	})

	it('a full library takes no more uploads', async () => {
		const backgrounds = Array.from({ length: 3 }, (_, i) => ({ id: String(i).repeat(64), name: 'B' + i, mime: 'image/png', size: 1, courses: 0 }))
		await library({ backgrounds, max: 3 })
		expect(host.querySelector('[data-test="cert-background-library-full"]').textContent).toContain('höchstens 3 Hintergründe')
		expect(host.querySelector('[data-test="cert-background-pick"]').disabled).toBe(true)
	})
})
