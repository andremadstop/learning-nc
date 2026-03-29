import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace('{courseId}', String(params.courseId))),
}))

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

import axios from '@nextcloud/axios'
import CourseSummary from '../../src/components/CourseSummary.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(overrides = {}) {
	const data = typeof CourseSummary.data === 'function' ? CourseSummary.data() : {}
	const instance = {
		...data,
		courseId: 7,
		courseName: 'Network+ Bootcamp',
		...overrides,
	}

	Object.defineProperties(instance, {
		mastery: { get: () => CourseSummary.computed.mastery.call(instance) },
		sessions: { get: () => CourseSummary.computed.sessions.call(instance) },
		xp: { get: () => CourseSummary.computed.xp.call(instance) },
		streak: { get: () => CourseSummary.computed.streak.call(instance) },
		duels: { get: () => CourseSummary.computed.duels.call(instance) },
		swarm: { get: () => CourseSummary.computed.swarm.call(instance) },
		earnedBadges: { get: () => CourseSummary.computed.earnedBadges.call(instance) },
		troubleSpots: { get: () => CourseSummary.computed.troubleSpots.call(instance) },
		masteryCircumference: { get: () => CourseSummary.computed.masteryCircumference.call(instance) },
		masteryDashOffset: { get: () => CourseSummary.computed.masteryDashOffset.call(instance) },
	})

	for (const [name, fn] of Object.entries(CourseSummary.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('CourseSummary', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads summary data and tolerates missing snapshots', async () => {
		const instance = createInstance()
		axios.get
			.mockResolvedValueOnce({
				data: {
					mastery: { total_mastered: 142, total_questions: 200, mastery_rate: 71.0 },
					sessions: { total_sessions: 45, overall_accuracy: 80.0 },
					xp: { total_xp: 4500, current_level: 12 },
					badges: [],
					streak: { current_streak: 7, longest_streak: 14 },
					swarm: { total_contributions: 5, approved_contributions: 3 },
					duels: { total_duels: 12, wins: 8, win_rate: 66.7 },
					trouble_spots: [{ question_id: 42, attempts: 5, accuracy: 20 }],
				},
			})
			.mockRejectedValueOnce({ response: { status: 404 } })

		await instance.loadSummaryState()

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary/snapshot')
		expect(instance.summary.mastery.mastery_rate).toBe(71)
		expect(instance.snapshot).toBeNull()
		expect(instance.loading).toBe(false)
		expect(instance.error).toBe('')
	})

	it('filters earned badges for the gallery and caps trouble spots at five', () => {
		const instance = createInstance({
			summary: {
				badges: [
					{ badge_id: 'streak_7', earned: true },
					{ badge_id: 'perfect_exam', earned: false },
					{ badge_id: 'mastermind_10', earned: true },
				],
				trouble_spots: Array.from({ length: 7 }, (_, index) => ({
					question_id: index + 1,
					attempts: 3,
					accuracy: 20,
				})),
			},
		})

		expect(instance.earnedBadges.map((badge) => badge.badge_id)).toEqual(['streak_7', 'mastermind_10'])
		expect(instance.troubleSpots).toHaveLength(5)
	})

	it('creates a snapshot and refreshes snapshot state afterwards', async () => {
		vi.useFakeTimers()
		const instance = createInstance({
			summary: {
				mastery: { mastery_rate: 80 },
				badges: [],
				trouble_spots: [],
			},
		})
		axios.post.mockResolvedValueOnce({ data: { id: 99 } })
		axios.get.mockResolvedValueOnce({
			data: {
				id: 99,
				created_at: 1711234567,
				snapshot_data: {},
			},
		})

		await instance.saveSnapshot()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/summary/snapshot')
		expect(instance.snapshot.id).toBe(99)
		expect(instance.snapshotSaved).toBe(true)

		vi.runAllTimers()
		expect(instance.snapshotSaved).toBe(false)
		vi.useRealTimers()
	})
})
