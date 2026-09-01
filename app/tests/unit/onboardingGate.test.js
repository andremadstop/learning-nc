import { describe, expect, it } from 'vitest'

import { hasSeenOnboarding, onboardingSeenKey } from '../../src/utils/onboardingGate.js'

/**
 * Codeberg #5 — "for new users, as well as after updates or restarts, the wizard appears again".
 *
 * App.vue decided purely on window.localStorage, which is scoped to one browser on one machine:
 * a second computer, a private window or cleared site data all brought the wizard back. The
 * server-side flag is authoritative from 5.4.2 on; localStorage survives as an offline fallback.
 *
 * The decision lives in this util rather than inline in App.vue because App.vue cannot be
 * imported under Vitest (@nextcloud/vue pulls in CSS the resolver cannot handle), and a rule
 * this load-bearing must not be the one part that no test can reach.
 *
 * RED against a util that ignores the acknowledged flag.
 */
function storageWith(entries = {}) {
	const map = new Map(Object.entries(entries))
	return {
		getItem: (key) => (map.has(key) ? map.get(key) : null),
		setItem: (key, value) => map.set(key, value),
	}
}

const throwingStorage = {
	getItem: () => {
		throw new Error('site data blocked')
	},
	setItem: () => {
		throw new Error('site data blocked')
	},
}

describe('hasSeenOnboarding', () => {
	it('trusts the server over an empty browser', () => {
		expect(hasSeenOnboarding(true, 'alice', storageWith())).toBe(true)
	})

	it('lets a genuinely new user through to the onboarding', () => {
		expect(hasSeenOnboarding(false, 'alice', storageWith())).toBe(false)
	})

	it('falls back to the local flag when the server said nothing yet', () => {
		const storage = storageWith({ 'learning:onboarding-seen:alice': 'yes' })

		expect(hasSeenOnboarding(false, 'alice', storage)).toBe(true)
	})

	it('keeps users apart on a shared browser', () => {
		const storage = storageWith({ 'learning:onboarding-seen:alice': 'yes' })

		expect(hasSeenOnboarding(false, 'bob', storage)).toBe(false)
	})

	it('does not show the onboarding when storage itself is unavailable', () => {
		// A blocked storage must not be read as "never seen" — that would put the wizard in front
		// of exactly the users who cannot dismiss it persistently.
		expect(hasSeenOnboarding(true, 'alice', throwingStorage)).toBe(true)
		expect(hasSeenOnboarding(false, 'alice', throwingStorage)).toBe(false)
	})

	it('builds the storage key the old releases already used', () => {
		// 5.4.1 and earlier wrote this exact key; changing it would re-trigger the wizard for
		// every user whose acknowledgement has not reached the server yet.
		expect(onboardingSeenKey('alice')).toBe('learning:onboarding-seen:alice')
	})
})
