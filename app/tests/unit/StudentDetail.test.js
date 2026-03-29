import { describe, expect, it } from 'vitest'

import StudentDetail from '../../src/components/StudentDetail.vue'

const translations = {
	badge_pioneer_name: 'Erster Schritt',
	badge_pioneer_trigger: 'Erreiche 50 beantwortete Fragen',
	badge_streak_7_name: 'Wochen-Held',
}

globalThis.t = (app, text, vars = {}) => {
	const resolved = translations[text] || text
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), resolved)
}

function createInstance(overrides = {}) {
	const data = typeof StudentDetail.data === 'function' ? StudentDetail.data() : {}
	const instance = {
		...data,
		courseId: 11,
		studentId: 'alice',
		data: null,
		...overrides,
	}

	Object.defineProperties(instance, {
		activeBadges: { get: () => StudentDetail.computed.activeBadges.call(instance) },
		activeBadgeProgress: { get: () => StudentDetail.computed.activeBadgeProgress.call(instance) },
		earnedActiveBadgeCount: { get: () => StudentDetail.computed.earnedActiveBadgeCount.call(instance) },
		earnedLegacyBadges: { get: () => StudentDetail.computed.earnedLegacyBadges.call(instance) },
	})

	for (const [name, fn] of Object.entries(StudentDetail.methods || {})) {
		instance[name] = fn.bind(instance)
	}

	return instance
}

describe('StudentDetail badge archive logic', () => {
	it('separates active badges from earned legacy badges and localizes active labels', () => {
		const instance = createInstance({
			data: {
				badges: [
					{
						badge_id: 'first_session',
						name: 'First Steps',
						description: 'Complete your first session',
						legacy: true,
						earned: true,
						earned_at: 1711234567,
					},
					{
						badge_id: 'streak_7',
						name: 'Week Hero',
						name_key: 'badge_streak_7_name',
						description: 'Build a 7-day learning streak',
						legacy: false,
						earned: true,
						earned_at: 1712234567,
					},
					{
						badge_id: 'pioneer',
						name: 'First Step',
						name_key: 'badge_pioneer_name',
						trigger: 'Reach 50 answered questions',
						trigger_key: 'badge_pioneer_trigger',
						description: 'Answer 50 questions',
						legacy: false,
						earned: false,
					},
					{
						badge_id: 'streak_gold',
						name: 'Streak Tier Gold',
						description: 'Reach a 45-day streak',
						legacy: true,
						earned: false,
					},
				],
				badge_progress: [
					{
						badge_id: 'pioneer',
						name: 'First Step',
						name_key: 'badge_pioneer_name',
						trigger: 'Reach 50 answered questions',
						trigger_key: 'badge_pioneer_trigger',
						legacy: false,
						percentage: 40,
						current: 20,
						target: 50,
					},
					{
						badge_id: 'mastermind_10',
						name: 'Rising Star',
						legacy: true,
						percentage: 100,
						current: 10,
						target: 10,
					},
				],
			},
		})

		expect(instance.activeBadges.map((badge) => badge.badge_id)).toEqual(['pioneer', 'streak_7'])
		expect(instance.earnedActiveBadgeCount).toBe(1)
		expect(instance.earnedLegacyBadges.map((badge) => badge.badge_id)).toEqual(['first_session'])
		expect(instance.activeBadgeProgress.map((badge) => badge.badge_id)).toEqual(['pioneer'])
		expect(instance.badgeLabel(instance.activeBadges[0])).toBe('Erster Schritt')
		expect(instance.badgeTrigger(instance.activeBadges[0])).toBe('Erreiche 50 beantwortete Fragen')
		expect(instance.badgeLabel(instance.activeBadges[1])).toBe('Wochen-Held')
	})
})
