/**
 * 2026-11 audit, high finding (answer key): GET /api/pools/{id}/questions handed every course member
 * answers[].is_correct and the explanation. The server now withholds them from everyone but the pool's
 * editors. The bot modes of Duel and Gameshow graded on the client with exactly that field, so they
 * now ask POST /api/questions/{id}/check, and Klaus only rolls whether he is right.
 *
 * Mounts the real components; the questions carry no is_correct, as the server now sends them.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace(/{(\w+)}/g, (_, key) => String(params[key]))),
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'student' })) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => ({ default: { name: 'QuestionLanguageSwitcher', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))

import axios from '@nextcloud/axios'
import DuelMode from '../../src/components/DuelMode.vue'
import GameshowMode from '../../src/components/GameshowMode.vue'

// What a learner now receives: no is_correct, no explanation.
const QUESTIONS = [
	{ id: 10, text: 'Which port does HTTPS use?', answers: [{ id: 100, text: '80' }, { id: 101, text: '443' }] },
	{ id: 11, text: 'Which port does SSH use?', answers: [{ id: 110, text: '22' }, { id: 111, text: '23' }] },
]

const MODES = [
	['DuelMode', DuelMode, 'startBotDuel'],
	['GameshowMode', GameshowMode, 'startBotGame'],
]

function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => { resolve = res; reject = rej })
	return { promise, resolve, reject }
}

async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

describe.each(MODES)('%s bot mode grades on the server', (name, Component, start) => {
	let app
	let host
	let vm

	beforeEach(async () => {
		vi.useFakeTimers()
		vi.clearAllMocks()
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/pools/42/questions') {
				return Promise.resolve({ data: QUESTIONS.map(q => ({ ...q, answers: q.answers.map(a => ({ ...a })) })) })
			}
			return Promise.resolve({ data: [] })
		})
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp(Component, { coursePools: [{ pool_id: 42, pool_name: 'Ports' }], initialPoolId: 42 })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		vm = app.mount(host)
		await flush()
		vm.selectedPoolId = 42
		await vm[start]()
		await flush()
		expect(vm.botMode).toBe(true)
		expect(vm.currentQuestion).toBeTruthy()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('posts the chosen answer to /check and takes the verdict from the server', async () => {
		const question = vm.currentQuestion
		const right = question.answers[1]
		axios.post.mockResolvedValueOnce({ data: { correct: true, correct_answer_id: right.id } })

		await vm.onAnswer(right.id)

		expect(axios.post).toHaveBeenCalledWith(`/apps/learning/api/questions/${question.id}/check`, { answerId: right.id })
		expect(vm.answeredCorrect).toBe(true)
		expect(vm.correctAnswerId).toBe(right.id)
		expect(vm.botLocalState.myScore).toBe(10)
	})

	it('a wrong answer scores nothing and still shows the right one', async () => {
		const question = vm.currentQuestion
		axios.post.mockResolvedValueOnce({ data: { correct: false, correct_answer_id: question.answers[1].id } })

		await vm.onAnswer(question.answers[0].id)

		expect(vm.answeredCorrect).toBe(false)
		expect(vm.correctAnswerId).toBe(question.answers[1].id)
		expect(vm.botLocalState.myScore).toBe(0)
	})

	it('a refused check (e.g. graded exam on the pool) lets the player answer again', async () => {
		const question = vm.currentQuestion
		axios.post.mockRejectedValueOnce({ response: { status: 403, data: { error: 'Not available during active exam' } } })

		await vm.onAnswer(question.answers[0].id)

		expect(vm.hasAnswered).toBe(false)
		expect(vm.error).toBe('Not available during active exam')
		expect(vm.correctAnswerId).toBe(null)
		expect(vm.botLocalState.currentIndex).toBe(0)
		expect(vm.phase).toBe('question')
	})

	it('Klaus answering while the check is pending does not skip the question', async () => {
		const question = vm.currentQuestion
		const pending = deferred()
		axios.post.mockReturnValueOnce(pending.promise)

		const answering = vm.onAnswer(question.answers[1].id)
		vm.botExecuteAnswer()
		await flush()
		expect(vm.phase).toBe('question')
		expect(vm.correctAnswerId).toBe(null)

		pending.resolve({ data: { correct: true, correct_answer_id: question.answers[1].id } })
		await answering
		await flush()

		expect(vm.correctAnswerId).toBe(question.answers[1].id)
		expect(vm.phase).toBe('feedback')
		vi.advanceTimersByTime(2000)
		await flush()
		expect(vm.botLocalState.currentIndex).toBe(1)
	})

	it('Klaus scores without knowing the solution', async () => {
		vi.spyOn(Math, 'random').mockReturnValue(0)
		const before = name === 'GameshowMode' && vm.isEliminationMode ? vm.botLocalState.botLives : vm.botLocalState.botScore

		vm.botExecuteAnswer()

		expect(vm.botHasAnswered).toBe(true)
		expect(vm.botLocalState.botScore).toBe(before + 10)
	})
})
