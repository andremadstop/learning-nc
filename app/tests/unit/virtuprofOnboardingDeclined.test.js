import { describe, expect, it, vi } from 'vitest'

// VirtuProfFullscreen and VirtuProfBubble list a bare `t` in their methods and rely on the
// global Nextcloud provides at runtime. ES imports are hoisted, so assigning globalThis.t below
// the import statement is too late — the module graph is evaluated first.
vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
// VirtuProf pulls in VirtuProfBubble, which imports `translate` — a bare { t } mock makes the
// import itself explode before any test runs.
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
}))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'


/**
 * Codeberg #5 — "the help wizard appears again after updates or restarts".
 *
 * checkTelosOnboarding() read `onboarding_declined` from GET /api/profile/telos/status.
 * That endpoint only ever returns `onboarding_completed` (TelosController::getStatus), so the
 * key was always undefined -> falsy -> showWelcomeStep() ran on every single load, no matter
 * how often the user had declined. The value itself was persisted correctly, just delivered by
 * a different endpoint (/api/virtuprof/state) and already present on the component: loadState()
 * is awaited before checkTelosOnboarding(), and applyVirtuProfState() assigns it.
 *
 * This goes RED against the pre-fix component: showOnboardingIntro becomes true even though
 * the user declined.
 */
function makeContext(overrides = {}) {
	return {
		userRole: 'student',
		enabled: true,
		onboardingDeclined: false,
		telosProfileLoaded: false,
		telosSaved: false,
		telosForm: {},
		showOnboardingIntro: false,
		showWelcomeStep: VirtuProf.methods.showWelcomeStep,
		...overrides,
	}
}

describe('VirtuProf.checkTelosOnboarding', () => {
	it('does not show the intro when the user already declined it', async () => {
		// The status endpoint never carries onboarding_declined — that is the whole point.
		axios.get.mockResolvedValueOnce({ data: { onboarding_completed: false } })
		const ctx = makeContext({ onboardingDeclined: true })

		await VirtuProf.methods.checkTelosOnboarding.call(ctx)

		expect(ctx.showOnboardingIntro).toBe(false)
	})

	it('still shows the intro for a user who has neither completed nor declined', async () => {
		axios.get.mockResolvedValueOnce({ data: { onboarding_completed: false } })
		const ctx = makeContext({ onboardingDeclined: false })

		await VirtuProf.methods.checkTelosOnboarding.call(ctx)

		expect(ctx.showOnboardingIntro).toBe(true)
	})

	it('does not even ask the server when the user declined', async () => {
		axios.get.mockClear()
		const ctx = makeContext({ onboardingDeclined: true })

		await VirtuProf.methods.checkTelosOnboarding.call(ctx)

		expect(axios.get).not.toHaveBeenCalled()
	})
})
