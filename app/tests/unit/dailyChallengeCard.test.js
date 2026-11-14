/**
 * DailyChallengeCard (audit #14, #96).
 *
 * #96: the midnight refresh fired while the countdown read 0, i.e. in the last second BEFORE UTC
 * midnight, so the server still answered with the old, completed challenge, and there was no
 * second attempt. The refresh now triggers on the wrap of the countdown.
 * #14: the answer carries the question id the card shows, and a 409 (today's question changed)
 * reloads the card instead of being shown as a failure.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import DailyChallengeCard from '../../src/components/DailyChallengeCard.vue'

function createVm() {
	const vm = {}
	Object.assign(vm, DailyChallengeCard.data.call(vm))
	for (const [name, fn] of Object.entries(DailyChallengeCard.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('DailyChallengeCard midnight refresh', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		axios.get.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('reloads the challenge after UTC midnight, not in the last second before it', async () => {
		vi.setSystemTime(new Date('2026-11-14T23:59:58.400Z'))
		const vm = createVm()
		vm.dailyChallenge = { available: true, completed: true, question: { id: 1 } }
		const requestTimes = []
		axios.get.mockImplementation(() => {
			requestTimes.push(Date.now())
			return Promise.resolve({ data: { available: true, completed: false, question: { id: 2 } } })
		})
		vm.startChallengeCountdown()

		await vi.advanceTimersByTimeAsync(3000)
		vm.stopChallengeCountdown()

		expect(requestTimes).toHaveLength(1)
		expect(requestTimes[0]).toBeGreaterThanOrEqual(Date.parse('2026-11-15T00:00:00.000Z'))
		expect(vm.dailyChallenge.question.id).toBe(2)
	})

	it('reloads once even when a throttled tab skipped the last seconds', async () => {
		vi.setSystemTime(new Date('2026-11-14T23:59:50.000Z'))
		const vm = createVm()
		vm.dailyChallenge = { available: true, completed: true, question: { id: 1 } }
		axios.get.mockResolvedValue({ data: { available: true, completed: false, question: { id: 2 } } })
		vm.updateChallengeCountdown()
		vi.setSystemTime(new Date('2026-11-15T00:00:20.000Z'))
		vm.updateChallengeCountdown()
		await vi.advanceTimersByTimeAsync(0)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('does not reload while the day is still running', async () => {
		vi.setSystemTime(new Date('2026-11-14T12:00:00.000Z'))
		const vm = createVm()
		vm.dailyChallenge = { available: true, completed: true, question: { id: 1 } }
		vm.startChallengeCountdown()
		await vi.advanceTimersByTimeAsync(5000)
		vm.stopChallengeCountdown()
		expect(axios.get).not.toHaveBeenCalled()
	})
})

describe('DailyChallengeCard answer', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		showError.mockReset()
	})

	it('sends the id of the question it shows', async () => {
		const vm = createVm()
		vm.dailyChallenge = { available: true, completed: false, question: { id: 42, question_type: 'single' } }
		vm.challengeSelectedIds = [7]
		axios.post.mockResolvedValue({ data: { correct: false, xp_earned: 0 } })
		axios.get.mockResolvedValue({ data: vm.dailyChallenge })
		await vm.submitChallenge()
		expect(axios.post.mock.calls[0][1]).toEqual({ question_id: 42, answer_ids: [7] })
	})

	it('reloads the card when the server says today\'s question changed', async () => {
		const vm = createVm()
		vm.dailyChallenge = { available: true, completed: false, question: { id: 42, question_type: 'single' } }
		vm.challengeSelectedIds = [7]
		axios.post.mockRejectedValue({ response: { status: 409, data: { error: 'The daily challenge has changed' } } })
		axios.get.mockResolvedValue({ data: { available: true, completed: false, question: { id: 43, question_type: 'single' } } })
		await vm.submitChallenge()
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(vm.dailyChallenge.question.id).toBe(43)
		expect(vm.challengeSelectedIds).toEqual([])
		expect(vm.challengeSubmitting).toBe(false)
		expect(showError).toHaveBeenCalledWith('The daily challenge has changed. Please answer the new question.')
	})
})
