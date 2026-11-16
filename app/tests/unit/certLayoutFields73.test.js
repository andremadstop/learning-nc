/**
 * GitHub #73: the fields of "Certificate design" stood beside their labels. The column layout of
 * the course rules (.cert-config-field) lives in CourseTabVerwaltung's scoped style, which does not
 * reach into CertLayoutSettings, and the signer inputs had no field wrapper at all. Every text
 * field of the section now sits in its own field, label first, with the column rule in the
 * component's own style.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, imagePath: (app, file) => `/apps/${app}/img/${file}` }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/CertLayoutSettings.vue'), 'utf8')

/** The rules of the component's own <style> block, selector → declarations. */
function ownRules() {
	const style = SOURCE.slice(SOURCE.indexOf('<style'))
	const rules = {}
	for (const m of style.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^}]*)\}/g)) {
		for (const selector of m[1].split(',').map((s) => s.trim())) {
			rules[selector] = (rules[selector] || '') + m[2]
		}
	}
	return rules
}

describe('certificate design: label above, input full width below (GitHub #73)', () => {
	let app
	let host

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	async function mount() {
		const { default: CertLayoutSettings } = await import('../../src/components/CertLayoutSettings.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CertLayoutSettings, { courseId: 7, modelValue: { show: {} } }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await nextTick()
	}

	it('every text field has its own field wrapper with the label first and the input right below', async () => {
		await mount()
		const labels = [...host.querySelectorAll('.cert-layout label[for]')]
		expect(labels.length).toBeGreaterThanOrEqual(3)
		for (const label of labels) {
			const control = host.querySelector('#' + label.getAttribute('for'))
			expect(control, label.textContent).not.toBeNull()
			const field = label.parentElement
			expect(field.classList.contains('cert-config-field'), label.textContent).toBe(true)
			expect(field.firstElementChild).toBe(label)
			expect(label.nextElementSibling).toBe(control)
		}
	})

	it('the component itself lays a field out as a column; the parent\'s scoped rule cannot reach it', () => {
		const rules = ownRules()
		expect(rules['.cert-config-field']).toMatch(/display:\s*flex/)
		expect(rules['.cert-config-field']).toMatch(/flex-direction:\s*column/)
		expect(rules['.cert-layout-text']).toMatch(/width:\s*100%/)
		// the hints look like the hints of the other course rules (same scoped-style gap)
		expect(rules['.mode-config-hint']).toMatch(/color:\s*var\(--color-text-maxcontrast\)/)
	})
})
