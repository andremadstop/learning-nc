export type FsrsRating = 1 | 2 | 3 | 4

export interface FsrsState {
	stability: number
	difficulty: number
	intervalDays: number
	retrievability: number
	last_reviewed?: number | null
}

export interface FsrsRatingOption {
	id: string
	keyHint: string
	rating: FsrsRating
	tone: string
	preview?: FsrsState
}

const W: number[] = [
	0.4, 0.6, 2.4, 5.8,
	4.93, 0.94, 0.86, 0.01,
	1.49, 0.14, 0.94, 2.18,
	0.05, 0.34, 1.26, 0.29,
	2.61,
]

export const DEFAULT_FSRS_RATING_OPTIONS: FsrsRatingOption[] = [
	{ id: 'again', keyHint: '1', rating: 1, tone: 'again' },
	{ id: 'hard', keyHint: '2', rating: 2, tone: 'hard' },
	{ id: 'easy', keyHint: '3', rating: 4, tone: 'easy' },
]

export const DETAILED_FSRS_RATING_OPTIONS: FsrsRatingOption[] = [
	{ id: 'again', keyHint: '1', rating: 1, tone: 'again' },
	{ id: 'hard', keyHint: '2', rating: 2, tone: 'hard' },
	{ id: 'good', keyHint: '3', rating: 3, tone: 'good' },
	{ id: 'easy', keyHint: '4', rating: 4, tone: 'easy' },
]

function clampDifficulty(difficulty: number): number {
	return Math.max(0.1, Math.min(1.0, difficulty))
}

function normalizeRating(rating: number): FsrsRating {
	if (![1, 2, 3, 4].includes(rating)) {
		throw new Error('FSRS rating must be between 1 and 4')
	}
	return rating as FsrsRating
}

export function calculateFsrsRetrievability(stability: number, elapsedDays: number): number {
	const safeStability = Math.max(0.1, Number(stability) || 0.1)
	const safeElapsedDays = Math.max(0, Number(elapsedDays) || 0)
	return Math.exp(Math.log(0.9) * safeElapsedDays / safeStability)
}

export function calculateFsrsIntervalDays(stability: number): number {
	return Math.max(1, Math.round(Math.max(0.1, Number(stability) || 0.1)))
}

export function initializeFsrsFromRating(rating: number): FsrsState {
	const normalizedRating = normalizeRating(rating)
	const stability = Math.max(0.1, W[normalizedRating - 1])
	const difficulty = clampDifficulty(W[4] - Math.exp(W[5] * (normalizedRating - 1)) + 1)

	return {
		stability,
		difficulty,
		intervalDays: calculateFsrsIntervalDays(stability),
		retrievability: 1.0,
	}
}

function updateDifficulty(difficulty: number, rating: FsrsRating): number {
	return clampDifficulty(difficulty - W[6] * (rating - 3))
}

function updateStability(stability: number, difficulty: number, retrievability: number, rating: FsrsRating): number {
	if (rating === 1) {
		return Math.max(
			0.1,
			W[11] * Math.pow(difficulty, -W[12]) * (Math.pow(stability + 1, W[13]) - 1) * Math.exp(W[14] * (1 - retrievability)),
		)
	}

	const hardPenalty = rating === 2 ? W[15] : 1.0
	const easyBonus = rating === 4 ? W[16] : 1.0
	const growth = Math.exp(W[8])
		* (11 - difficulty)
		* Math.pow(stability, -W[9])
		* (Math.exp((1 - retrievability) * W[10]) - 1)
		* hardPenalty
		* easyBonus

	return Math.max(0.1, stability * (1 + growth))
}

function resolveElapsedDays(item: Partial<FsrsState> | null | undefined, nowSeconds: number): number {
	const lastReviewed = Number(item?.last_reviewed || 0)
	if (lastReviewed <= 0) {
		return 0
	}
	return Math.max(0, (nowSeconds - lastReviewed) / 86400)
}

export function previewFsrsReview(
	item: Partial<FsrsState> | null | undefined,
	rating: number,
	nowSeconds = Math.floor(Date.now() / 1000),
): FsrsState {
	const normalizedRating = normalizeRating(rating)
	const stability = Number(item?.stability || 0)
	const difficulty = Number(item?.difficulty || 0)

	if (stability <= 0 || difficulty <= 0) {
		return initializeFsrsFromRating(normalizedRating)
	}

	const elapsedDays = resolveElapsedDays(item, nowSeconds)
	const retrievability = calculateFsrsRetrievability(stability, elapsedDays)
	const nextDifficulty = updateDifficulty(difficulty, normalizedRating)
	const nextStability = updateStability(stability, difficulty, retrievability, normalizedRating)

	return {
		stability: nextStability,
		difficulty: nextDifficulty,
		intervalDays: calculateFsrsIntervalDays(nextStability),
		retrievability,
	}
}

export function buildFsrsRatingOptions(
	item: Partial<FsrsState> | null | undefined,
	detailed = false,
	nowSeconds = Math.floor(Date.now() / 1000),
): FsrsRatingOption[] {
	const baseOptions = detailed ? DETAILED_FSRS_RATING_OPTIONS : DEFAULT_FSRS_RATING_OPTIONS
	return baseOptions.map(option => ({
		...option,
		preview: previewFsrsReview(item, option.rating, nowSeconds),
	}))
}

export function formatFsrsIntervalLabel(intervalDays: number): string {
	const safeDays = Math.max(1, Number(intervalDays) || 1)
	return safeDays === 1 ? '1 day' : `${safeDays} days`
}
