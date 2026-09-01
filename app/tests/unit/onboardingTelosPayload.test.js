import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
}))

import axios from '@nextcloud/axios'
import OnboardingRedesign from '../../src/components/OnboardingRedesign.vue'
import { INTENSITY_TILES } from '../../src/utils/onboarding-slides.js'

globalThis.t = (app, text) => text

/**
 * Codeberg #4 — "TypeError in TelosController::saveTelos(): Argument #3 ($help_offer) must be
 * of type ?array, string given".
 *
 * The onboarding wizard hand-rolled its own payload and posted '' for every list field, where
 * the shared util (used by PersonalSettings and VirtuProf) posts arrays. PHP rejected the
 * string inside NC's Dispatcher, before the controller body ran — an unhandled 500, and the
 * profile of every user who completed onboarding was silently never saved.
 *
 * These tests assert the payload SHAPE at the network boundary, which is the level the bug
 * actually lived at. They go RED against the pre-fix component: help_offer/help_wanted/
 * strengths/weaknesses were '' and hours_per_week was '' instead of a number.
 */
function makeContext(choices = {}, role = 'student') {
	return {
		store: {
			role,
			profileChoices: {
				goal: null,
				intensity: null,
				aiConsent: 'no',
				...choices,
			},
		},
	}
}

function postedPayload() {
	expect(axios.post).toHaveBeenCalledTimes(1)
	return axios.post.mock.calls[0][1]
}

describe('OnboardingRedesign.saveTelosProfile — payload contract', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('posts arrays, never empty strings, for every list field', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext())

		const payload = postedPayload()
		expect(Array.isArray(payload.help_offer)).toBe(true)
		expect(Array.isArray(payload.help_wanted)).toBe(true)
		expect(Array.isArray(payload.telos.strengths)).toBe(true)
		expect(Array.isArray(payload.telos.weaknesses)).toBe(true)
		expect(payload.help_offer).toEqual([])
		expect(payload.help_wanted).toEqual([])
	})

	it('posts hours_per_week as a number, not a string', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({ intensity: 'intensive' }))

		const payload = postedPayload()
		const intensive = INTENSITY_TILES.find((tile) => tile.id === 'intensive')
		expect(typeof payload.telos.hours_per_week).toBe('number')
		expect(payload.telos.hours_per_week).toBe(intensive.hours)
	})

	it('falls back to 0 hours — not "" — when no intensity tile was picked', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({ intensity: null }))

		expect(postedPayload().telos.hours_per_week).toBe(0)
	})

	it('still maps the goal tiles onto the telos fields', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({ goal: 'certification' }))
		expect(postedPayload().telos.target_cert).toBe('certification')

		vi.clearAllMocks()
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({ goal: 'career' }))
		expect(postedPayload().telos.motivation).toBe('career')

		vi.clearAllMocks()
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({ goal: 'hobby' }))
		expect(postedPayload().telos.motivation).toBe('hobby')
	})

	it('keeps the role and the private-by-default visibility', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext({}, 'instructor'))

		const payload = postedPayload()
		expect(payload.telos.role).toBe('instructor')
		expect(payload.visibility).toBe('private')
		expect(payload.telos.experience_level).toBe('beginner')
		expect(payload.telos.learning_style).toBe('solo')
	})

	it('posts to the telos endpoint', async () => {
		await OnboardingRedesign.methods.saveTelosProfile.call(makeContext())

		expect(axios.post.mock.calls[0][0]).toBe('/apps/learning/api/profile/telos')
	})
})

/**
 * Codeberg #4, second-order damage: finalize() ran the profile save, the AI-consent save and the
 * starter-pool import inside ONE try/catch with an empty handler. The TypeError-500 from step 1
 * therefore also cancelled steps 2 and 3 — an opted-in user silently ended up without AI consent
 * (locked out of every AI feature by the v5.2.1 consent gate) and without the starter pool they
 * picked, with nothing logged anywhere. These tests pin the isolation.
 */
describe('OnboardingRedesign.finalize — one failing step must not eat the others', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	function finalizeContext(overrides = {}) {
		const calls = []
		const ctx = {
			store: {
				skipped: false,
				selectedStarterPoolId: 42,
				...overrides.store,
			},
			getUid: () => 'alex',
			visible: true,
			$emit: vi.fn(),
			runFinalizeStep: OnboardingRedesign.methods.runFinalizeStep,
			saveTelosProfile: vi.fn(() => {
				calls.push('telos')
				return Promise.reject(new Error('boom: 500 from the dispatcher'))
			}),
			saveAiConsent: vi.fn(() => {
				calls.push('consent')
				return Promise.resolve()
			}),
			importStarterPool: vi.fn(() => {
				calls.push('pool')
				return Promise.resolve()
			}),
			// Codeberg #5 added a fourth finalize step. Without it here the helper would be
			// testing a TypeError on a missing method rather than the isolation this suite is
			// about.
			acknowledgeOnboarding: vi.fn(() => {
				calls.push('acknowledge')
				return Promise.resolve()
			}),
			...overrides.ctx,
		}
		return { ctx, calls }
	}

	it('still saves AI consent and imports the starter pool when the profile save throws', async () => {
		const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		const { ctx, calls } = finalizeContext()

		await OnboardingRedesign.methods.finalize.call(ctx)

		expect(calls).toEqual(['telos', 'consent', 'pool', 'acknowledge'])
		expect(ctx.saveAiConsent).toHaveBeenCalledTimes(1)
		expect(ctx.importStarterPool).toHaveBeenCalledTimes(1)
		errorSpy.mockRestore()
	})

	it('logs the failing step instead of swallowing it silently', async () => {
		const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		const { ctx } = finalizeContext()

		await OnboardingRedesign.methods.finalize.call(ctx)

		expect(errorSpy).toHaveBeenCalledTimes(1)
		expect(String(errorSpy.mock.calls[0][0])).toContain('telos profile')
		errorSpy.mockRestore()
	})

	it('still completes onboarding when every step fails', async () => {
		const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		const { ctx } = finalizeContext({
			ctx: {
				saveAiConsent: vi.fn(() => Promise.reject(new Error('nope'))),
				importStarterPool: vi.fn(() => Promise.reject(new Error('nope'))),
			},
		})

		await OnboardingRedesign.methods.finalize.call(ctx)

		expect(ctx.visible).toBe(false)
		expect(window.localStorage.getItem('learning:onboarding-seen:alex')).toBe('yes')

		vi.runAllTimers()
		expect(ctx.$emit).toHaveBeenCalledWith('done')
		errorSpy.mockRestore()
	})

	it('skips profile and consent when the user skipped, but still imports the pool', async () => {
		const { ctx, calls } = finalizeContext({ store: { skipped: true, selectedStarterPoolId: 42 } })

		await OnboardingRedesign.methods.finalize.call(ctx)

		// Skipping is a decision, so the server hears about it even though no profile is saved.
		expect(calls).toEqual(['pool', 'acknowledge'])
		expect(ctx.saveTelosProfile).not.toHaveBeenCalled()
		expect(ctx.saveAiConsent).not.toHaveBeenCalled()
	})
})
