/**
 * Pre-existing, found in the #40 review: VirtuProf labels an open support ticket and an open duel
 * invite with vt('Open'). fr, ru and uk translated that key as the verb ("Ouvrir", "Открыть",
 * "Відкрити"), so the status badge told the learner to open it. Both readers use the key as a
 * status; no t('learning', 'Open') call exists. The real components run on the generated catalogue.
 */
import { describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => '/nextcloud/index.php' + url),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

import VirtuProf from '../../src/components/VirtuProf.vue'
import VirtuProfBubble from '../../src/components/VirtuProfBubble.vue'
import { translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'

const STATUS = {
	de: 'Offen',
	en: 'Open',
	fr: 'Ouvert',
	ru: 'Открыт',
	ar: 'مفتوح',
	uk: 'Відкрито',
}

function bubble(lang) {
	return { effectiveLanguage: lang, vt: (key, params) => translateVirtuProf(lang, key, params) }
}

describe('VirtuProf shows "open" as a status, not as the verb', () => {
	it.each(Object.entries(STATUS))('%s: an open ticket in the bubble', (lang, label) => {
		expect(VirtuProfBubble.methods.statusLabel.call(bubble(lang), 'open')).toBe(label)
	})

	it.each(Object.entries(STATUS))('%s: an open duel invite', (lang, label) => {
		const key = VirtuProf.methods.inviteStatusLabel.call({}, { status: 'open', can_open_duel: false })
		expect(translateVirtuProf(lang, key)).toBe(label)
	})
})
