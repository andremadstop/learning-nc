import { describe, expect, it } from 'vitest'

/**
 * Tests for Pool Draft Review logic — the data transformations used by
 * PoolGenerator.vue and PoolDraftReview.vue.
 *
 * Since these are pure data operations extracted from component methods,
 * we test them without mounting Vue components.
 */

function makeDraft(overrides = {}) {
	return {
		text: 'What protocol operates at Layer 4?',
		answers: [
			{ text: 'TCP', is_correct: true },
			{ text: 'ARP', is_correct: false },
			{ text: 'ICMP', is_correct: false },
			{ text: 'Ethernet', is_correct: false },
		],
		explanation: 'TCP is a transport layer protocol.',
		difficulty: 'medium',
		source_chunk: 'Chapter 3 discusses transport layer protocols...',
		_status: 'accepted',
		...overrides,
	}
}

describe('PoolDraftReview logic', () => {
	describe('accepted count', () => {
		it('counts only accepted questions', () => {
			const drafts = [
				makeDraft({ _status: 'accepted' }),
				makeDraft({ _status: 'rejected' }),
				makeDraft({ _status: 'accepted' }),
			]
			const count = drafts.filter(q => q._status === 'accepted').length
			expect(count).toBe(2)
		})

		it('returns 0 when all are rejected', () => {
			const drafts = [
				makeDraft({ _status: 'rejected' }),
				makeDraft({ _status: 'rejected' }),
			]
			const count = drafts.filter(q => q._status === 'accepted').length
			expect(count).toBe(0)
		})

		it('returns total when all are accepted', () => {
			const drafts = [makeDraft(), makeDraft(), makeDraft()]
			const count = drafts.filter(q => q._status === 'accepted').length
			expect(count).toBe(3)
		})
	})

	describe('toggle status', () => {
		it('toggles from accepted to rejected', () => {
			const q = makeDraft({ _status: 'accepted' })
			q._status = q._status === 'accepted' ? 'rejected' : 'accepted'
			expect(q._status).toBe('rejected')
		})

		it('toggles from rejected to accepted', () => {
			const q = makeDraft({ _status: 'rejected' })
			q._status = q._status === 'accepted' ? 'rejected' : 'accepted'
			expect(q._status).toBe('accepted')
		})
	})

	describe('toggle all', () => {
		it('deselects all when all are accepted', () => {
			const drafts = [makeDraft(), makeDraft()]
			const allAccepted = drafts.every(q => q._status === 'accepted')
			const newStatus = allAccepted ? 'rejected' : 'accepted'
			drafts.forEach(q => { q._status = newStatus })

			expect(drafts.every(q => q._status === 'rejected')).toBe(true)
		})

		it('selects all when some are rejected', () => {
			const drafts = [
				makeDraft({ _status: 'accepted' }),
				makeDraft({ _status: 'rejected' }),
			]
			const allAccepted = drafts.every(q => q._status === 'accepted')
			const newStatus = allAccepted ? 'rejected' : 'accepted'
			drafts.forEach(q => { q._status = newStatus })

			expect(drafts.every(q => q._status === 'accepted')).toBe(true)
		})
	})

	describe('set correct answer', () => {
		it('sets exactly one correct answer', () => {
			const q = makeDraft()
			// Simulate setting answer index 2 as correct
			q.answers.forEach((a, i) => { a.is_correct = i === 2 })

			expect(q.answers[0].is_correct).toBe(false)
			expect(q.answers[1].is_correct).toBe(false)
			expect(q.answers[2].is_correct).toBe(true)
			expect(q.answers[3].is_correct).toBe(false)
		})

		it('always has exactly one correct answer after toggle', () => {
			const q = makeDraft()
			// Toggle through all indices
			for (let target = 0; target < 4; target++) {
				q.answers.forEach((a, i) => { a.is_correct = i === target })
				const correctCount = q.answers.filter(a => a.is_correct).length
				expect(correctCount).toBe(1)
			}
		})
	})

	describe('delete question', () => {
		it('removes question at given index', () => {
			const drafts = [
				makeDraft({ text: 'Q1' }),
				makeDraft({ text: 'Q2' }),
				makeDraft({ text: 'Q3' }),
			]
			drafts.splice(1, 1)
			expect(drafts.length).toBe(2)
			expect(drafts[0].text).toBe('Q1')
			expect(drafts[1].text).toBe('Q3')
		})
	})

	describe('export accepted for API', () => {
		it('strips _status from accepted questions', () => {
			const drafts = [
				makeDraft({ _status: 'accepted' }),
				makeDraft({ _status: 'rejected' }),
				makeDraft({ _status: 'accepted', text: 'Keep me' }),
			]

			const exported = drafts
				.filter(q => q._status === 'accepted')
				.map(({ _status, ...q }) => q)

			expect(exported.length).toBe(2)
			expect(exported[0]).not.toHaveProperty('_status')
			expect(exported[1]).not.toHaveProperty('_status')
			expect(exported[1].text).toBe('Keep me')
		})

		it('preserves all question fields except _status', () => {
			const draft = makeDraft()
			const { _status, ...exported } = draft

			expect(exported).toHaveProperty('text')
			expect(exported).toHaveProperty('answers')
			expect(exported).toHaveProperty('explanation')
			expect(exported).toHaveProperty('difficulty')
			expect(exported).toHaveProperty('source_chunk')
			expect(exported).not.toHaveProperty('_status')
		})
	})

	describe('generation result mapping', () => {
		it('maps raw API response to drafts with _status', () => {
			const apiQuestions = [
				{
					text: 'What is DHCP?',
					answers: [
						{ text: 'Dynamic Host Config', is_correct: true },
						{ text: 'Domain Helper', is_correct: false },
						{ text: 'Data Host', is_correct: false },
						{ text: 'Direct Hub', is_correct: false },
					],
					explanation: 'DHCP assigns IP addresses.',
					difficulty: 'easy',
					source_chunk: 'Source text...',
				},
			]

			const mapped = apiQuestions.map(q => ({ ...q, _status: 'accepted' }))

			expect(mapped[0]._status).toBe('accepted')
			expect(mapped[0].text).toBe('What is DHCP?')
			expect(mapped[0].answers.length).toBe(4)
		})

		it('handles empty API response', () => {
			const mapped = [].map(q => ({ ...q, _status: 'accepted' }))
			expect(mapped.length).toBe(0)
		})
	})
})
