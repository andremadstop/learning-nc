import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({ default: { name: 'NcProgressBar', template: '<div />' } }))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => ({ default: { name: 'QuestionLanguageSwitcher', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))
vi.mock('../../src/utils/sse-client.js', () => ({ createSseClient: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))
vi.mock('../../src/confetti.js', () => ({ celebrateGameshowWin: vi.fn() }))

import DuelMode from '../../src/components/DuelMode.vue'
import GameshowMode from '../../src/components/GameshowMode.vue'
import { celebrateGameshowWin } from '../../src/confetti.js'

globalThis.t = (app, text) => text

function createInstance(Component, overrides = {}) {
	const instance = {
		...Component.data.call({ contentLanguage: '' }),
		courseId: 5,
		coursePools: null,
		contentLanguage: '',
		mode: 'sprint',
		initialPoolId: null,
		presetDuelCode: null,
		$emit: vi.fn(),
		$nextTick: (fn) => fn && fn(),
		...overrides,
	}
	for (const [name, def] of Object.entries(Component.computed || {})) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(Component.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-11-14T10:00:00Z'))
})

afterEach(() => {
	vi.useRealTimers()
	vi.clearAllMocks()
})

describe('game pauses are cancelled when the game is left (audit #97)', () => {
	it('DuelMode: Abbrechen during the bot feedback pause stays on the join screen', () => {
		const vm = createInstance(DuelMode, { botMode: true, phase: 'question' })
		vm.botLocalState.totalQuestions = 5
		vm.scheduleBotAnswer = vi.fn()

		vm.botAdvanceQuestion()
		expect(vm.phase).toBe('feedback')
		vm.cancelDuel()
		vi.advanceTimersByTime(5000)

		expect(vm.phase).toBe('join')
		expect(vm.scheduleBotAnswer).not.toHaveBeenCalled()
	})

	it('GameshowMode: Abbrechen during the reveal does not restart the question timer', () => {
		const vm = createInstance(GameshowMode, { phase: 'question', state: { question_started_at: Date.now(), players: [] } })
		vm.startTimer = vi.fn()

		vm.showFeedbackThenNext(1)
		vm.cancelGame()
		vi.advanceTimersByTime(10000)

		expect(vm.phase).toBe('join')
		expect(vm.startTimer).not.toHaveBeenCalled()
	})

	it('GameshowMode: a bot pause that is not cancelled still runs', () => {
		const vm = createInstance(GameshowMode, { botMode: true, phase: 'question' })
		vm.botLocalState.totalQuestions = 5
		vm.scheduleBotAnswer = vi.fn()

		vm.botAdvanceQuestion()
		vi.advanceTimersByTime(2000)

		expect(vm.phase).toBe('question')
		expect(vm.scheduleBotAnswer).toHaveBeenCalledTimes(1)
		expect(vm.pendingTimeouts).toEqual([])
	})
})

describe('GameshowMode timer uses the server clock (audit #160)', () => {
	it('counts from the server time when this device runs behind', () => {
		const serverNow = Date.now() + 4000 // device clock 4 s behind the server
		const vm = createInstance(GameshowMode, { phase: 'question' })
		vm.state = { question_started_at: serverNow - 5000, server_now_ms: serverNow }
		GameshowMode.watch.state.call(vm, vm.state)

		vm.startTimer()
		expect(vm.timerValue).toBe(10)
		vm.stopTimer()
	})

	it('never shows more than 15 s for a question that starts after the reveal', () => {
		const vm = createInstance(GameshowMode, { phase: 'question' })
		vm.state = { question_started_at: Date.now() + 3000, server_now_ms: Date.now() }
		GameshowMode.watch.state.call(vm, vm.state)

		vm.startTimer()
		expect(vm.timerValue).toBe(15)
		vm.stopTimer()
	})
})

describe('GameshowMode confetti (audit #99)', () => {
	it('fires through the ESM confetti helper', () => {
		const vm = createInstance(GameshowMode)
		vm.fireConfetti()
		expect(celebrateGameshowWin).toHaveBeenCalledTimes(1)
	})
})
