import { describe, expect, it } from 'vitest'

import {
	buildFsrsRatingOptions,
	initializeFsrsFromRating,
	previewFsrsReview,
} from '../../src/utils/fsrsScheduler.js'

describe('fsrsScheduler', () => {
	it('builds the default three-button rating model for learners', () => {
		const nowSeconds = 1_800_000_000
		const options = buildFsrsRatingOptions({
			stability: 4,
			difficulty: 0.55,
			last_reviewed: nowSeconds - 86400,
		}, false, nowSeconds)

		expect(options.map((entry) => ({
			id: entry.id,
			keyHint: entry.keyHint,
			rating: entry.rating,
		}))).toEqual([
			{ id: 'again', keyHint: '1', rating: 1 },
			{ id: 'hard', keyHint: '2', rating: 2 },
			{ id: 'easy', keyHint: '3', rating: 4 },
		])
		expect(options[0].preview.intervalDays).toBeGreaterThanOrEqual(1)
		expect(options[2].preview.intervalDays).toBeGreaterThanOrEqual(options[1].preview.intervalDays)
	})

	it('builds the detailed four-button architect view', () => {
		const options = buildFsrsRatingOptions({
			stability: 7,
			difficulty: 0.4,
			last_reviewed: 1_800_000_000 - (3 * 86400),
		}, true, 1_800_000_000)

		expect(options.map((entry) => entry.id)).toEqual(['again', 'hard', 'good', 'easy'])
		expect(options.map((entry) => entry.keyHint)).toEqual(['1', '2', '3', '4'])
	})

	it('initializes new cards and grows intervals for easier ratings', () => {
		const initial = initializeFsrsFromRating(3)
		const again = previewFsrsReview({ stability: 3, difficulty: 0.6, last_reviewed: 1_800_000_000 - 86400 }, 1, 1_800_000_000)
		const easy = previewFsrsReview({ stability: 3, difficulty: 0.6, last_reviewed: 1_800_000_000 - 86400 }, 4, 1_800_000_000)

		expect(initial.stability).toBeGreaterThan(0)
		expect(initial.difficulty).toBeGreaterThan(0)
		expect(easy.intervalDays).toBeGreaterThanOrEqual(again.intervalDays)
	})
})
