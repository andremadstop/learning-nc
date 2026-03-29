import { describe, it, expect, vi } from 'vitest'

// Mock @nextcloud/l10n before importing the module
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

const { summaryToMarkdown, summaryToJson } = await import('../../src/utils/summary-export.js')

const mockSummary = {
	mastery: { total_mastered: 142, total_questions: 200, mastery_rate: 71.0 },
	sessions: { total_sessions: 45, total_questions: 1200, correct_answers: 960, overall_accuracy: 80.0 },
	xp: { total_xp: 4500, current_level: 12, current_streak: 7, total_sessions: 45, total_mastered: 142 },
	badges: [
		{ badge_id: 'streak_7', earned_at: 1711234567 },
		{ badge_id: 'pool_complete', earned_at: 1711334567 },
	],
	streak: { current_streak: 7, longest_streak: 14, is_active_today: true, freeze_tokens: 2 },
	swarm: { total_contributions: 5, approved_contributions: 3 },
	duels: { total_duels: 12, wins: 8, win_rate: 66.7 },
	trouble_spots: [{ question_id: 42, attempts: 5, accuracy: 20.0 }],
}

describe('summaryToMarkdown', () => {
	it('contains course name and user', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('Security+')
		expect(md).toContain('Andre')
	})

	it('contains mastery stats', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('142')
		expect(md).toContain('71')
	})

	it('contains badge count', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('streak_7')
		expect(md).toContain('pool_complete')
	})

	it('contains duel stats', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('66.7')
	})

	it('contains trouble spots', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('#42')
		expect(md).toContain('20%')
	})

	it('contains swarm contributions', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('3')
		expect(md).toContain('5')
	})
})

describe('summaryToJson', () => {
	it('returns valid JSON with course and user', () => {
		const json = summaryToJson(mockSummary, 'Network+', 'Bob')
		const parsed = JSON.parse(json)
		expect(parsed.course).toBe('Network+')
		expect(parsed.user).toBe('Bob')
		expect(parsed.exported_at).toBeDefined()
	})

	it('includes all summary fields', () => {
		const json = summaryToJson(mockSummary, 'A+', 'Eve')
		const parsed = JSON.parse(json)
		expect(parsed.mastery.total_mastered).toBe(142)
		expect(parsed.duels.win_rate).toBe(66.7)
		expect(parsed.trouble_spots).toHaveLength(1)
	})
})

describe('edge cases', () => {
	it('handles empty summary gracefully', () => {
		const md = summaryToMarkdown({}, 'Empty', 'Nobody')
		expect(md).toContain('Empty')
		expect(md).toContain('0')
	})

	it('handles missing badges array', () => {
		const md = summaryToMarkdown({ badges: [] }, 'Test', 'User')
		expect(md).not.toContain('Abzeichen')
	})
})
