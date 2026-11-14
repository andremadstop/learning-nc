import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({ default: { name: 'NcProgressBar', template: '<div />' } }))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => ({ default: { name: 'QuestionLanguageSwitcher', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))
vi.mock('../../src/utils/sse-client.js', () => ({ createSseClient: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

import GameshowMode from '../../src/components/GameshowMode.vue'

globalThis.t = (app, text) => text

/**
 * The player who let the 15 s timer run out (no answer) gets the next question once the server
 * moves on (see GameshowService: a poll after the deadline records the miss). Before, the index
 * advance only reached syncTimer: the new question showed with a countdown that never ran
 * (the interval had stopped at 0) and a stale lastQuestionIndex.
 */
function createInstance(overrides = {}) {
	const instance = {
		...GameshowMode.data.call({ contentLanguage: '' }),
		courseId: 5,
		coursePools: null,
		contentLanguage: '',
		mode: 'sprint',
		initialPoolId: null,
		$emit: vi.fn(),
		$nextTick: (fn) => fn && fn(),
		...overrides,
	}
	for (const [name, def] of Object.entries(GameshowMode.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(GameshowMode.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function stateAt(index, startedAt, status = 'active') {
	return {
		code: 'abc123',
		status,
		mode: 'sprint',
		current_question_index: index,
		total_questions: 3,
		question_started_at: startedAt,
		my_slot: 1,
		players: [
			{ user_id: 'alice', display_name: 'alice', slot: 0, score: 700, lives: 3, is_removed: false, answered: false },
			{ user_id: 'bob', display_name: 'bob', slot: 1, score: 0, lives: 3, is_removed: false, answered: false },
		],
		current_question: { id: 10 + index, text: 'Q' + index, answers: [{ id: 1, text: 'A' }, { id: 2, text: 'B' }] },
	}
}

describe('GameshowMode: a question the player let run out', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-12T10:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the next question with a running timer once the server moves on', () => {
		const now = Date.now()
		const vm = createInstance({
			phase: 'question',
			hasAnswered: false,
			lastQuestionIndex: -1,
			timerValue: 0,
			state: stateAt(0, now - 19000),
		})
		expect(vm.timerExpired).toBe(true)

		vm.applyStateTransitions(stateAt(1, now))

		expect(vm.phase).toBe('question')
		expect(vm.currentQuestion.id).toBe(11)
		expect(vm.lastQuestionIndex).toBe(0)
		expect(vm.hasAnswered).toBe(false)
		expect(vm.timerExpired).toBe(false)
		expect(vm.timerValue).toBe(15)

		vi.advanceTimersByTime(1000)
		expect(vm.timerValue).toBe(14)
		vm.stopTimer()
	})

	it('treats the next answer as the answer to the new question', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const now = Date.now()
		const vm = createInstance({
			phase: 'question',
			hasAnswered: false,
			lastQuestionIndex: -1,
			timerValue: 0,
			state: stateAt(0, now - 19000),
		})
		vm.applyStateTransitions(stateAt(1, now))
		axios.post.mockResolvedValueOnce({ data: { ...stateAt(1, now), correct_answer_id: 1, my_answer_correct: true } })

		await vm.onAnswer(1)

		expect(axios.post).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(3000)
		// Waiting for alice on question 2, not a feedback round as if the index had jumped.
		expect(vm.phase).toBe('question')
		expect(vm.hasAnswered).toBe(true)
		vm.stopTimer()
	})
})

/**
 * Audit #160: during the reveal the server already points at the next question but hides it
 * (current_question null) until shortly before its start. A client whose last state still lacks
 * it when the question phase begins fetches the state once instead of showing an empty question.
 */
describe('GameshowMode: a question the server still hides', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-12T10:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('fetches the state when the question starts and it is still missing', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
		const now = Date.now()
		const vm = createInstance({
			gameshowCode: 'abc123',
			phase: 'question',
			hasAnswered: false,
			lastQuestionIndex: -1,
			timerValue: 0,
			state: stateAt(0, now - 19000),
		})
		const hidden = { ...stateAt(1, now + 4000), current_question: null, server_now_ms: now }
		vm.applyStateTransitions(hidden)

		expect(vm.phase).toBe('leaderboard')
		expect(vm.currentQuestion).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockResolvedValueOnce({ data: { ...stateAt(1, now + 4000), server_now_ms: now + 4000 } })
		vi.advanceTimersByTime(4000)
		await Promise.resolve()
		await Promise.resolve()

		expect(vm.phase).toBe('question')
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toBe('/apps/learning/api/gameshow/abc123/state')
		expect(vm.currentQuestion.id).toBe(11)
		vm.stopTimer()
	})

	it('does not fetch when the pushed state already carries the question', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
		const now = Date.now()
		const vm = createInstance({
			gameshowCode: 'abc123',
			phase: 'question',
			hasAnswered: false,
			lastQuestionIndex: -1,
			timerValue: 0,
			state: stateAt(0, now - 19000),
		})
		vm.applyStateTransitions({ ...stateAt(1, now + 4000), current_question: null, server_now_ms: now })
		vm.applyStateTransitions({ ...stateAt(1, now + 4000), server_now_ms: now })
		vi.advanceTimersByTime(4000)

		expect(vm.phase).toBe('question')
		expect(vm.currentQuestion.id).toBe(11)
		expect(axios.get).not.toHaveBeenCalled()
		vm.stopTimer()
	})
})
