/**
 * GitHub #70: with the admin's AI off the personal settings still offered speech output,
 * push-to-talk, the voice language and the character picker (all parts of the AI chat), and an
 * "AI consent" section although there is nothing to agree to. A consent still on record must stay
 * revocable whatever the AI switch says.
 *
 * Mounts the real PersonalSettings.vue with stubbed Nextcloud components.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

const { api } = vi.hoisted(() => ({ api: { personal: {}, telos: {}, virtuprof: {}, consent: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: api.personal })
			}
			if (url.endsWith('/api/virtuprof/state')) {
				return Promise.resolve({ data: api.virtuprof })
			}
			if (url.endsWith('/api/profile/telos/consent')) {
				return Promise.resolve({ data: api.consent })
			}
			if (url.endsWith('/api/profile/telos')) {
				return Promise.resolve({ data: api.telos })
			}
			if (url.endsWith('/api/v1/user/state')) {
				return Promise.resolve({ data: { xp: { level: 7, total_xp: 4321 }, streak: { current_streak: 3 }, badges: [] } })
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
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<input class="select-stub" :id="inputId" />', { props: ['inputId'] }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => stub('NcCheckboxRadioSwitch',
	'<span class="switch-stub"><input type="checkbox" :checked="modelValue"><span class="switch-stub__label"><slot /></span></span>',
	{ props: ['modelValue', 'type', 'description', 'disabled'], emits: ['update:modelValue'] }))
vi.mock('../../src/components/PrivacyInfo.vue', () => stub('PrivacyInfo', '<div />'))
vi.mock('../../src/components/SkinOptionPreview.vue', () => stub('SkinOptionPreview', '<span />'))

import axios from '@nextcloud/axios'
import PersonalSettings from '../../src/components/PersonalSettings.vue'

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
	app = createApp(PersonalSettings, { userRole: 'student' })
	app.use(pinia)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await settle()
}

function switchNames() {
	return [...host.querySelectorAll('.switch-stub__label')].map((el) => el.textContent.trim())
}


function headings() {
	return [...host.querySelectorAll('h3')].map((h) => h.textContent.trim())
}

const AI_OFF_STATUS = { valid: false, ai_enabled: false, recipient: null, ai_consent_version: null, current_version: '3.0' }

beforeEach(() => {
	api.personal = { virtuprof_enabled: 'yes' }
	api.telos = {}
	api.virtuprof = {}
	api.consent = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('#70 VirtuProf voice and character with AI off', () => {
	it('hides speech output, push-to-talk, voice language and character, keeps the assistant switch and bot sounds', async () => {
		api.virtuprof = { ai_enabled: false }
		await mountSettings()
		const names = switchNames()
		expect(names).toContain('Virtual assistant')
		expect(names).toContain('Bot sounds')
		expect(names).not.toContain('Speech output')
		expect(names).not.toContain('Voice input (push-to-talk)')
		expect(host.querySelector('#virtuprof-voice-lang')).toBeNull()
		expect(host.querySelector('#virtuprof-skin')).toBeNull()
	})

	it('shows them with AI on (IT courses look as before)', async () => {
		api.virtuprof = { ai_enabled: true }
		await mountSettings()
		const names = switchNames()
		expect(names).toContain('Speech output')
		expect(names).toContain('Voice input (push-to-talk)')
		expect(host.querySelector('#virtuprof-voice-lang')).not.toBeNull()
		expect(host.querySelector('#virtuprof-skin')).not.toBeNull()
	})

	it('a save with AI off still sends the hidden voice and character values', async () => {
		api.virtuprof = { ai_enabled: false, tts_enabled: true, skin: 'nova', voice_lang: 'en-US' }
		await mountSettings()
		const save = [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save')
		save.click()
		await settle()
		const prefs = axios.put.mock.calls.find(([url]) => url.endsWith('/api/virtuprof/preferences'))
		expect(prefs).toBeTruthy()
		expect(prefs[1]).toMatchObject({ ttsEnabled: true, skin: 'nova', voiceLang: 'en-US' })
	})
})

describe('#70 AI consent section with AI off', () => {
	it('is left out when AI is off and no consent is on record', async () => {
		api.consent = AI_OFF_STATUS
		await mountSettings()
		expect(headings()).not.toContain('KI-Einwilligung')
		expect(host.textContent).not.toContain('KI-Einwilligung widerrufen')
	})

	it('stays with its revoke button when a consent is still stored', async () => {
		api.consent = { ...AI_OFF_STATUS, ai_consent_version: '3.0' }
		await mountSettings()
		expect(headings()).toContain('KI-Einwilligung')
		expect(host.textContent).toContain('KI-Einwilligung widerrufen')
	})

	it('stays when the status cannot be read', async () => {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/api/profile/telos/consent')) return Promise.reject(new Error('down'))
			return Promise.resolve({ data: url.endsWith('/api/settings/personal') ? api.personal : {} })
		})
		await mountSettings()
		expect(headings()).toContain('KI-Einwilligung')
		expect(host.textContent).toContain('KI-Einwilligung widerrufen')
	})

	it('stays with AI on', async () => {
		api.consent = { valid: false, ai_enabled: true, recipient: { name: 'Gemini' }, ai_consent_version: null, current_version: '3.0' }
		await mountSettings()
		expect(headings()).toContain('KI-Einwilligung')
	})
})
