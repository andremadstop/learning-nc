/**
 * GitHub #36: with the admin's gamification switch off, VirtuProf's help still offered the
 * category "Fortschritt & Belohnungen" ("Mastery, Rangliste, XP, Streaks und Badges"), the FAQ
 * answers on XP/levels, streaks, badges and the leaderboard, and a training-results answer that
 * promised XP, streak effects and badges. Without gamification those entries are left out and
 * the texts are neutral; with gamification on (or unknown) the FAQ is unchanged.
 *
 * The VirtuProf catalogue is real here (no virtuprof-i18n mock), so a French text only comes
 * out if the neutral variant made it into the catalogue.
 */
import { describe, expect, it, vi } from 'vitest'

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

import { translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'
import { FAQ_CATEGORIES, FAQS } from '../../src/utils/virtuprof-scripts.js'
import VirtuProf from '../../src/components/VirtuProf.vue'

const GAMIFICATION_FAQS = ['xp-levels', 'streaks', 'badges', 'leaderboard']

function helpContext(gamificationEnabled, lang = 'de') {
	const ctx = {
		gamificationEnabled,
		activeFaqCategoryId: null,
		activeFaqId: null,
		vt: (key, params) => translateVirtuProf(lang, key, params),
		recommendedFaqCategoryId: () => null,
		resetTicketFeedback: () => {},
	}
	for (const name of ['activeFaqCatalogue', 'faqs', 'faqCategories', 'orderedFaqIds', 'orderedFaqCategoryIds']) {
		Object.defineProperty(ctx, name, { get: () => VirtuProf.computed[name].call(ctx) })
	}
	for (const name of ['buildFaqListStep', 'buildFaqCategoryStep', 'buildFaqAnswerStep', 'openFaq', 'openFaqCategory']) {
		ctx[name] = VirtuProf.methods[name].bind(ctx)
	}
	return ctx
}

function progressStep(ctx) {
	ctx.openFaqCategory('progress')
	return ctx.buildFaqCategoryStep()
}

describe('VirtuProf FAQ with gamification off (GitHub #36)', () => {
	it('names neither XP, streaks nor badges in the progress category', () => {
		const step = progressStep(helpContext(false))
		expect(step.title).toBe('Fortschritt im Kurs')
		expect(step.text).not.toMatch(/XP|Streak|Badge|Rangliste/)
		const faqIds = step.actions.filter(a => a.type === 'open-faq').map(a => a.faqId)
		expect(faqIds).toEqual(['my-progress'])
	})

	it('does not open an XP, streak, badge or leaderboard answer', () => {
		const ctx = helpContext(false)
		for (const id of GAMIFICATION_FAQS) {
			ctx.activeFaqId = null
			ctx.openFaq(id)
			expect(ctx.activeFaqId).toBeNull()
		}
	})

	it('lists the category under its neutral label', () => {
		const labels = helpContext(false).buildFaqListStep().actions.map(a => a.label)
		expect(labels).toContain('Fortschritt im Kurs')
		expect(labels).not.toContain('Fortschritt & Belohnungen')
	})

	it('answers "what do I see after training" without XP, streaks or badges', () => {
		const ctx = helpContext(false)
		ctx.openFaq('training-results')
		const { text } = ctx.buildFaqAnswerStep()
		expect(text).not.toMatch(/XP|Streak|Badge/)
		expect(text).toContain('persönliche Bestleistung')
	})

	it('has the neutral texts in the VirtuProf catalogue (French UI)', () => {
		const ctx = helpContext(false, 'fr')
		expect(progressStep(ctx).title).toBe('Progression dans le cours')
		ctx.openFaq('training-results')
		expect(ctx.buildFaqAnswerStep().text).toMatch(/^À la fin, vous voyez votre score et le nombre/)
	})
})

describe('VirtuProf FAQ with gamification on (unchanged)', () => {
	it.each([true, undefined])('offers every category and entry as before (gamificationEnabled=%s)', (enabled) => {
		const ctx = helpContext(enabled)
		expect(ctx.faqs).toBe(FAQS)
		expect(ctx.faqCategories).toBe(FAQ_CATEGORIES)
		const step = progressStep(ctx)
		expect(step.title).toBe('Fortschritt & Belohnungen')
		const faqIds = step.actions.filter(a => a.type === 'open-faq').map(a => a.faqId)
		for (const id of GAMIFICATION_FAQS) {
			expect(faqIds).toContain(id)
		}
	})
})
