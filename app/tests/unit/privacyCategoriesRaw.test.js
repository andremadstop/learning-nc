/**
 * GitHub #38: the category texts go through @nextcloud/l10n translate(), which by default escapes
 * placeholders and runs the result through DOMPurify. In a browser DOMPurify serializes "&" as
 * "&amp;", and the page renders the texts with {{ }}, so "Audit & Moderation" would read
 * "Audit &amp; Moderation". happy-dom's serializer keeps "&", so the effect itself cannot be shown
 * here; this test holds the contract instead: every category text is asked for as plain text.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const real = await importOriginal()
	return { ...real, translate: vi.fn((...args) => real.translate(...args)) }
})

import { translate } from '@nextcloud/l10n'
import { buildPrivacyCategoryGroups } from '../../src/utils/privacyInfo.js'

describe('privacy categories are translated as plain text', () => {
	it('every title and text asks translate() for no escaping and no sanitizing', () => {
		translate.mockClear()
		const groups = buildPrivacyCategoryGroups('Q&A <Labs>')
		expect(translate.mock.calls.length).toBeGreaterThan(50)
		for (const call of translate.mock.calls) {
			expect(call[4], `options for "${call[1]}"`).toEqual({ escape: false, sanitize: false })
		}
		// a provider name is data, put in after translation: shown as typed
		const ai = groups.find((g) => g.id === 'ai').entries.find((e) => e.id === 'ai-interaction')
		expect(ai.who_sees).toMatch(/^Q&A <Labs> verarbeitet die Anfrage\./)
	})
})
