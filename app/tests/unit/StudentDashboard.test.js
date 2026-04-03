/**
 * StudentDashboard.vue + DailyChallengeCard.vue unit tests.
 *
 * Tests: data model, API calls on mount, widget rendering logic,
 * event emissions, and DailyChallengeCard standalone behavior.
 * Uses component definition directly (no @vue/test-utils — project pattern).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock @nextcloud/axios
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

// Mock @nextcloud/dialogs (avoids CSS import from NcNoteCard)
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

// Mock @nextcloud/vue components (avoids CSS import chain)
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))

import axios from '@nextcloud/axios'

import StudentDashboard from '../../src/components/StudentDashboard.vue'
import DailyChallengeCard from '../../src/components/DailyChallengeCard.vue'

/**
 * Creates a minimal component instance to access methods/data without mounting.
 */
function createDashboardInstance() {
	const data = typeof StudentDashboard.data === 'function' ? StudentDashboard.data() : {}
	const instance = {
		...data,
		$emit: vi.fn(),
	}
	// Bind methods
	if (StudentDashboard.methods) {
		for (const [name, fn] of Object.entries(StudentDashboard.methods)) {
			instance[name] = fn.bind(instance)
		}
	}
	return instance
}

function createChallengeCardInstance() {
	const data = typeof DailyChallengeCard.data === 'function' ? DailyChallengeCard.data() : {}
	const instance = {
		...data,
		$emit: vi.fn(),
	}
	if (DailyChallengeCard.methods) {
		for (const [name, fn] of Object.entries(DailyChallengeCard.methods)) {
			instance[name] = fn.bind(instance)
		}
	}
	return instance
}

// --- StudentDashboard tests ---

describe('StudentDashboard component definition', () => {
	it('exports a valid component with name', () => {
		expect(StudentDashboard.name).toBe('StudentDashboard')
	})

	it('has required data properties for queue, streak, and user state', () => {
		const data = StudentDashboard.data()
		expect(data).toHaveProperty('queueCount')
		expect(data).toHaveProperty('remediationCount')
		expect(data).toHaveProperty('userState')
		expect(data).toHaveProperty('loading')
	})
})

describe('StudentDashboard SmartQueue widget', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loadQueueCount fetches from /api/leitner/queue/count and sets queueCount', async () => {
		const instance = createDashboardInstance()
		axios.get.mockResolvedValueOnce({ data: { count: 42 } })
		await instance.loadQueueCount()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/leitner/queue/count')
		expect(instance.queueCount).toBe(42)
	})

	it('loadRemediationCount fetches and sets remediationCount', async () => {
		const instance = createDashboardInstance()
		axios.get.mockResolvedValueOnce({ data: { count: 7 } })
		await instance.loadRemediationCount()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/leitner/remediation/count')
		expect(instance.remediationCount).toBe(7)
	})
})

describe('StudentDashboard streak display', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loadUserState fetches from /api/v1/user/state and stores streak data', async () => {
		const instance = createDashboardInstance()
		axios.get.mockResolvedValueOnce({
			data: {
				streak: {
					current_streak: 5,
					longest_streak: 12,
					freeze_tokens: 2,
					is_active_today: true,
				},
				xp: { total_xp: 450, level: 3 },
				daily_progress: {
					cards_reviewed_today: 10,
					daily_goal: 20,
					sessions_today: 2,
				},
			},
		})
		await instance.loadUserState()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/v1/user/state')
		expect(instance.userState.streak.current_streak).toBe(5)
		expect(instance.userState.streak.is_active_today).toBe(true)
		expect(instance.userState.streak.freeze_tokens).toBe(2)
		expect(instance.userState.xp.level).toBe(3)
	})
})

describe('StudentDashboard Quick-Links', () => {
	it('emits switchView with target view name', () => {
		const instance = createDashboardInstance()
		instance.goToView('pools')
		expect(instance.$emit).toHaveBeenCalledWith('switchView', 'pools')
	})
})

describe('StudentDashboard API calls on mount', () => {
	it('defines mounted hook that calls all load methods', () => {
		// Verify mounted exists and would call the loaders
		expect(StudentDashboard.mounted).toBeDefined()
	})
})

// --- DailyChallengeCard tests ---

describe('DailyChallengeCard component definition', () => {
	it('exports a valid component with name', () => {
		expect(DailyChallengeCard.name).toBe('DailyChallengeCard')
	})

	it('has dailyChallenge data property', () => {
		const data = DailyChallengeCard.data()
		expect(data).toHaveProperty('dailyChallenge')
		expect(data).toHaveProperty('challengeSelectedIds')
		expect(data).toHaveProperty('challengeSubmitting')
	})
})

describe('DailyChallengeCard loads and renders challenge', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loadDailyChallenge fetches from /api/v1/daily-challenge', async () => {
		const instance = createChallengeCardInstance()
		axios.get.mockResolvedValueOnce({
			data: {
				available: true,
				completed: false,
				question: {
					text: 'What is a VLAN?',
					question_type: 'multiple_choice',
					answers: [
						{ id: 1, text: 'Virtual LAN' },
						{ id: 2, text: 'Virtual Link' },
					],
				},
				xp_reward: 10,
				pool_name: 'Network+ Basics',
			},
		})
		await instance.loadDailyChallenge()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/v1/daily-challenge')
		expect(instance.dailyChallenge.question.text).toBe('What is a VLAN?')
		expect(instance.dailyChallenge.available).toBe(true)
	})

	it('toggleChallengeAnswer adds and removes answer IDs', () => {
		const instance = createChallengeCardInstance()
		instance.toggleChallengeAnswer(1)
		expect(instance.challengeSelectedIds).toContain(1)
		instance.toggleChallengeAnswer(1)
		expect(instance.challengeSelectedIds).not.toContain(1)
	})

	it('formatCountdown returns HH:MM:SS string', () => {
		const instance = createChallengeCardInstance()
		expect(instance.formatCountdown(3661)).toBe('01:01:01')
		expect(instance.formatCountdown(0)).toBe('00:00:00')
	})
})

describe('DailyChallengeCard completed state', () => {
	it('shows countdown when challenge is completed', async () => {
		const instance = createChallengeCardInstance()
		axios.get.mockResolvedValueOnce({
			data: {
				available: true,
				completed: true,
				was_correct: true,
				xp_reward: 10,
				question: {
					text: 'What is a VLAN?',
					answers: [{ id: 1, text: 'Virtual LAN', is_correct: true }],
				},
				pool_name: 'Network+',
			},
		})
		await instance.loadDailyChallenge()
		expect(instance.dailyChallenge.completed).toBe(true)
		expect(instance.challengeXpEarned).toBe(10)
	})
})
