import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	getLanguage: () => 'de',
	isRTL: () => false,
}))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: {
		name: 'AccessibleDialog',
		props: ['name'],
		emits: ['closing'],
		template: '<div role="dialog"><h2>{{ name }}</h2><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
}))

import axios from '@nextcloud/axios'
import consent from '../../data/ai-consent.json'
import OnboardingRedesign from '../../src/components/OnboardingRedesign.vue'
import OnbProfileTiles from '../../src/components/onboarding/OnbProfileTiles.vue'
import { useOnboardingStore } from '../../src/stores/onboardingStore.js'

const URL = '/apps/learning/api/profile/telos/consent'
const GEMINI = { name: 'Google Gemini', region: 'us', processing_local: false, intermediary: false, fingerprint: 'fp-gemini' }
const status = (over = {}) => ({ valid: false, reason: 'never', current_version: '3.0', ai_enabled: true, recipient: GEMINI, previous_recipient_name: null, ...over })

async function flush() {
	for (let i = 0; i < 8; i++) await Promise.resolve()
	await nextTick()
}

describe('onboarding store: the consent choice is never carried over from the browser', () => {
	beforeEach(() => {
		window.localStorage.clear()
		setActivePinia(createPinia())
	})

	it('discards an aiConsent "yes" that an older version stored in localStorage', () => {
		window.localStorage.setItem('learning:onboarding-progress:user', JSON.stringify({
			step: 'profile',
			role: 'student',
			profileChoices: { goal: 'career', intensity: 'regular', aiConsent: 'yes' },
		}))
		const store = useOnboardingStore()
		store.hydrate()
		expect(store.profileChoices.goal).toBe('career')
		expect(store.profileChoices.intensity).toBe('regular')
		expect(store.profileChoices.aiConsent).toBeNull()
	})

	it('does not persist aiConsent either (the server is the only record)', () => {
		const store = useOnboardingStore()
		store.setProfileChoice('aiConsent', 'yes')
		const stored = JSON.parse(window.localStorage.getItem('learning:onboarding-progress:user'))
		expect(stored.profileChoices.aiConsent).toBeNull()
		expect(store.profileChoices.aiConsent).toBe('yes')
	})
})

describe('OnboardingRedesign no longer writes the consent', () => {
	it('has no saveAiConsent step and finalize never touches the consent route', async () => {
		expect(OnboardingRedesign.methods.saveAiConsent).toBeUndefined()
		axios.post.mockClear()
		axios.get.mockClear()
		vi.useFakeTimers()
		const ctx = {
			store: { skipped: false, selectedStarterPoolId: null, profileChoices: { goal: null, intensity: null, aiConsent: 'yes' }, role: 'student' },
			visible: true,
			getUid: () => 'alex',
			$emit: vi.fn(),
			runFinalizeStep: OnboardingRedesign.methods.runFinalizeStep,
			saveTelosProfile: vi.fn(() => Promise.resolve()),
			importStarterPool: vi.fn(() => Promise.resolve()),
			acknowledgeOnboarding: vi.fn(() => Promise.resolve()),
		}
		await OnboardingRedesign.methods.finalize.call(ctx)
		vi.useRealTimers()
		const urls = [...axios.post.mock.calls, ...axios.get.mock.calls].map((c) => c[0])
		expect(urls).not.toContain(URL)
	})
})

describe('OnbProfileTiles consent tile', () => {
	let host, app, pinia, store

	function mountTiles() {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useOnboardingStore()
		store.role = 'student'
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(OnbProfileTiles) })
		app.use(pinia)
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
	}

	const consentTiles = () => [...host.querySelectorAll('.onb-tile-group')].at(-1).querySelectorAll('button.onb-tile')
	const acceptButton = () => host.querySelector('.actions button:last-child')

	beforeEach(() => {
		document.body.innerHTML = ''
		window.localStorage.clear()
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
		axios.post.mockResolvedValue({ data: { status: 'ok', valid: true } })
		axios.delete.mockResolvedValue({ data: { status: 'ok', valid: false } })
	})
	afterEach(() => { app?.unmount(); app = null; host?.remove() })

	it('names the recipient on the tile and writes nothing until the dialog is accepted', async () => {
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()

		expect(consentTiles()[0].textContent).toContain('Details ansehen & zustimmen')
		expect(consentTiles()[0].textContent).toContain('Google Gemini')
		expect(store.profileChoices.aiConsent).toBeNull()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('opens the dialog from the tile; accepting stores the consent with source onboarding', async () => {
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()

		consentTiles()[0].click()
		await flush()
		expect(host.querySelector('[role="dialog"]')).not.toBeNull()
		expect(store.profileChoices.aiConsent).toBeNull()

		acceptButton().click()
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(URL, { version: consent.version, recipient_fingerprint: 'fp-gemini', source: 'onboarding' })
		expect(store.profileChoices.aiConsent).toBe('yes')
		expect(host.querySelector('[role="dialog"]')).toBeNull()
	})

	it('cancelling the dialog leaves the choice open and writes nothing', async () => {
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()
		consentTiles()[0].click()
		await flush()
		host.querySelector('.actions button:first-child').click()
		await flush()
		expect(store.profileChoices.aiConsent).toBeNull()
		expect(axios.post).not.toHaveBeenCalled()
		expect(host.querySelector('[role="dialog"]')).toBeNull()
	})

	it('ja then nein: choosing "without AI" after consenting revokes the consent on the server', async () => {
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()
		consentTiles()[0].click()
		await flush()
		acceptButton().click()
		await flush()
		expect(store.profileChoices.aiConsent).toBe('yes')

		consentTiles()[1].click()
		await flush()

		expect(axios.delete).toHaveBeenCalledWith(URL)
		expect(store.profileChoices.aiConsent).toBe('no')
	})

	it('409 on accept (admin switched the recipient): nothing is chosen, the new recipient is shown, a second accept stores it', async () => {
		const OLLAMA = { name: 'Ollama', region: 'local', processing_local: true, intermediary: false, fingerprint: 'fp-ollama' }
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()
		consentTiles()[0].click()
		await flush()

		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'recipient_changed', recipient: OLLAMA } } })
		axios.get.mockResolvedValue({ data: status({ reason: 'recipient_changed', recipient: OLLAMA, previous_recipient_name: 'Google Gemini' }) })
		acceptButton().click()
		await flush()

		expect(store.profileChoices.aiConsent).toBeNull()
		expect(host.textContent).toContain('Die Daten gehen an: Ollama')
		expect(host.querySelector('[role="alert"]')).not.toBeNull()

		acceptButton().click()
		await flush()
		expect(axios.post.mock.calls[1][1]).toEqual({ version: consent.version, recipient_fingerprint: 'fp-ollama', source: 'onboarding' })
		expect(store.profileChoices.aiConsent).toBe('yes')
	})

	it('reload: after a reload the choice starts empty and the server status decides', async () => {
		window.localStorage.setItem('learning:onboarding-progress:user', JSON.stringify({ step: 'profile', role: 'student', profileChoices: { goal: 'career', intensity: 'regular', aiConsent: 'yes' } }))
		axios.get.mockResolvedValue({ data: status() })
		pinia = createPinia()
		setActivePinia(pinia)
		store = useOnboardingStore()
		store.hydrate()
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(OnbProfileTiles) })
		app.use(pinia)
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		expect(store.profileChoices.aiConsent).toBeNull()
		expect(store.profileChoices.goal).toBe('career')
	})

	it('a failed revocation keeps the choice at yes (the consent is still on record)', async () => {
		axios.get.mockResolvedValue({ data: status({ valid: true }) })
		axios.delete.mockRejectedValueOnce(new Error('500'))
		mountTiles()
		await flush()
		expect(store.profileChoices.aiConsent).toBe('yes')

		consentTiles()[1].click()
		await flush()

		expect(store.profileChoices.aiConsent).toBe('yes')
		expect(host.querySelector('[role="alert"]')).not.toBeNull()
	})

	it('"without AI" without any consent on record revokes nothing', async () => {
		axios.get.mockResolvedValue({ data: status() })
		mountTiles()
		await flush()
		consentTiles()[1].click()
		await flush()
		expect(axios.delete).not.toHaveBeenCalled()
		expect(store.profileChoices.aiConsent).toBe('no')
	})

	// Review finding (Codex): the revocation the user asked for must not depend on a status read
	// that failed — unknown status means "maybe on record", so DELETE is sent (fail closed).
	it('status GET failed, then "without AI": the consent is revoked anyway', async () => {
		axios.get.mockRejectedValue(new Error('network'))
		mountTiles()
		await flush()
		consentTiles()[1].click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith(URL)
		expect(store.profileChoices.aiConsent).toBe('no')
	})

	it('status GET failed and the revocation fails too: the choice stays open with an error', async () => {
		axios.get.mockRejectedValue(new Error('network'))
		axios.delete.mockRejectedValue(new Error('network'))
		mountTiles()
		await flush()
		consentTiles()[1].click()
		await flush()
		expect(store.profileChoices.aiConsent).toBeNull()
		expect(host.querySelector('[role="alert"]')).not.toBeNull()
	})

	it('a stored consent that is not valid right now (recipient changed) is revoked by "without AI"', async () => {
		axios.get.mockResolvedValue({ data: status({ reason: 'recipient_changed', ai_consent_version: '3.0', previous_recipient_name: 'Ollama' }) })
		mountTiles()
		await flush()
		consentTiles()[1].click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith(URL)
		expect(store.profileChoices.aiConsent).toBe('no')
	})

	it('a consent that is already valid on the server shows as chosen', async () => {
		axios.get.mockResolvedValue({ data: status({ valid: true }) })
		mountTiles()
		await flush()
		expect(store.profileChoices.aiConsent).toBe('yes')
	})

	it('AI switched off: no consent tile, the choice is "without AI"', async () => {
		axios.get.mockResolvedValue({ data: status({ recipient: null, ai_enabled: false }) })
		mountTiles()
		await flush()
		expect(consentTiles()).toHaveLength(1)
		expect(store.profileChoices.aiConsent).toBe('no')
	})
})
