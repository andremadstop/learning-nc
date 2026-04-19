import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	playCelebrate,
	playShrug,
	playWave,
	setAnimationsEnabledGetter,
} from '../../src/utils/character-animations.js'

function mockMatchMedia(matches) {
	window.matchMedia = vi.fn().mockReturnValue({
		matches,
		addEventListener: () => {},
		removeEventListener: () => {},
		media: '(prefers-reduced-motion: reduce)',
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
		vi.restoreAllMocks()
		setAnimationsEnabledGetter(() => true)
	})

	describe.each([
		['playWave', playWave],
		['playCelebrate', playCelebrate],
		['playShrug', playShrug],
	])('%s', (name, helper) => {
		it('invokes element.animate() when reduced motion is not preferred', async () => {
			const element = fakeElement()
			await helper(element)
			expect(element.animate).toHaveBeenCalledTimes(1)
		})

		it('does not invoke element.animate() when prefers-reduced-motion matches', async () => {
			mockMatchMedia(true)
			const element = fakeElement()
			await helper(element)
			expect(element.animate).not.toHaveBeenCalled()
		})

		it('does not invoke element.animate() when manual override returns false', async () => {
			setAnimationsEnabledGetter(() => false)
			const element = fakeElement()
			await helper(element)
			expect(element.animate).not.toHaveBeenCalled()
		})

		it('resolves with null when gated', async () => {
			mockMatchMedia(true)
			const result = await helper(fakeElement())
			expect(result).toBeNull()
		})

		it('tolerates null element targets', async () => {
			await expect(helper(null)).resolves.toBeNull()
		})
	})

	it('updates the module-level manual override getter', async () => {
		const first = fakeElement()
		await playWave(first)
		expect(first.animate).toHaveBeenCalledTimes(1)

		setAnimationsEnabledGetter(() => false)
		const second = fakeElement()
		await playWave(second)
		expect(second.animate).not.toHaveBeenCalled()
	})
})
