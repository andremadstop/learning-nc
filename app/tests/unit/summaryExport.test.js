import { describe, it, expect, vi } from 'vitest'

// Mock @nextcloud/l10n before importing the module
vi.mock('@nextcloud/l10n', () => ({
	// marks translated strings so a test can tell a translated badge name from a raw id
	translate: (app, str) => (str.startsWith('badge.') ? `T(${str})` : str),
	getCanonicalLocale: () => 'en-US',
}))

const { summaryToMarkdown } = await import('../../src/utils/summary-export.js')

const mockSummary = {
	mastery: { total_mastered: 142, total_questions: 200, mastery_rate: 71.0 },
	sessions: { total_sessions: 45, total_questions: 1200, correct_answers: 960, overall_accuracy: 80.0 },
	xp: { total_xp: 4500, current_level: 12, current_streak: 7, total_sessions: 45, total_mastered: 142 },
	// the summary endpoint lists every badge with an earned flag (BadgeService::formatBadge)
	badges: [
		{ badge_id: 'streak_7', name: 'Week streak', name_key: 'badge.streak_7', earned: true, earned_at: 1711234567 },
		{ badge_id: 'pool_complete', name: 'Pool done', name_key: 'badge.pool_complete', earned: true, earned_at: 1711334567 },
		{ badge_id: 'duel_master', name: 'Duel master', name_key: 'badge.duel_master', earned: false, earned_at: null },
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

	// Audit #244: raw badge ids, unearned badges in the list and a fixed de-DE date.
	it('lists only earned badges, by their translated name', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		expect(md).toContain('(2)')
		expect(md).toContain('- T(badge.streak_7) (')
		expect(md).toContain('- T(badge.pool_complete) (')
		expect(md).not.toContain('duel_master')
		expect(md).not.toMatch(/^- streak_7/m)
	})

	it('formats dates in the Nextcloud locale', () => {
		const md = summaryToMarkdown(mockSummary, 'Security+', 'Andre')
		const expected = new Date(1711234567 * 1000).toLocaleDateString('en-US')
		expect(md).toContain(`- T(badge.streak_7) (${expected})`)
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
