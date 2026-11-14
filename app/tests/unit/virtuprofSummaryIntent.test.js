/**
 * Audit #59: the "Create summary" chip in fullscreen sent its label in the user's language
 * ("Créer un résumé"), which VirtuProfController's German/English keyword match did not know, so
 * fr/ru/ar/uk users got an LLM answer instead of the summary file. The bubble sent the German
 * literal 'zusammenfassung erstellen', which showed up as the user's own message. Both chips now
 * send the translated label plus intent: 'summary', and the controller reads `intent`.
 *
 * Audit #187 (VirtuProf part): the "report this question" confirmations were German and pushed as
 * `content`, while the chat renders `text` — empty bubbles.
 *
 * The VirtuProf catalogue is real here (no virtuprof-i18n mock), so a label only comes out French
 * if it goes through vt() with a key the catalogue has.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

import axios from '@nextcloud/axios'
import { translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'
import VirtuProf from '../../src/components/VirtuProf.vue'
import VirtuProfBubble from '../../src/components/VirtuProfBubble.vue'
import VirtuProfFullscreen from '../../src/components/VirtuProfFullscreen.vue'

const frenchVt = (key, params) => translateVirtuProf('fr', key, params)

function chatContext(overrides = {}) {
	return {
		consentValid: true,
		showAiConsentDialog: false,
		pendingChatMessage: null,
		pendingChatIntent: null,
		lastRefusedMessage: null,
		visible: true,
		isMinimized: false,
		chatMessages: [],
		chatLoading: false,
		currentContext: { poolId: 5, courseId: 7 },
		hintLevel: 0,
		applyReaction: vi.fn(),
		isHintRequest: () => false,
		vt: frenchVt,
		...overrides,
	}
}

describe('summary chip (audit #59)', () => {
	it.each([
		['bubble', VirtuProfBubble],
		['fullscreen', VirtuProfFullscreen],
	])('%s: sends the label in the user\'s language plus intent summary', (_name, Component) => {
		const chips = Component.computed.quickSuggestions.call({ vt: frenchVt })
		const summary = chips.find((chip) => chip.key === 'zusammenfassung')
		expect(summary.intent).toBe('summary')
		expect(summary.text).toBe(translateVirtuProf('fr', 'Create summary'))
		expect(summary.text).not.toBe('Create summary')
		expect(summary.text).not.toBe('zusammenfassung erstellen')
	})

	it('bubble and fullscreen hand the intent on with the text', () => {
		const bubble = { chatLoading: false, $emit: vi.fn() }
		VirtuProfBubble.methods.sendSuggestion.call(bubble, 'Créer un résumé', 'summary')
		expect(bubble.$emit).toHaveBeenCalledWith('chat-send', 'Créer un résumé', 'summary')

		const fullscreen = { chatLoading: false, $emit: vi.fn() }
		VirtuProfFullscreen.methods.sendSuggestion.call(fullscreen, 'Créer un résumé', 'summary')
		expect(fullscreen.$emit).toHaveBeenCalledWith('send', 'Créer un résumé', 'summary')
	})
})

describe('VirtuProf chat request carries the intent (audit #59)', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('posts intent, the parameter VirtuProfController::chat() declares', async () => {
		axios.post.mockResolvedValue({ data: { answer: 'ok', action: 'file_created', path: '/Learning/x.md' } })
		const ctx = chatContext()

		await VirtuProf.methods.handleChatSend.call(ctx, 'Créer un résumé', 'summary')

		const call = axios.post.mock.calls.find((c) => String(c[0]).includes('virtu-prof/chat'))
		expect(call[1]).toMatchObject({ message: 'Créer un résumé', intent: 'summary', poolId: 5, courseId: 7 })
	})

	it('a typed message sends no intent', async () => {
		axios.post.mockResolvedValue({ data: { answer: 'ok' } })
		await VirtuProf.methods.handleChatSend.call(chatContext(), 'was ist ein vlan')
		const call = axios.post.mock.calls.find((c) => String(c[0]).includes('virtu-prof/chat'))
		expect(call[1]).not.toHaveProperty('intent')
	})

	it('keeps the intent while the consent dialog is open and resends it after consent', async () => {
		const ctx = chatContext({ consentValid: false })
		await VirtuProf.methods.handleChatSend.call(ctx, 'Créer un résumé', 'summary')
		expect(ctx.pendingChatMessage).toBe('Créer un résumé')
		expect(ctx.pendingChatIntent).toBe('summary')

		ctx.handleChatSend = vi.fn()
		VirtuProf.methods.handleConsentGranted.call(ctx, { written: true })
		expect(ctx.handleChatSend).toHaveBeenCalledWith('Créer un résumé', 'summary')
		expect(ctx.pendingChatIntent).toBeNull()
	})
})

describe('question error report confirmation (audit #187)', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	const reportContext = () => chatContext({
		currentContext: { questionContext: { questionId: 9, poolId: 5, courseId: 7 } },
	})

	it('is a visible chat message in the user\'s language', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const ctx = reportContext()
		await VirtuProf.methods.handleReportError.call(ctx)
		const msg = ctx.chatMessages.at(-1)
		expect(msg.text).toBe('Merci pour le signalement ! Ton formateur a été prévenu et va vérifier la question.')
	})

	it('reports a failed send the same way', async () => {
		axios.post.mockRejectedValue(new Error('down'))
		const ctx = reportContext()
		await VirtuProf.methods.handleReportError.call(ctx)
		expect(ctx.chatMessages.at(-1).text).toBe('Le signalement d\'erreur n\'a malheureusement pas pu être envoyé. Réessaie plus tard.')
	})
})
