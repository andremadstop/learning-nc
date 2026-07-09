import { describe, it, expect, vi, beforeEach } from 'vitest'

// Charakterisierungs-Netz für die Help/FAQ/Ticket-Navigation von VirtuProf.
// Gebaut VOR der Mixin-Extraktion (Phase A2, Mixin 10). Muss danach OHNE Änderung grün
// bleiben — Beweis der Verhaltensneutralität. Location-agnostisch über die GEMERGTEN
// Options (Komponente + Mixins); bindet zusätzlich Computeds, da einige Ticket-Methoden
// this.hasCourseContext / this.orderedFaq* lesen.

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'
import { FAQS, FAQ_CATEGORIES } from '../../src/utils/virtuprof-scripts.js'

function mergedOptions(comp) {
	const mixins = comp.mixins || []
	const data = Object.assign({}, ...mixins.map(m => (m.data ? m.data() : {})), comp.data ? comp.data() : {})
	const methods = Object.assign({}, ...mixins.map(m => m.methods || {}), comp.methods || {})
	const computed = Object.assign({}, ...mixins.map(m => m.computed || {}), comp.computed || {})
	return { data, methods, computed }
}

function createInstance(overrides = {}) {
	const { data, methods, computed } = mergedOptions(VirtuProf)
	const instance = { ...data, $emit: vi.fn(), $refs: {}, ...overrides }
	for (const [name, fn] of Object.entries(methods)) {
		instance[name] = fn.bind(instance)
	}
	// Computeds als lazy Getter binden (nur beim Zugriff ausgewertet).
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(instance, name, { get: fn.bind(instance), configurable: true })
	}
	// Zentrale Cross-Cutter, die im Component-Kern bleiben, für Isolation stubben:
	instance.applyReaction = vi.fn()
	instance.saveVirtuProfPreferences = vi.fn(() => Promise.resolve())
	instance.processNext = vi.fn()
	instance.vt = (key) => key
	return instance
}

const anyFaqId = Object.keys(FAQS)[0]
const anyCategoryId = FAQS[anyFaqId].category

beforeEach(() => {
	vi.clearAllMocks()
})

describe('Help-Navigation — Opener', () => {
	it('openFaqList setzt faq-list-View und blendet Panel ein', () => {
		const instance = createInstance({ helpView: null, visible: false, isMinimized: true, activeFaqId: 'x', activeFaqCategoryId: 'y' })
		instance.openFaqList()
		expect(instance.helpView).toBe('faq-list')
		expect(instance.activeFaqId).toBe(null)
		expect(instance.activeFaqCategoryId).toBe(null)
		expect(instance.visible).toBe(true)
		expect(instance.isMinimized).toBe(false)
	})

	it('openHelpHome setzt home-View', () => {
		const instance = createInstance({ helpView: null })
		instance.openHelpHome()
		expect(instance.helpView).toBe('home')
	})

	it('openContextHelp setzt context-View', () => {
		const instance = createInstance({ helpView: null })
		instance.openContextHelp()
		expect(instance.helpView).toBe('context')
	})

	it('openFaqCategory mit gültiger Kategorie öffnet sie', () => {
		const instance = createInstance({ helpView: null })
		instance.openFaqCategory(anyCategoryId)
		expect(instance.helpView).toBe('faq-category')
		expect(instance.activeFaqCategoryId).toBe(anyCategoryId)
	})

	it('openFaqCategory mit unbekannter Kategorie ist No-Op', () => {
		const instance = createInstance({ helpView: 'sentinel' })
		instance.openFaqCategory('__does_not_exist__')
		expect(instance.helpView).toBe('sentinel')
	})

	it('openFaq mit gültiger ID öffnet Antwort + setzt Kategorie', () => {
		const instance = createInstance({ helpView: null })
		instance.openFaq(anyFaqId)
		expect(instance.helpView).toBe('faq-answer')
		expect(instance.activeFaqId).toBe(anyFaqId)
		expect(instance.activeFaqCategoryId).toBe(FAQS[anyFaqId].category || null)
	})

	it('openFaq mit unbekannter ID ist No-Op', () => {
		const instance = createInstance({ helpView: 'sentinel' })
		instance.openFaq('__nope__')
		expect(instance.helpView).toBe('sentinel')
	})

	it('openTicketForm setzt ticket-form, technical ohne Kurskontext', () => {
		const instance = createInstance({ helpView: null, ticketSubject: '', currentContext: {} })
		instance.openTicketForm()
		expect(instance.helpView).toBe('ticket-form')
		expect(instance.ticketCategory).toBe('technical')
	})
})

describe('closeHelp', () => {
	it('normaler Pfad: helpView null, Ticket-Feedback zurückgesetzt', () => {
		const instance = createInstance({ helpView: 'faq-list', telosOnboardingActive: false, ticketError: 'e', ticketSuccess: 's', currentScriptId: null, queue: [] })
		instance.closeHelp()
		expect(instance.helpView).toBe(null)
		expect(instance.ticketError).toBe('')
		expect(instance.ticketSuccess).toBe('')
	})

	it('während Onboarding: delegiert an declineOnboarding', () => {
		const instance = createInstance({ helpView: 'telos-journey', telosOnboardingActive: true })
		instance.closeHelp()
		expect(instance.onboardingDeclined).toBe(true)
		expect(instance.helpView).toBe(null)
	})
})

describe('Ticket-Flow', () => {
	it('resetTicketFeedback leert Fehler + Erfolg', () => {
		const instance = createInstance({ ticketError: 'e', ticketSuccess: 's' })
		instance.resetTicketFeedback()
		expect(instance.ticketError).toBe('')
		expect(instance.ticketSuccess).toBe('')
	})

	it('submitTicket bei laufendem Versand → No-Op', async () => {
		const instance = createInstance({ ticketSending: true })
		await instance.submitTicket()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('submitTicket Erfolg: POST, Erfolgstext, Draft geleert, Tickets neu geladen', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		axios.get.mockResolvedValueOnce({ data: { tickets: [] } })
		const instance = createInstance({ ticketSending: false, ticketDraft: 'hilfe', ticketSubject: 'Betreff', currentContext: {}, activeFaqId: null, activeFaqCategoryId: null, helpView: 'ticket-form' })
		await instance.submitTicket()
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('/apps/learning/api/support-tickets')
		expect(instance.ticketSuccess).toBe('Support ticket sent')
		expect(instance.ticketDraft).toBe('')
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(instance.ticketSending).toBe(false)
	})

	it('submitTicket Fehler: setzt ticketError', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'kaputt' } } })
		const instance = createInstance({ ticketSending: false, ticketDraft: 'x', ticketSubject: 'y', currentContext: {} })
		await instance.submitTicket()
		expect(instance.ticketError).toBe('kaputt')
		expect(instance.ticketSending).toBe(false)
	})

	it('loadMyTickets Erfolg: übernimmt tickets-Array', async () => {
		axios.get.mockResolvedValueOnce({ data: { tickets: [{ id: 1 }] } })
		const instance = createInstance({ myTickets: [] })
		await instance.loadMyTickets()
		expect(instance.myTickets).toEqual([{ id: 1 }])
	})

	it('loadMyTickets Fehler: leert Liste + setzt Fehler', async () => {
		axios.get.mockRejectedValueOnce(new Error('net'))
		const instance = createInstance({ myTickets: [{ id: 9 }] })
		await instance.loadMyTickets()
		expect(instance.myTickets).toEqual([])
		expect(instance.ticketError).toBe('Failed to load support tickets')
	})

	it('defaultTicketSubject nutzt FAQ-Titel wenn activeFaqId gesetzt', () => {
		const instance = createInstance({ activeFaqId: anyFaqId })
		expect(instance.defaultTicketSubject()).toBe(FAQS[anyFaqId].title)
	})

	it('defaultTicketSubject fällt ohne FAQ auf Kontext-Teile zurück', () => {
		const instance = createInstance({ activeFaqId: null, currentContext: { courseTitle: 'Kurs', poolName: 'Pool', area: 'pool' } })
		expect(instance.defaultTicketSubject()).toBe('Kurs | Pool | pool')
	})
})

describe('Step-Builder', () => {
	it('buildFaqCategoryStep fällt bei ungültiger Kategorie auf FAQ-Liste zurück', () => {
		const instance = createInstance({ activeFaqCategoryId: '__nope__' })
		const step = instance.buildFaqCategoryStep()
		expect(step.title).toBe('FAQs')
	})

	it('buildFaqAnswerStep fällt bei ungültiger FAQ auf FAQ-Liste zurück', () => {
		const instance = createInstance({ activeFaqId: '__nope__' })
		const step = instance.buildFaqAnswerStep()
		expect(step.title).toBe('FAQs')
	})

	it('buildHelpHomeStep bietet FAQ-, Ticket- und Close-Aktionen', () => {
		const instance = createInstance({ currentContext: {} })
		const step = instance.buildHelpHomeStep()
		const types = step.actions.map(a => a.type)
		expect(types).toContain('open-faq-list')
		expect(types).toContain('open-ticket-form')
		expect(types).toContain('close-help')
	})

	it('buildTicketFormStep hat kind ticket-form', () => {
		const instance = createInstance({ currentContext: {} })
		expect(instance.buildTicketFormStep().kind).toBe('ticket-form')
	})

	it('buildTicketListStep hat kind ticket-list', () => {
		const instance = createInstance()
		expect(instance.buildTicketListStep().kind).toBe('ticket-list')
	})
})

describe('Computeds', () => {
	it('orderedFaqIds filtert auf aktive Kategorie', () => {
		const instance = createInstance({ activeFaqCategoryId: anyCategoryId })
		const ids = instance.orderedFaqIds
		expect(ids.length).toBeGreaterThan(0)
		expect(ids.every(id => FAQS[id].category === anyCategoryId)).toBe(true)
	})

	it('orderedFaqIds ohne Kategorie liefert alle FAQs', () => {
		const instance = createInstance({ activeFaqCategoryId: null })
		expect(instance.orderedFaqIds.length).toBe(Object.keys(FAQS).length)
	})

	it('orderedFaqCategoryIds enthält alle Kategorien', () => {
		const instance = createInstance({ currentContext: {} })
		expect([...instance.orderedFaqCategoryIds].sort()).toEqual(Object.keys(FAQ_CATEGORIES).sort())
	})
})
