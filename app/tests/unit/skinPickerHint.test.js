/**
 * Skin-picker hint (MIGR-03): calls the real PersonalSettings.vue `showSkinPickerHint` computed
 * with the real hintMixin.hintDismissed (audit #128: this file used to re-implement the computed,
 * so a change to the 7-day window or the dismiss check stayed green).
 *
 * dismissHint() is not called: it does this.$forceUpdate(). The test writes the same localStorage
 * key it writes, which is what hintDismissed() reads.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
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

import hintMixin from '../../src/hintMixin.js'
import PersonalSettings from '../../src/components/PersonalSettings.vue'

const HINT_ID = 'skin-picker-v440'
const DISMISS_KEY = `learning-hint-${HINT_ID}-dismissed`
const SHOWN_KEY = `learning-hint-${HINT_ID}-shown-at`
const DAY_MS = 24 * 60 * 60 * 1000
const T0 = new Date('2026-11-01T10:00:00Z').getTime()

function showHint() {
	return PersonalSettings.computed.showSkinPickerHint.call({ hintDismissed: hintMixin.methods.hintDismissed })
}

describe('skin-picker hint v440 — PersonalSettings.showSkinPickerHint', () => {
	beforeEach(() => {
		localStorage.clear()
		vi.useFakeTimers()
		vi.setSystemTime(T0)
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('is visible on first render and seeds the shown-at timestamp', () => {
		expect(showHint()).toBe(true)
		expect(parseInt(localStorage.getItem(SHOWN_KEY), 10)).toBe(T0)
	})

	it('hides immediately after dismissal', () => {
		expect(showHint()).toBe(true)
		localStorage.setItem(DISMISS_KEY, '1')
		expect(showHint()).toBe(false)
	})

	it('is still visible just before the 7-day window ends', () => {
		showHint()
		vi.setSystemTime(T0 + 7 * DAY_MS - 1)
		expect(showHint()).toBe(true)
	})

	it('hides automatically once the 7-day window has passed', () => {
		showHint()
		vi.setSystemTime(T0 + 7 * DAY_MS)
		expect(showHint()).toBe(false)
	})

	it('does not reset the window on later renders', () => {
		showHint()
		vi.setSystemTime(T0 + 3 * DAY_MS)
		showHint()
		expect(parseInt(localStorage.getItem(SHOWN_KEY), 10)).toBe(T0)
	})

	it('ignores the dismissal of a different hint id', () => {
		localStorage.setItem('learning-hint-some-other-hint-dismissed', '1')
		expect(showHint()).toBe(true)
	})
})
