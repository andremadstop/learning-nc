/**
 * Names inserted into translated text that Vue renders as text must come out as typed. The real
 * @nextcloud/l10n (not mocked here) HTML-escapes placeholder values and sanitizes the result by
 * default: "R&D" became "R&amp;D", and "<intern>" was stripped. Audit #154 (feed title) and #182
 * (Gameshow commentary) pass { escape: false, sanitize: false }; these tests run the real library,
 * so they fail if either option is dropped.
 */
import { describe, expect, it, vi } from 'vitest'
import { translate } from '@nextcloud/l10n'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({ default: { name: 'NcProgressBar', template: '<div />' } }))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => ({ default: { name: 'QuestionLanguageSwitcher', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))
vi.mock('../../src/utils/sse-client.js', () => ({ createSseClient: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

import { feedItemTitle } from '../../src/utils/feedItem.js'
import GameshowMode from '../../src/components/GameshowMode.vue'

describe('names in translated text stay as typed', () => {
	it('feed: a pool name with & and <…>', () => {
		expect(feedItemTitle({ type: 'pool_added', meta: { pool_name: 'R&D <intern>' } })).toBe('Neuer Fragenpool: R&D <intern>')
	})

	it('Gameshow commentary: player names with & and <…>', () => {
		globalThis.t = translate
		const text = GameshowMode.methods.buildStandingsCommentary.call({
			isEliminationMode: false,
			remainingPlayerCount: 0,
			recentlyEliminatedPlayers: [],
			sortedPlayers: [{ display_name: 'Tom & Jerry', score: 900 }, { display_name: '<Ben>', score: 0 }],
		})
		expect(text).toBe('Tom & Jerry dominiert!')
	})
})
