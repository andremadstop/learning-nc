/**
 * GitHub #77: the organisation name on the certificate. The course can name its organisation
 * (legal name instead of the instance's brand) and hide the name beside a wordmark logo. Hiding is
 * only the look of the card: the signed credential keeps the issuer name, and without a logo the
 * card still names its issuer.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, imagePath: (app, file) => `/apps/${app}/img/${file}` }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

const LOGO = 'https://cloud.example/logo.png'

function fields(over = {}) {
	return {
		courseTitle: 'Arbeitsschutz 2026',
		recipientName: 'Alex Beispiel',
		courseElements: [],
		score: 90,
		threshold: 80,
		issuerName: 'Coca Cola',
		issuerLogo: LOGO,
		validFrom: '2026-06-27T10:00:00Z',
		validUntil: '',
		...over,
	}
}

let host, app

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.mount(host)
	await flush()
}

describe('CertificateCard: organisation name (GitHub #77)', () => {
	async function card(presentation, over = {}) {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		await mount(CertificateCard, { fields: fields(over), presentation, verificationId: 'x' })
	}

	it('shows the name beside a decorative logo by default', async () => {
		await card(null)
		expect(host.querySelector('[data-test="certificate-issuer"]').textContent).toBe('Coca Cola')
		const logo = host.querySelector('[data-test="certificate-logo"]')
		expect(logo.getAttribute('alt')).toBe('')
		expect(logo.getAttribute('aria-hidden')).toBe('true')
	})

	it('a wordmark logo stands alone and then names the issuer itself', async () => {
		await card({ show: { issuer: false } })
		expect(host.querySelector('[data-test="certificate-issuer"]')).toBeNull()
		const logo = host.querySelector('[data-test="certificate-logo"]')
		expect(logo.getAttribute('alt')).toBe('Coca Cola')
		expect(logo.hasAttribute('aria-hidden')).toBe(false)
	})

	it('keeps the name when there is no logo to stand for it', async () => {
		await card({ show: { issuer: false, logo: false } })
		expect(host.querySelector('[data-test="certificate-issuer"]').textContent).toBe('Coca Cola')
		await card({ show: { issuer: false } }, { issuerLogo: '' })
		expect(host.querySelector('[data-test="certificate-issuer"]').textContent).toBe('Coca Cola')
	})
})

describe('course settings: organisation name (GitHub #77)', () => {
	async function settings(modelValue, serverLayout, updates = []) {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockResolvedValueOnce({ data: serverLayout })
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		const { certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		await mount(CertLayoutSettings, {
			courseId: 7,
			courseTitle: 'Arbeitsschutz',
			modelValue: certLayoutFromServer(modelValue),
			'onUpdate:modelValue': (v) => updates.push(v),
		})
		return updates
	}

	it('names what an empty field gives and previews it', async () => {
		await settings({}, { issuer_name_fallback: 'Training Solutions LTD', issuer_name: 'Training Solutions LTD' })
		const input = host.querySelector('[data-test="cert-layout-issuer-name"]')
		expect(input.value).toBe('')
		expect(input.getAttribute('placeholder')).toBe('Training Solutions LTD')
		expect(document.getElementById(input.getAttribute('aria-describedby')).textContent).toContain('„Training Solutions LTD“')
		expect(host.querySelector('.cert-layout-preview .certificate-issuer').textContent).toBe('Training Solutions LTD')
	})

	it('the course name is previewed and sent as issuer_name', async () => {
		const updates = await settings({ issuer_name: 'Akademie Nord GmbH' }, { issuer_name_fallback: 'T-Solutions' })
		expect(host.querySelector('.cert-layout-preview .certificate-issuer').textContent).toBe('Akademie Nord GmbH')

		const input = host.querySelector('[data-test="cert-layout-issuer-name"]')
		input.value = 'Training Solutions LTD'
		input.dispatchEvent(new Event('input'))
		expect(updates.at(-1).issuer_name).toBe('Training Solutions LTD')
	})

	it('the switch hides the name in the preview only through show.issuer', async () => {
		const updates = await settings({}, { issuer_name_fallback: 'Coca Cola' })
		// NcCheckboxRadioSwitch puts the attributes on its input
		const toggle = host.querySelector('input[data-test="cert-layout-show-issuer"]')
		expect(toggle.checked).toBe(true)
		expect(document.getElementById(toggle.getAttribute('aria-describedby')).textContent).toContain('Prüfseite bleibt der Name')
		toggle.click()
		await flush()
		expect(updates.at(-1).show).toMatchObject({ issuer: false, logo: true })
	})

	it('a course from 5.13.0 (no issuer keys) keeps the name shown', async () => {
		const { certLayoutFromServer, certLayoutPayload } = await import('../../src/components/CourseTabVerwaltung.vue')
		const layout = certLayoutFromServer({ show: { logo: true, score: false } })
		expect(layout.show.issuer).toBe(true)
		expect(certLayoutPayload(layout)).toMatchObject({ issuer_name: '', show: { issuer: true, score: false } })
	})
})

describe('admin: the operator details say when their name is the issuer (GitHub #77)', () => {
	it('names the course entry and the Theming fallback, not "never this name"', async () => {
		const { default: OperatorSettings } = await import('../../src/components/OperatorSettings.vue')
		await mount(OperatorSettings, {})
		const hint = host.querySelector('.operator-settings__issuer-hint').textContent.replace(/\s+/g, ' ')
		expect(hint).toContain('steht als Aussteller auf neuen Zertifikaten')
		expect(hint).toContain('„Gestaltung des Zertifikats“')
		expect(hint).toContain('Bereits ausgestellte Zertifikate behalten ihren Namen.')
		expect(hint).not.toContain('nicht dieser Name')
	})
})
