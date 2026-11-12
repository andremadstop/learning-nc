/**
 * Audit 2026-11 (S29): the switch labelled "Ruhige Darstellung (keine Avatar-Animationen)" was bound
 * straight to form.animationsEnabled. For a new account (animations on) the "calm display" switch
 * showed ON while avatars kept moving, and switching it OFF was what stopped them — the opposite
 * of the label, on an accessibility setting.
 *
 * Mounts the real PersonalSettings.vue with stubbed Nextcloud components and reads the switch
 * that sits under the calm-display label.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { animations: 'yes' } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: { animations_enabled: api.animations } })
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
import { useA11yStore } from '../../src/stores/a11yStore.js'

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
	app.mount(host)
	await settle()
}

function calmSwitch() {
	const row = host.querySelector('.lnc-a11y-toggle')
	expect(row).not.toBeNull()
	expect(row.textContent).toContain('Ruhige Darstellung')
	return row.querySelector('.switch-stub')
}

beforeEach(() => {
	api.animations = 'yes'
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('PersonalSettings: calm display switch', () => {
	it('is OFF while animations run (the default)', async () => {
		await mountSettings()
		expect(useA11yStore().animationsEnabled).toBe(true)
		expect(calmSwitch().checked).toBe(false)
	})

	it('is ON when animations are switched off on the server', async () => {
		api.animations = 'no'
		await mountSettings()
		expect(calmSwitch().checked).toBe(true)
	})

	it('switching it ON stops the animations', async () => {
		await mountSettings()
		const input = calmSwitch()
		input.checked = true
		input.dispatchEvent(new Event('change'))
		await settle()
		expect(useA11yStore().animationsEnabled).toBe(false)
		expect(calmSwitch().checked).toBe(true)
	})
})
