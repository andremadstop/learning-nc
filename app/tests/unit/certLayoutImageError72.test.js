/**
 * GitHub #72: a stamp over 1 MB was refused, but the message only appeared at the top of the page,
 * out of sight. The refusal is now shown in the "Stamp and signature" section, in the field of the
 * image that was refused: announced (live region), tied to the pick button (aria-describedby) next
 * to the limit hint, and cleared by the next attempt. A file the picker already knows is too large
 * is refused without a request. ImageFileField is the shared field (also for the logo library, #78).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const picker = vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
	return { node: null }
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, imagePath: (app, file) => `/apps/${app}/img/${file}` }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: { stamp: null, signature: null } })),
		post: vi.fn(() => Promise.resolve({ data: { stamp: null, signature: null } })),
		delete: vi.fn(() => Promise.resolve({ data: { stamp: null, signature: null } })),
	},
}))
// The file picker: "chooses" picker.node with the dialog's own button.
vi.mock('@nextcloud/dialogs', () => ({
	getFilePickerBuilder: () => {
		let factory = null
		const builder = {
			setMultiSelect: () => builder,
			setMimeTypeFilter: () => builder,
			setButtonFactory: (f) => { factory = f; return builder },
			build: () => ({
				pickNodes: async () => {
					factory([picker.node])[0].callback([picker.node])
					return [picker.node]
				},
			}),
		}
		return builder
	},
}))

const STAMP = 'data:image/png;base64,iVBORw0KGgo='
const TOO_LARGE = 'Die Datei ist zu groß (höchstens 1 MB und 3000 × 3000 Pixel).'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('stamp and signature: a refused file is named in its own field (GitHub #72)', () => {
	let app
	let host
	let emitted

	afterEach(async () => {
		app?.unmount()
		host?.remove()
		const axios = (await import('@nextcloud/axios')).default
		axios.post.mockClear()
	})

	async function mount() {
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		emitted = []
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(CertLayoutSettings, {
				courseId: 7,
				modelValue: { show: {} },
				onError: (m) => emitted.push(m),
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await flush()
	}

	const field = (kind) => host.querySelector(`[data-test="cert-layout-image-${kind}"]`)
	const errorOf = (kind) => host.querySelector(`[data-test="cert-layout-image-${kind}-error"]`)
	const pick = async (kind) => { host.querySelector(`[data-test="cert-layout-pick-${kind}"]`).click(); await flush() }

	it('a file over 1 MB is refused in the stamp field at once, without a request', async () => {
		const axios = (await import('@nextcloud/axios')).default
		await mount()
		picker.node = { path: '/Stempel.png', size: 2 * 1048576 }

		await pick('stamp')

		expect(axios.post).not.toHaveBeenCalled()
		expect(errorOf('stamp').textContent.trim()).toBe(TOO_LARGE)
		expect(field('stamp').contains(errorOf('stamp'))).toBe(true)
		expect(errorOf('signature').textContent.trim()).toBe('')
		expect(emitted).toEqual([])
	})

	it('the error is a live region the pick button points to, next to the limit hint', async () => {
		await mount()
		picker.node = { path: '/Stempel.png', size: 2 * 1048576 }
		await pick('stamp')

		const error = errorOf('stamp')
		expect(error.getAttribute('aria-live')).toBe('polite')
		const button = host.querySelector('[data-test="cert-layout-pick-stamp"]')
		const described = (button.getAttribute('aria-describedby') || '').split(' ')
		expect(described).toContain(error.id)
		const hint = host.querySelector('#' + described[0])
		expect(hint.textContent).toContain('höchstens 1 MB')
		// no aria-invalid: ARIA 1.2 does not allow it on a button (axe aria-allowed-attr)
		expect(button.hasAttribute('aria-invalid')).toBe(false)
	})

	it('a refusal of the server (pixels) lands in the field of that image, not at the top of the page', async () => {
		const axios = (await import('@nextcloud/axios')).default
		await mount()
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { error: 'too_large' } } })
		picker.node = { path: '/Unterschrift.png', size: 2000 }

		await pick('signature')

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(errorOf('signature').textContent.trim()).toBe(TOO_LARGE)
		expect(errorOf('stamp').textContent.trim()).toBe('')
		expect(emitted).toEqual([])
	})

	it('the next attempt clears the message; a good file shows its thumbnail', async () => {
		const axios = (await import('@nextcloud/axios')).default
		await mount()
		picker.node = { path: '/Stempel-gross.png', size: 2 * 1048576 }
		await pick('stamp')
		expect(errorOf('stamp').textContent.trim()).toBe(TOO_LARGE)

		axios.post.mockResolvedValueOnce({ data: { stamp: STAMP, signature: null } })
		picker.node = { path: '/Stempel.png', size: 2000 }
		await pick('stamp')

		expect(errorOf('stamp').textContent.trim()).toBe('')
		expect(field('stamp').querySelector('img')?.getAttribute('src')).toBe(STAMP)
	})

	it('the same refusal twice in a row changes the live region both times, so both are announced', async () => {
		await mount()
		picker.node = { path: '/Stempel.png', size: 2 * 1048576 }
		await pick('stamp')
		expect(errorOf('stamp').textContent.trim()).toBe(TOO_LARGE)

		const region = errorOf('stamp')
		const seen = []
		const observer = new MutationObserver(() => seen.push(region.textContent.trim()))
		observer.observe(region, { childList: true, characterData: true, subtree: true })
		picker.node = { path: '/Stempel-2.png', size: 3 * 1048576 }
		await pick('stamp')
		observer.disconnect()

		// emptied first, then the message again: a screen reader hears the second refusal
		expect(seen).toContain('')
		expect(seen[seen.length - 1]).toBe(TOO_LARGE)
		expect(region.textContent.trim()).toBe(TOO_LARGE)
	})

	it('a node without a known size goes to the server, which decides', async () => {
		const axios = (await import('@nextcloud/axios')).default
		await mount()
		picker.node = { path: '/Stempel.png' }
		await pick('stamp')
		expect(axios.post).toHaveBeenCalledTimes(1)
	})
})

describe('ImageFileField (GitHub #72, shared with #78)', () => {
	let app
	let host

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	async function mountField(props, on = {}) {
		const { default: ImageFileField } = await import('../../src/components/ImageFileField.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(ImageFileField, { idBase: 'logo-1', label: 'Logo', pickLabel: 'Datei wählen', ...props, ...on }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await flush()
	}

	it('shows its own limit hint and keeps the live region in the DOM before any error', async () => {
		await mountField({ hint: 'PNG, JPEG oder WebP, höchstens 1 MB.' })
		const button = host.querySelector('button')
		expect(button.getAttribute('aria-describedby')).toBe('logo-1-hint logo-1-error')
		expect(host.querySelector('#logo-1-hint').textContent).toContain('höchstens 1 MB')
		expect(host.querySelector('#logo-1-error').getAttribute('aria-live')).toBe('polite')
		expect(host.querySelector('#logo-1-error').textContent.trim()).toBe('')
		expect(host.querySelector('[role="group"]').getAttribute('aria-labelledby')).toBe('logo-1-label')
	})

	it('emits pick and remove; remove only with an image', async () => {
		const onPick = vi.fn()
		const onRemove = vi.fn()
		await mountField({ imageUrl: STAMP, removeLabel: 'Entfernen' }, { onPick, onRemove })
		const buttons = host.querySelectorAll('button')
		expect(buttons).toHaveLength(2)
		buttons[0].click()
		buttons[1].click()
		expect(onPick).toHaveBeenCalledTimes(1)
		expect(onRemove).toHaveBeenCalledTimes(1)
	})

	it('a busy field cannot be picked twice', async () => {
		await mountField({ busy: true })
		expect(host.querySelector('button').disabled).toBe(true)
	})
})
