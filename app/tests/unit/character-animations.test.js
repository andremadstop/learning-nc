import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	playCelebrate,
	playShrug,
	playWave,
	setAnimationsEnabledGetter,
} from '../../src/utils/character-animations.js'

function mockMatchMedia(matches) {
	Object.defineProperty(window, 'matchMedia', {
		configurable: true,
		writable: true,
		value: vi.fn().mockReturnValue({
			matches,
			media: '(prefers-reduced-motion: reduce)',
			addEventListener: () => {},
			removeEventListener: () => {},
		}),
	})
}

function fakeElement() {
	return {
		animate: vi.fn().mockReturnValue({ finished: Promise.resolve() }),
	}
}

describe('character-animations WAAPI helpers', () => {
	beforeEach(() => {
		mockMatchMedia(false)
		setAnimationsEnabledGetter(() => true)
	})

	afterEach(() => {
		setAnimationsEnabledGetter(() => true)
		vi.restoreAllMocks()
	})

	describe.each([
		['playWave', playWave],
		['playCelebrate', playCelebrate],
		['playShrug', playShrug],
	])('%s', (name, helper) => {
		it('invokes element.animate() when reduced-motion is not preferred', async () => {
			const el = fakeElement()
			const result = await helper(el)

			expect(el.animate).toHaveBeenCalledTimes(1)
			expect(result).not.toBeNull()
		})

		it('does not invoke element.animate() when prefers-reduced-motion: reduce matches', async () => {
			mockMatchMedia(true)
			const el = fakeElement()
			const result = await helper(el)

			expect(el.animate).not.toHaveBeenCalled()
			expect(result).toBeNull()
		})

		it('does not invoke element.animate() when manual override returns false', async () => {
			setAnimationsEnabledGetter(() => false)
			const el = fakeElement()
			const result = await helper(el)

			expect(el.animate).not.toHaveBeenCalled()
			expect(result).toBeNull()
		})

		it('tolerates a null element target without throwing', async () => {
			await expect(helper(null)).resolves.toBeNull()
		})

		it('uses transform and opacity only in WAAPI keyframes', async () => {
			const el = fakeElement()
			await helper(el)
			const [keyframes] = el.animate.mock.calls[0]

			for (const keyframe of keyframes) {
				expect(Object.keys(keyframe).sort()).toEqual(
					expect.arrayContaining(['transform']),
				)
				expect(Object.keys(keyframe).every((key) => ['transform', 'opacity'].includes(key))).toBe(true)
			}
		})
	})

	describe('setAnimationsEnabledGetter', () => {
		it('updates the module-level override reference', async () => {
			const el = fakeElement()
			setAnimationsEnabledGetter(() => true)
			await playWave(el)
			expect(el.animate).toHaveBeenCalledTimes(1)

			const el2 = fakeElement()
			setAnimationsEnabledGetter(() => false)
			await playWave(el2)
			expect(el2.animate).not.toHaveBeenCalled()
		})
	})
})
