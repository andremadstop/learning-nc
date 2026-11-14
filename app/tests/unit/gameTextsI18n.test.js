/**
 * Audit #182: Gameshow commentary, the bot-duel result, the campaign HUD (roles, items, act),
 * DialogueStage emotion tags and the DNS simulator's "invalid" status were German literals outside
 * t(), so they stayed German in every language. The global t used here answers from the real
 * catalogues, so each assertion only passes when the string goes through t() with a key that exists.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({ default: { name: 'NcProgressBar', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => ({ default: { name: 'QuestionLanguageSwitcher', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))
vi.mock('../../src/utils/sse-client.js', () => ({ createSseClient: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

import { setTestLanguage, translate } from './support/l10nCatalog.js'
import GameshowMode from '../../src/components/GameshowMode.vue'
import DuelMode from '../../src/components/DuelMode.vue'
import GameHud from '../../src/components/GameHud.vue'
import DialogueStage from '../../src/components/DialogueStage.vue'
import DnsResolver from '../../src/components/DnsResolver.vue'

beforeEach(() => {
	globalThis.t = translate
	setTestLanguage('en')
})

afterEach(() => {
	setTestLanguage('de')
})

const player = (name, extra = {}) => ({ user_id: name.toLowerCase(), display_name: name, score: 0, lives: 3, ...extra })

describe('Gameshow commentary', () => {
	const commentary = (ctx) => GameshowMode.methods.buildStandingsCommentary.call({
		isEliminationMode: false,
		remainingPlayerCount: 0,
		recentlyEliminatedPlayers: [],
		...ctx,
	})

	it('describes the standings in the reader\'s language', () => {
		expect(commentary({ sortedPlayers: [player('Ana', { score: 500 }), player('Ben', { score: 100 })] })).toBe('Ana is dominating!')
		expect(commentary({ sortedPlayers: [player('Ana', { score: 130 }), player('Ben', { score: 100 })] })).toBe('Ben is close behind Ana!')
		expect(commentary({ sortedPlayers: [player('Ana', { score: 200 }), player('Ben', { score: 100 })] })).toBe('Ana leads. Lead in points: 100')
		expect(commentary({ sortedPlayers: [player('Ana')] })).toBe('Ana is in the lead!')
	})

	it('covers the elimination mode', () => {
		const sortedPlayers = [player('Ana', { lives: 2 }), player('Ben', { lives: 1 }), player('Cem')]
		expect(commentary({ isEliminationMode: true, remainingPlayerCount: 1, sortedPlayers })).toBe('Ana is the last one standing!')
		expect(commentary({ isEliminationMode: true, remainingPlayerCount: 2, sortedPlayers })).toBe('Sudden death between Ana and Ben!')
		expect(commentary({ isEliminationMode: true, remainingPlayerCount: 3, sortedPlayers, recentlyEliminatedPlayers: [player('Dora')] }))
			.toBe('Dora is out. Still in the game: 3')
		expect(commentary({ isEliminationMode: true, remainingPlayerCount: 3, sortedPlayers })).toBe('Ana leads. Lives left: 2')
	})

	it('answer feedback comes from the catalogue', () => {
		const english = ['Correct! Keep it up!', 'Perfect!', 'Exactly right!', 'Great!', 'So close!', 'Sorry, wrong!', 'That missed!', 'Not quite!']
		for (let i = 0; i < 20; i++) {
			expect(english).toContain(GameshowMode.methods.buildAnswerMessage.call({}, i % 2 === 0))
		}
	})
})

describe('bot duel result', () => {
	it.each([['Gameshow', GameshowMode], ['Duel', DuelMode]])('%s: the bot win is announced in the reader\'s language', (_name, Component) => {
		const text = Component.computed.botWinnerText.call({ botLocalState: { myScore: 1, botScore: 3 } })
		expect(text).toBe('🤓 Klaus wins!')
	})
})

describe('campaign HUD', () => {
	it('labels the role, the act and the items through the catalogue', () => {
		expect(GameHud.computed.roleLabel.call({ hudState: { role: 'architect' } })).toBe('Network architect')
		expect(GameHud.computed.actLabel.call({ actProgress: { current: 2, total: 5 } })).toBe('Act 2/5')
		const coffee = GameHud.methods.itemMeta.call({}, 'kaffee')
		expect(coffee.label).toBe('Coffee')
		expect(coffee.description).toBe('Probably improves troubleshooting, psychologically.')
		setTestLanguage('de')
		expect(GameHud.methods.itemMeta.call({}, 'key_card').label).toBe('Schlüsselkarte')
	})
})

describe('DialogueStage emotion tag', () => {
	it('is translated, and German has its umlaut', () => {
		expect(DialogueStage.computed.emotionLabel.call({ emotion: 'explain' })).toBe('explaining')
		setTestLanguage('de')
		expect(DialogueStage.computed.emotionLabel.call({ emotion: 'explain' })).toBe('erklärend')
		expect(DialogueStage.computed.emotionLabel.call({ emotion: 'unknown_mood' })).toBe('unknown_mood')
	})
})

describe('DNS resolver status', () => {
	it('translates "invalid" and keeps the protocol codes', () => {
		expect(DnsResolver.computed.statusLabel.call({ lookupResult: { status: 'invalid_request' } })).toBe('Invalid')
		expect(DnsResolver.computed.statusLabel.call({ lookupResult: { status: 'missing_domain' } })).toBe('NXDOMAIN')
	})
})
