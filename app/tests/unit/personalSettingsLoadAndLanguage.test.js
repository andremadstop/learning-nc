/**
 * PersonalSettings: after a failed GET the form holds its defaults, so Save must not be offered
 * (it PUT every field and overwrote the real settings); and the 'UI Language' select, whose value
 * nothing ever read, is gone.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { failSettings: false } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/settings/personal')) {
				return api.failSettings
					? Promise.reject(Object.assign(new Error('429'), { response: { status: 429 } }))
					: Promise.resolve({ data: { daily_challenge: 'no', ui_language: 'en' } })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
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
const { stub } = vi.hoisted(() => ({
	stub: (name, template, extra = {}) => ({ default: { name, template, ...extra } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', '<button type="button"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon', '<span />'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard', '<div><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<div />'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch',
	'<input type="checkbox" class="switch-stub" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot />',
	{ props: ['modelValue', 'type'], emits: ['update:modelValue'] }))
vi.mock('../../src/components/PrivacyInfo.vue', () => stub('PrivacyInfo', '<div />'))
vi.mock('../../src/components/SkinOptionPreview.vue', () => stub('SkinOptionPreview', '<span />'))

import PersonalSettings from '../../src/components/PersonalSettings.vue'
import axios from '@nextcloud/axios'

let host, app

async function settle() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountSettings() {
	const pinia = createPinia()
	setActivePinia(pinia)
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(PersonalSettings)
	app.use(pinia)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	const vm = app.mount(host)
	await settle()
	return vm
}

function saveButton() {
	return [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save')
}

beforeEach(() => {
	api.failSettings = false
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('PersonalSettings: a failed load does not overwrite the settings (audit #104)', () => {
	it('disables Save and offers a retry instead of writing the defaults', async () => {
		api.failSettings = true
		const vm = await mountSettings()

		expect(host.textContent).toContain('Failed to load settings')
		expect(saveButton().disabled).toBe(true)
		await vm.save()
		expect(axios.put).not.toHaveBeenCalled()

		// The retry button is rendered inside the error note and reloads the settings.
		const retry = host.querySelector('.retry-load')
		expect(retry).not.toBeNull()
		expect(retry.textContent).toContain('Retry')
		const getsBefore = axios.get.mock.calls.filter(([url]) => url.endsWith('/api/settings/personal')).length
		api.failSettings = false
		retry.click()
		await settle()
		const getsAfter = axios.get.mock.calls.filter(([url]) => url.endsWith('/api/settings/personal')).length
		expect(getsAfter).toBe(getsBefore + 1)
		expect(saveButton().disabled).toBe(false)
		expect(host.querySelector('.retry-load')).toBeNull()
	})
})

describe('PersonalSettings: no UI language field (audit #12, #61, #218)', () => {
	it('shows no UI language select and does not send ui_language', async () => {
		const vm = await mountSettings()

		expect(host.querySelector('#ui-language')).toBeNull()
		await vm.save()
		const call = axios.put.mock.calls.find(([url]) => url.endsWith('/api/settings/personal'))
		expect(call).toBeTruthy()
		expect(call[1]).not.toHaveProperty('ui_language')
		expect(call[1].daily_challenge).toBe('no')
	})
})
