import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))

import axios from '@nextcloud/axios'
import OnboardingRedesign from '../../src/components/OnboardingRedesign.vue'

globalThis.t = (app, text) => text

/**
 * Codeberg #5: whether the wizard had been seen lived exclusively in localStorage, so it came
 * back on every new browser. The server has to learn about it — and crucially also when the user
 * skips, because OnboardingRedesign only saves the telos profile `if (!this.store.skipped)`.
 * Without this, moving the gate server-side would trap every skipper in the wizard forever.
 *
 * RED against the pre-fix component: no POST to the acknowledge endpoint at all.
 */
const ACK_URL = '/apps/learning/api/settings/personal/onboarding'

function makeContext(storeOverrides = {}) {
	return {
		store: {
			skipped: false,
			selectedStarterPoolId: null,
			profileChoices: { goal: null, intensity: null, aiConsent: 'no' },
			role: 'student',
			...storeOverrides,
		},
		visible: true,
		getUid: () => 'alice',
		saveTelosProfile: vi.fn(() => Promise.resolve()),
		saveAiConsent: vi.fn(() => Promise.resolve()),
		importStarterPool: vi.fn(() => Promise.resolve()),
		acknowledgeOnboarding: OnboardingRedesign.methods.acknowledgeOnboarding,
		runFinalizeStep: OnboardingRedesign.methods.runFinalizeStep,
		$emit: vi.fn(),
	}
}

describe('OnboardingRedesign.finalize', () => {
	beforeEach(() => {
		axios.post.mockClear()
		axios.post.mockImplementation(() => Promise.resolve({ data: {} }))
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('tells the server the onboarding is done', async () => {
		await OnboardingRedesign.methods.finalize.call(makeContext())

		expect(axios.post).toHaveBeenCalledWith(ACK_URL)
	})

	it('tells the server even when the user skipped', async () => {
		const ctx = makeContext({ skipped: true })

		await OnboardingRedesign.methods.finalize.call(ctx)

		expect(axios.post).toHaveBeenCalledWith(ACK_URL)
		expect(ctx.saveTelosProfile).not.toHaveBeenCalled()
	})

	it('still finishes for the user when the acknowledge call fails', async () => {
		axios.post.mockRejectedValueOnce(new Error('offline'))
		const ctx = makeContext()

		await OnboardingRedesign.methods.finalize.call(ctx)

		expect(ctx.visible).toBe(false)
	})
})
