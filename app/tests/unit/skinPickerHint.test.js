/**
 * Phase 153 Plan 02 Wave-0 scaffold for MIGR-03.
 *
 * Exercises the composite hint-visibility contract (hintDismissed + 7-day
 * shown-at expiry) that PersonalSettings.vue's `showSkinPickerHint` computed
 * (added in Plan 04) must satisfy.
 *
 * IMPORTANT: do NOT call `hintMixin.methods.dismissHint(HINT_ID)` bare. The
 * mixin's dismissHint internally calls `this.$forceUpdate()`, which throws
 * `TypeError: this.$forceUpdate is not a function` when invoked off the
 * methods object with no Vue instance. The test simulates the OBSERVABLE
 * side-effect (localStorage key write) directly — that's the same state the
 * mixin's `hintDismissed` reads, so the visibility contract is exercised
 * correctly without needing a Vue instance.
 *
 * Plan 04 wires this contract into a real component and the Playwright spec
 * (Plan 05) covers the full UI dismiss-click path.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import hintMixin from '../../src/hintMixin.js'

const HINT_ID = 'skin-picker-v440'
const DISMISS_KEY = `learning-hint-${HINT_ID}-dismissed`
const SHOWN_KEY = `learning-hint-${HINT_ID}-shown-at`
const SEVEN_DAYS_MS = 7 * 24 * 60 * 60 * 1000

// Helper: replicate the visibility computed used in PersonalSettings.vue
// (Plan 04 implements this inside the component). hintMixin.methods.hintDismissed
// reads localStorage directly and is `this`-free → safe to call bare.
function shouldShowHint(now = Date.now()) {
	// 1. Dismissed flag wins
	if (hintMixin.methods.hintDismissed(HINT_ID)) {
		return false
	}
	// 2. Otherwise check the 7-day window via shown-at sibling key
	let shownAt = parseInt(localStorage.getItem(SHOWN_KEY) || '0', 10)
	if (!shownAt) {
		shownAt = now
		try {
			localStorage.setItem(SHOWN_KEY, String(shownAt))
		} catch {
			/* ignore */
		}
	}
	return now - shownAt < SEVEN_DAYS_MS
}

describe('skin-picker hint v440 — visibility contract (MIGR-03)', () => {
	beforeEach(() => {
		localStorage.clear()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('is visible on first render and seeds the shown-at timestamp', () => {
		expect(shouldShowHint()).toBe(true)
		expect(parseInt(localStorage.getItem(SHOWN_KEY), 10)).toBeGreaterThan(0)
	})

	it('hides immediately after dismissal (DISMISS_KEY = "1")', () => {
		expect(shouldShowHint()).toBe(true)
		// Simulate the observable side-effect of hintMixin.dismissHint() WITHOUT
		// calling the method bare — bare invocation throws TypeError because
		// dismissHint internally does this.$forceUpdate(), which has no `this`
		// binding off the methods object. The visibility contract reads
		// localStorage directly via hintDismissed(id), so writing the key is
		// functionally equivalent for this unit test.
		localStorage.setItem(DISMISS_KEY, '1')
		expect(hintMixin.methods.hintDismissed(HINT_ID)).toBe(true)
		expect(shouldShowHint()).toBe(false)
	})

	it('hides automatically once the 7-day window has passed', () => {
		const t0 = Date.now()
		shouldShowHint(t0) // seeds the shown-at key
		const tFuture = t0 + SEVEN_DAYS_MS + 1
		expect(shouldShowHint(tFuture)).toBe(false)
	})

	it('uses a separate key per hint id (no cross-contamination)', () => {
		const OTHER_ID = 'some-other-hint'
		const OTHER_DISMISS_KEY = `learning-hint-${OTHER_ID}-dismissed`
		// Simulate dismissing a DIFFERENT hint id
		localStorage.setItem(OTHER_DISMISS_KEY, '1')
		// skin-picker hint is still showable (different DISMISS_KEY)
		expect(shouldShowHint()).toBe(true)
	})
})
