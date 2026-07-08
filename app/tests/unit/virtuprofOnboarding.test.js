import { describe, it, expect, vi, beforeEach } from 'vitest'

// Charakterisierungs-Netz für den Telos-Onboarding-/Journey-Flow von VirtuProf.
// Gebaut VOR der Mixin-Extraktion (Phase A2, Mixin 9). Muss danach OHNE Änderung grün
// bleiben — das ist der Beweis, dass die Extraktion verhaltensneutral war.
//
// WICHTIG (advisor / A2.0-Muster): Zugriff location-agnostisch über die GEMERGTEN
// Options (Komponente + Mixins), damit der Test nicht bricht, sobald die Methoden in
// ein Mixin wandern (Vue merged Mixins erst zur Instanziierung, nicht ins Options-Objekt).

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'

function mergedOptions(comp) {
	const mixins = comp.mixins || []
	const data = Object.assign({}, ...mixins.map(m => (m.data ? m.data() : {})), comp.data ? comp.data() : {})
	const methods = Object.assign({}, ...mixins.map(m => m.methods || {}), comp.methods || {})
	return { data, methods }
}

function createInstance(overrides = {}) {
	const { data, methods } = mergedOptions(VirtuProf)
	const instance = { ...data, $emit: vi.fn(), $refs: {}, ...overrides }
	for (const [name, fn] of Object.entries(methods)) {
		instance[name] = fn.bind(instance)
	}
	// Zentrale Cross-Cutter, die im Component-Kern bleiben, für Isolation stubben:
	instance.applyReaction = vi.fn()
	instance.saveVirtuProfPreferences = vi.fn(() => Promise.resolve())
	instance.vt = (key) => key
	return instance
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('startJourney / Preset-Auswahl', () => {
	it('startJourney aktiviert Onboarding, setzt Preset-Auswahl-View, resettet Journey', () => {
		const instance = createInstance({ telosSaved: true, journeyStepIndex: 3, selectedPresetId: 'x', helpView: null, visible: false, isMinimized: true })
		instance.startJourney()
		expect(instance.telosOnboardingActive).toBe(true)
		expect(instance.telosSaved).toBe(false)
		expect(instance.journeyStepIndex).toBe(0)
		expect(instance.selectedPresetId).toBe(null)
		expect(instance.helpView).toBe('preset-select')
		expect(instance.visible).toBe(true)
		expect(instance.isMinimized).toBe(false)
	})

	it('applyPreset übernimmt Preset-Telos-Werte + Sichtbarkeit und wechselt zur Journey', () => {
		const instance = createInstance({ helpView: 'preset-select' })
		instance.applyPreset('it_admin')
		expect(instance.selectedPresetId).toBe('it_admin')
		expect(instance.telosForm.telos.role).toBe('IT-Administrator')
		expect(instance.telosForm.telos.target_cert).toBe('CompTIA Security+')
		expect(instance.telosForm.visibility).toBe('course')
		expect(instance.helpView).toBe('telos-journey')
		expect(instance.journeyStepIndex).toBe(0)
	})

	it('applyPreset mit unbekannter ID ist ein No-Op', () => {
		const instance = createInstance({ helpView: 'preset-select', selectedPresetId: null })
		instance.applyPreset('does-not-exist')
		expect(instance.selectedPresetId).toBe(null)
		expect(instance.helpView).toBe('preset-select')
	})

	it('startCustomJourney startet mit frischem Formular ohne Preset', () => {
		const instance = createInstance({ selectedPresetId: 'it_admin', helpView: 'preset-select' })
		instance.startCustomJourney()
		expect(instance.selectedPresetId).toBe(null)
		expect(instance.telosForm.telos.role).toBe('')
		expect(instance.helpView).toBe('telos-journey')
		expect(instance.journeyStepIndex).toBe(0)
	})
})

describe('journeyNext / journeyBack — Grenzen', () => {
	it('journeyBack bei Index 0 bleibt 0', () => {
		const instance = createInstance({ journeyStepIndex: 0 })
		instance.journeyBack()
		expect(instance.journeyStepIndex).toBe(0)
	})

	it('journeyNext erhöht den Index', () => {
		const instance = createInstance({ journeyStepIndex: 0 })
		instance.journeyNext()
		expect(instance.journeyStepIndex).toBe(1)
	})

	it('journeyNext läuft nicht über den letzten Schritt hinaus (letzter Schritt = Save-Action)', () => {
		const instance = createInstance({ journeyStepIndex: 0 })
		for (let i = 0; i < 20; i += 1) instance.journeyNext()
		const cappedIndex = instance.journeyStepIndex
		instance.journeyNext()
		expect(instance.journeyStepIndex).toBe(cappedIndex)
		const step = instance.buildTelosJourneyStep()
		expect(step.actions.some(a => a.type === 'submit-telos-form')).toBe(true)
		expect(step.actions.some(a => a.type === 'journey-next')).toBe(false)
	})
})

describe('updateTelosField — verschachteltes Setzen', () => {
	it('setzt verschachteltes Telos-Feld', () => {
		const instance = createInstance()
		instance.updateTelosField('telos.role', 'Quereinsteiger')
		expect(instance.telosForm.telos.role).toBe('Quereinsteiger')
	})

	it('setzt Top-Level-Feld', () => {
		const instance = createInstance()
		instance.updateTelosField('visibility', 'public')
		expect(instance.telosForm.visibility).toBe('public')
	})

	it('leeres Feld ist ein No-Op', () => {
		const instance = createInstance()
		const before = JSON.stringify(instance.telosForm)
		instance.updateTelosField('', 'x')
		expect(JSON.stringify(instance.telosForm)).toBe(before)
	})
})

describe('submitTelosForm', () => {
	it('Erfolg: speichert, setzt Complete-View, feuert milestone-Reaktion', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const instance = createInstance({ telosSaved: false, telosOnboardingActive: true })
		await instance.submitTelosForm()
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('/apps/learning/api/profile/telos')
		expect(instance.telosSaved).toBe(true)
		expect(instance.telosOnboardingActive).toBe(false)
		expect(instance.helpView).toBe('telos-complete')
		expect(instance.telosCompletionProfile).not.toBe(null)
		expect(instance.applyReaction).toHaveBeenCalledWith('milestone')
	})

	it('Fehler: setzt telosError, telosSaved bleibt false', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'nope' } } })
		const instance = createInstance({ telosSaved: false })
		await instance.submitTelosForm()
		expect(instance.telosError).toBe('nope')
		expect(instance.telosSaved).toBe(false)
	})
})

describe('declineOnboarding', () => {
	it('setzt onboardingDeclined, schließt Panel, persistiert Präferenz', () => {
		const instance = createInstance({ visible: true, helpView: 'welcome' })
		instance.declineOnboarding()
		expect(instance.onboardingDeclined).toBe(true)
		expect(instance.helpView).toBe(null)
		expect(instance.visible).toBe(false)
		expect(instance.telosOnboardingActive).toBe(false)
		expect(instance.saveVirtuProfPreferences).toHaveBeenCalledWith({ onboardingDeclined: true })
	})
})

describe('checkTelosOnboarding — Status-Verzweigung', () => {
	it('Nicht-Student: kein API-Call', async () => {
		const instance = createInstance({ userRole: 'instructor', enabled: true })
		await instance.checkTelosOnboarding()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('onboarding_completed: telosSaved true, Formular übernommen', async () => {
		// response.data.telos ist ein Profil-Objekt; applyTelosToForm liest daraus .telos (verschachtelt).
		axios.get.mockResolvedValueOnce({ data: { onboarding_completed: true, telos: { telos: { role: 'Admin' } } } })
		const instance = createInstance({ userRole: 'student', enabled: true, telosSaved: false })
		await instance.checkTelosOnboarding()
		expect(instance.telosSaved).toBe(true)
		expect(instance.telosForm.telos.role).toBe('Admin')
	})

	it('onboarding_declined: onboardingDeclined true, kein Welcome', async () => {
		axios.get.mockResolvedValueOnce({ data: { onboarding_declined: true } })
		const instance = createInstance({ userRole: 'student', enabled: true, showOnboardingIntro: false })
		await instance.checkTelosOnboarding()
		expect(instance.onboardingDeclined).toBe(true)
		expect(instance.showOnboardingIntro).toBe(false)
	})

	it('weder completed noch declined: zeigt Welcome-Intro', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const instance = createInstance({ userRole: 'student', enabled: true, showOnboardingIntro: false })
		await instance.checkTelosOnboarding()
		expect(instance.showOnboardingIntro).toBe(true)
	})
})

describe('Step-Builder', () => {
	it('buildPresetSelectStep listet alle Presets', () => {
		const instance = createInstance()
		const step = instance.buildPresetSelectStep()
		expect(step.kind).toBe('preset-select')
		expect(step.presets.length).toBe(3)
	})

	it('buildWelcomeStepContent bietet Start + Skip', () => {
		const instance = createInstance()
		const step = instance.buildWelcomeStepContent()
		expect(step.actions.some(a => a.type === 'start-journey')).toBe(true)
		expect(step.actions.some(a => a.type === 'decline-onboarding')).toBe(true)
	})

	it('buildTelosJourneyStep zeigt bei Index 0 keinen Back-Button', () => {
		const instance = createInstance({ journeyStepIndex: 0 })
		const step = instance.buildTelosJourneyStep()
		expect(step.actions.some(a => a.type === 'journey-back')).toBe(false)
	})

	it('buildTelosJourneyStep zeigt ab Index 1 einen Back-Button', () => {
		const instance = createInstance({ journeyStepIndex: 1 })
		const step = instance.buildTelosJourneyStep()
		expect(step.actions.some(a => a.type === 'journey-back')).toBe(true)
	})
})
