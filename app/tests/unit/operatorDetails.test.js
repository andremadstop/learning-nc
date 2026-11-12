import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'
import fs from 'node:fs'
import path from 'node:path'

vi.hoisted(() => {
	globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k] ?? '')) : text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="nc-note"><slot /></div>' } }))

import axios from '@nextcloud/axios'
import ImpressumPage from '../../src/components/ImpressumPage.vue'
import PrivacyInfo from '../../src/components/PrivacyInfo.vue'
import OperatorSettings from '../../src/components/OperatorSettings.vue'
import PersonalSettings from '../../src/components/PersonalSettings.vue'
import { parseOperator } from '../../src/utils/operatorInfo.js'

/**
 * Audit 2026-11 (high): the operator (provider in the imprint, controller in the privacy statement)
 * was hard-coded in privacy-info.json and shipped with every release, so every installation named
 * the app author's company. It is now an admin setting; without one the pages show a neutral hint.
 */

const OPERATOR = {
	name: 'Musterschule e. V.',
	address: 'Schulweg 1\n12345 Musterstadt',
	register: 'Amtsgericht Musterstadt, VR 123',
	email: 'kontakt@musterschule.example',
	content_responsible: 'Erika Muster',
}
const HINT = 'The operator of this platform has not entered any operator details yet. Please contact the administrators of this Nextcloud.'

let host = null
let app = null
function render(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(component, props)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	return host
}
afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

describe('the shipped data names no operator', () => {
	const file = path.resolve(__dirname, '../../data/privacy-info.json')
	const raw = fs.readFileSync(file, 'utf8')

	it('privacy-info.json has no responsible block', () => {
		expect(JSON.parse(raw)).not.toHaveProperty('responsible')
	})

	it('privacy-info.json carries none of the former operator details', () => {
		for (const needle of ['Souverain', 'HRB 42359', 'Geschwister-Scholl', 'souverain-systems.de']) {
			expect(raw).not.toContain(needle)
		}
	})
})

describe('parseOperator', () => {
	it('accepts a complete entry', () => {
		expect(parseOperator(OPERATOR)).toEqual(OPERATOR)
	})

	it('a missing required field means no operator', () => {
		expect(parseOperator({ ...OPERATOR, email: '' })).toBeNull()
		expect(parseOperator({ ...OPERATOR, address: undefined })).toBeNull()
	})

	it('null, arrays and strings mean no operator', () => {
		expect(parseOperator(null)).toBeNull()
		expect(parseOperator([])).toBeNull()
		expect(parseOperator('Musterschule')).toBeNull()
	})

	it('drops unknown keys and non-string values', () => {
		expect(parseOperator({ ...OPERATOR, register: 42, extra: 'x' })).toEqual({ ...OPERATOR, register: '' })
	})
})

describe('ImpressumPage', () => {
	it('shows the configured operator', () => {
		const el = render(ImpressumPage, { operator: OPERATOR })
		const text = el.textContent

		expect(text).toContain('Musterschule e. V.')
		expect(text).toContain('Schulweg 1')
		expect(text).toContain('Amtsgericht Musterstadt, VR 123')
		expect(text).toContain('Erika Muster')
		expect(el.querySelector('a[href="mailto:kontakt@musterschule.example"]')).not.toBeNull()
		expect(text).not.toContain(HINT)
	})

	it('names the operator as responsible for content when nobody else is entered', () => {
		const el = render(ImpressumPage, { operator: { ...OPERATOR, content_responsible: '' } })
		const cards = [...el.querySelectorAll('.legal-card')]
		const content = cards.find(card => card.querySelector('h2')?.textContent === 'Verantwortlich für Inhalte')

		expect(content.textContent).toContain('Musterschule e. V.')
	})

	it('without an operator shows the hint and no operator block', () => {
		const el = render(ImpressumPage, { operator: null })
		const text = el.textContent

		expect(text).toContain(HINT)
		expect(text).not.toContain('Anbieter')
		expect(el.querySelector('a[href^="mailto:"]')).toBeNull()
		expect(text).not.toMatch(/Souverain|HRB 42359|Geschwister-Scholl/)
	})
})

describe('PrivacyInfo', () => {
	it('names the configured operator as controller', () => {
		const el = render(PrivacyInfo, { operator: OPERATOR, locale: 'de' })
		const block = el.querySelector('.privacy-info__responsible')

		expect(block.textContent).toContain('Musterschule e. V.')
		expect(block.textContent).toContain('12345 Musterstadt')
		expect(block.querySelector('a[href="mailto:kontakt@musterschule.example"]')).not.toBeNull()
	})

	it('without an operator shows the hint and no mail link', () => {
		const el = render(PrivacyInfo, { operator: null, locale: 'de' })
		const block = el.querySelector('.privacy-info__responsible')

		expect(block.textContent).toContain(HINT)
		expect(block.querySelector('a')).toBeNull()
		expect(el.textContent).not.toMatch(/Souverain|HRB 42359|Geschwister-Scholl/)
	})
})

describe('PersonalSettings reads the operator for its privacy section', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('takes the operator from the public endpoint', async () => {
		axios.get.mockResolvedValue({ data: { operator: OPERATOR } })
		const ctx = { operator: null }

		await PersonalSettings.methods.loadOperator.call(ctx)

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/legal/operator')
		expect(ctx.operator).toEqual(OPERATOR)
	})

	it('keeps no operator when the endpoint fails or has none', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const ctx = { operator: null }
		await PersonalSettings.methods.loadOperator.call(ctx)
		expect(ctx.operator).toBeNull()

		axios.get.mockResolvedValue({ data: { operator: null } })
		await PersonalSettings.methods.loadOperator.call(ctx)
		expect(ctx.operator).toBeNull()
	})
})

function operatorSettingsInstance(form) {
	const ctx = { ...OperatorSettings.data(), form }
	for (const [name, fn] of Object.entries(OperatorSettings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('OperatorSettings (admin)', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
	})

	it('loads the stored entry into the form', async () => {
		axios.get.mockResolvedValue({ data: { operator: OPERATOR, configured: true } })
		const el = render(OperatorSettings)
		await vi.waitFor(() => expect(el.querySelector('#learning-operator-name').value).toBe('Musterschule e. V.'))

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/settings/admin/operator')
		expect(el.querySelector('#learning-operator-address').value).toBe(OPERATOR.address)
	})

	it('sends every field and reports success', async () => {
		axios.get.mockResolvedValue({ data: { operator: { name: '', address: '', register: '', email: '', content_responsible: '' }, configured: false } })
		axios.put.mockResolvedValue({ data: { operator: OPERATOR, configured: true } })
		const ctx = operatorSettingsInstance({ ...OPERATOR })

		await OperatorSettings.methods.save.call(ctx)

		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/settings/admin/operator', OPERATOR)
		expect(ctx.saved).toBe(true)
		expect(ctx.configured).toBe(true)
		expect(ctx.fieldErrors).toEqual({})
	})

	it('shows the fields the server refused', async () => {
		axios.put.mockRejectedValue({ response: { status: 400, data: { error: 'invalid_operator', fields: { address: 'required', email: 'invalid' } } } })
		const ctx = operatorSettingsInstance({ ...OPERATOR, address: '', email: 'x' })

		await OperatorSettings.methods.save.call(ctx)

		expect(ctx.saved).toBe(false)
		expect(ctx.fieldErrors).toEqual({ address: 'required', email: 'invalid' })
		expect(ctx.error).not.toBe('')
	})
})

describe('page entries pass the server-rendered operator through', () => {
	it('impressum-page.js mounts the imprint with the operator from data-operator', async () => {
		const node = document.createElement('div')
		node.id = 'learning-imprint-page'
		node.dataset.operator = JSON.stringify(OPERATOR)
		document.body.appendChild(node)
		await import('../../src/impressum-page.js')
		await nextTick()

		expect(document.getElementById('learning-imprint-page').textContent).toContain('Musterschule e. V.')
		node.remove()
	})

	it('privacy-page.js shows the hint when data-operator is null', async () => {
		const node = document.createElement('div')
		node.id = 'learning-privacy-page'
		node.dataset.operator = 'null'
		node.dataset.aiNotice = 'null'
		document.body.appendChild(node)
		await import('../../src/privacy-page.js')
		await nextTick()

		const text = document.getElementById('learning-privacy-page').textContent
		expect(text).toContain(HINT)
		expect(text).not.toContain('Musterschule')
		node.remove()
	})
})
