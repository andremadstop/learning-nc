/**
 * GitHub #58 (and the #36 follow-up on the same page):
 *  - With the virtual assistant switched off, its section kept showing speech output, push-to-talk,
 *    bot sounds, voice language and the character picker, which then do nothing. The switch itself
 *    sat in the general list above the section.
 *  - Notifications (ReminderService), the FSRS statistics and the calm display are not VirtuProf
 *    settings: they must stay visible with the assistant off.
 *  - With gamification off (admin switch, gamification_enabled in /settings/personal) the page
 *    still showed level, XP, streak and badges and the daily-challenge switch.
 *  - The learning profile was described as "only VirtuProf" / "instructors see only aggregated
 *    patterns", while course/public profiles appear in the class register and the instructors see
 *    each person's exam target with its date.
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

const { api } = vi.hoisted(() => ({ api: { personal: {}, telos: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/settings/personal')) {
				return Promise.resolve({ data: api.personal })
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
vi.mock('@nextcloud/vue/components/NcSelect', () => stub('NcSelect', '<div class="select-stub" :id="id" />', { props: ['id'] }))
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

function statLabels() {
	return [...host.querySelectorAll('.profile-stat-label')].map((el) => el.textContent.trim())
}

beforeEach(() => {
	api.personal = {}
	api.telos = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('#58 VirtuProf section', () => {
	it('with the assistant off shows none of its settings, but keeps the general ones', async () => {
		api.personal = { virtuprof_enabled: 'no' }
		await mountSettings()
		const names = switchNames()
		expect(names).toContain('Virtual assistant')
		expect(names).not.toContain('Speech output')
		expect(names).not.toContain('Voice input (push-to-talk)')
		expect(names).not.toContain('Bot sounds')
		expect(host.querySelector('#virtuprof-voice-lang')).toBeNull()
		expect(host.querySelector('#virtuprof-skin')).toBeNull()
		expect(names).toContain('Notifications enabled')
		expect(names).toContain('Extended learning statistics')
		expect(names).toContain('Ruhige Darstellung (keine Avatar-Animationen)')
	})

	it('with the assistant on shows its settings', async () => {
		api.personal = { virtuprof_enabled: 'yes' }
		await mountSettings()
		const names = switchNames()
		expect(names).toContain('Speech output')
		expect(names).toContain('Bot sounds')
		expect(host.querySelector('#virtuprof-voice-lang')).not.toBeNull()
		expect(host.querySelector('#virtuprof-skin')).not.toBeNull()
	})

	it('puts the "Virtual assistant" switch inside the VirtuProf section, after its heading', async () => {
		await mountSettings()
		const heading = [...host.querySelectorAll('h3')].find((h) => h.textContent.includes('VirtuProf Settings'))
		const assistant = [...host.querySelectorAll('.switch-stub')].find((el) => el.textContent.includes('Virtual assistant'))
		expect(heading).toBeTruthy()
		expect(heading.compareDocumentPosition(assistant) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		const notifications = [...host.querySelectorAll('.switch-stub')].find((el) => el.textContent.includes('Notifications enabled'))
		expect(heading.compareDocumentPosition(notifications) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy()
	})

	it('a save with the assistant off still sends the hidden VirtuProf values unchanged', async () => {
		api.personal = { virtuprof_enabled: 'no' }
		await mountSettings()
		const save = [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Save')
		save.click()
		await settle()
		const prefs = axios.put.mock.calls.find(([url]) => url.endsWith('/api/virtuprof/preferences'))
		expect(prefs).toBeTruthy()
		expect(prefs[1]).toHaveProperty('ttsEnabled')
		expect(prefs[1]).toHaveProperty('skin')
	})
})

describe('#58/#36 gamification off on the personal settings page', () => {
	it('hides level, XP, streak, badges and the daily challenge', async () => {
		api.personal = { gamification_enabled: false }
		await mountSettings()
		const labels = statLabels()
		expect(labels).not.toContain('XP')
		expect(labels).not.toContain('Level')
		expect(labels).not.toContain('Streak')
		expect(labels).not.toContain('Badges')
		expect(labels).toContain('Mastered')
		expect(labels).toContain('Accuracy')
		expect(switchNames()).not.toContain('Daily Challenge enabled')
	})

	it('keeps them with gamification on (IT courses look as before)', async () => {
		api.personal = { gamification_enabled: true }
		await mountSettings()
		expect(statLabels()).toEqual(expect.arrayContaining(['Level', 'XP', 'Streak', 'Badges', 'Mastered', 'Accuracy']))
		expect(switchNames()).toContain('Daily Challenge enabled')
	})
})

describe('#58 learning profile texts', () => {
	it('does not claim the profile is VirtuProf-only or that instructors see only aggregates', async () => {
		await mountSettings()
		const text = host.textContent
		expect(text).not.toContain('lets instructors see only aggregated class-level patterns')
		expect(text).not.toContain('You + VirtuProf')
		expect(text).toContain('Klassenbuch')
		expect(text).toContain('Prüfungsziel mit Datum')
	})

	it('the private hint says the learner is then not in the class register', async () => {
		api.telos = { visibility: 'private' }
		await mountSettings()
		expect(host.querySelector('.field-hint').textContent).toContain('nicht im Klassenbuch')
	})

	it('the public hint does not promise server-wide visibility that does not exist', async () => {
		api.telos = { visibility: 'public' }
		await mountSettings()
		const hint = host.querySelector('.field-hint').textContent
		expect(hint).not.toContain('All users of this server')
		expect(hint).toContain('nur innerhalb deiner Kurse')
	})
})
