/**
 * Fresh-eyes C3 (#36): the learning profile listed "Fire safety · 50 %, Data protection · 50 %" as
 * the weakest topics of a learner who had never answered a question. The server marks such pools
 * answered: false (neutral 50 % error rate); the list leaves them out.
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

const { api } = vi.hoisted(() => ({ api: { personal: {}, telos: {}, virtuprof: {}, consent: {}, profile: {} } }))
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
			if (url.endsWith('/api/profile')) {
				return Promise.resolve({ data: api.profile })
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

function topics() {
	return [...host.querySelectorAll('.weak-topics-list li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim())
}

beforeEach(() => {
	api.personal = { virtuprof_enabled: 'yes' }
	api.telos = {}
	api.virtuprof = {}
	api.consent = {}
	api.profile = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('fresh-eyes C3: weakest topics', () => {
	it('leaves out pools nobody answered (neutral 50 %)', async () => {
		api.profile = { weakest_topics: [
			{ pool_id: 1, pool_name: 'Fire safety', error_rate: 50, answered: false },
			{ pool_id: 2, pool_name: 'Data protection', error_rate: 50, answered: false },
		] }
		await mountSettings()
		expect(topics()).toEqual([])
		expect(host.textContent).not.toContain('Current weakest topics')
	})

	it('keeps answered pools, also next to unanswered ones', async () => {
		api.profile = { weakest_topics: [
			{ pool_id: 3, pool_name: 'Subnetting', error_rate: 62.5, answered: true },
			{ pool_id: 1, pool_name: 'Fire safety', error_rate: 50, answered: false },
		] }
		await mountSettings()
		expect(topics()).toEqual(['Subnetting · 62.5%'])
	})

	it('keeps pools of a payload cached before the flag existed', async () => {
		api.profile = { weakest_topics: [{ pool_id: 3, pool_name: 'Subnetting', error_rate: 40 }] }
		await mountSettings()
		expect(topics()).toEqual(['Subnetting · 40%'])
	})
})
