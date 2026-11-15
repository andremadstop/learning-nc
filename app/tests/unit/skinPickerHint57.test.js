/**
 * GitHub #57: "New skins available in settings" was shown on the settings page itself, to every
 * new user for a week and also with the virtual assistant off, and its close button never rendered
 * (NcNoteCard in @nextcloud/vue 9 has no show-action/action-text props).
 *
 * Mounts the real PersonalSettings.vue: the hint shows only with the assistant on, leads to the
 * character picker, and closes.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { personal: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => Promise.resolve({ data: url.endsWith('/api/settings/personal') ? api.personal : {} })),
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
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton', '<button type="button" @click="$emit(\'click\')"><slot /></button>', { emits: ['click'] }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon', '<span />'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard', '<div class="note-card"><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<div><input class="skin-select-input"></div>'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch', '<span><slot /></span>'))
vi.mock('../../src/components/PrivacyInfo.vue', () => stub('PrivacyInfo', '<div />'))
vi.mock('../../src/components/SkinOptionPreview.vue', () => stub('SkinOptionPreview', '<span />'))

import PersonalSettings from '../../src/components/PersonalSettings.vue'

const HINT_TEXT = 'Neu: VirtuProf kann dich auch als anderer Charakter begleiten.'
let host, app

async function settle() {
	for (let i = 0; i < 8; i++) {
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

function hint() {
	return [...host.querySelectorAll('.skin-picker-hint')][0] || null
}

beforeEach(() => {
	localStorage.clear()
	api.personal = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('#57 skin hint on the personal settings page', () => {
	it('is not shown with the virtual assistant off', async () => {
		api.personal = { virtuprof_enabled: 'no' }
		await mountSettings()
		expect(host.textContent).not.toContain(HINT_TEXT)
		expect(host.textContent).not.toContain('Neue Skins verfügbar in den Einstellungen')
	})

	it('is shown with the assistant on, with a way to the picker and a working close button', async () => {
		await mountSettings()
		expect(hint()).not.toBeNull()
		expect(hint().textContent).toContain(HINT_TEXT)
		const close = [...hint().querySelectorAll('button')].find((b) => b.textContent.includes('Hinweis schließen'))
		expect(close).toBeTruthy()
		close.click()
		await settle()
		expect(hint()).toBeNull()
		expect(localStorage.getItem('learning-hint-skin-picker-v440-dismissed')).toBe('1')
	})

	it('"Zur Charakter-Auswahl" scrolls to the picker and focuses it', async () => {
		const scroll = vi.fn()
		Element.prototype.scrollIntoView = scroll
		await mountSettings()
		const go = [...hint().querySelectorAll('button')].find((b) => b.textContent.includes('Zur Charakter-Auswahl'))
		go.click()
		await settle()
		expect(scroll).toHaveBeenCalled()
		expect(document.activeElement?.classList.contains('skin-select-input')).toBe(true)
		expect(hint()).toBeNull()
	})
})
