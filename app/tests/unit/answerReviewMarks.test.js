/**
 * Persona sweep C9b (pre-existing since v2.7): after answering in Training, Leitner or Smart Queue
 * the review marked the options with `answer.is_correct`, which the server strips before a
 * question reaches a learner (TrainingService / LeitnerService). So a right answer was marked red
 * ("answer-wrong-selected") and the right option never green, while the note said "Correct!".
 * The answer response names the right options (correct_answer_ids); the review reads them.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn(), celebrateMastery: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => stub('LevelUpOverlay'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import { correctIdsFrom, isRightOption } from '../../src/utils/answerReview.js'
import TrainingMode from '../../src/components/TrainingMode.vue'
import LeitnerMode from '../../src/components/LeitnerMode.vue'
import SmartQueue from '../../src/components/SmartQueue.vue'

function createInstance(Component, extra = {}) {
	const props = {}
	for (const [name, def] of Object.entries(Component.props || {})) {
		props[name] = typeof def?.default === 'function' ? def.default() : def?.default
	}
	const instance = { ...props, ...extra, $emit: vi.fn(), $refs: {}, $nextTick: (fn) => fn && fn() }
	Object.assign(instance, Component.data.call(instance))
	for (const [name, def] of Object.entries(Component.computed || {})) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(Component.methods || {})) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

// what a learner gets: no is_correct on the options
const answers = [{ id: 7, text: 'Red' }, { id: 8, text: 'Green' }, { id: 9, text: 'Purple' }]

describe('answer review: the right options come from the answer response', () => {
	beforeEach(() => vi.clearAllMocks())

	it('correctIdsFrom reads correct_answer_ids, else correct_answer_id', () => {
		expect(correctIdsFrom({ correct_answer_ids: [8, '9'] })).toEqual([8, 9])
		expect(correctIdsFrom({ correct_answer_id: 8 })).toEqual([8])
		expect(correctIdsFrom({ correct_answer_id: null })).toEqual([])
		expect(correctIdsFrom(null)).toEqual([])
	})

	it('isRightOption: the flag where it is there, the ids where it is not', () => {
		expect(isRightOption({ id: 1, is_correct: true }, [])).toBe(true)
		expect(isRightOption({ id: 8 }, [8])).toBe(true)
		expect(isRightOption({ id: 7 }, [8])).toBe(false)
		expect(isRightOption(null, [8])).toBe(false)
	})

	it('Training: a right answer marks the chosen option as right, a wrong one marks the right option', async () => {
		const vm = createInstance(TrainingMode, { poolId: 1 })
		vm.session = 5
		vm.questions = [{ id: 30, pool_position: 1, text: 'Q', question_type: 'single', answers }]
		axios.post.mockResolvedValueOnce({ data: { is_correct: true, correct_answer_ids: [8], correct_answer_texts: ['Green'] } })
		await vm.submitAnswer(8)
		expect(vm.isRightOption(answers[1])).toBe(true)
		expect(vm.isRightOption(answers[0])).toBe(false)
	})

	it('Training: a jump back to an answered question keeps the marks', async () => {
		const vm = createInstance(TrainingMode, { poolId: 1 })
		vm.session = 5
		vm.questions = [1, 2].map((n) => ({ id: n * 10, pool_position: n, text: `Q${n}`, question_type: 'single', answers }))
		axios.post.mockResolvedValueOnce({ data: { is_correct: false, correct_answer_ids: [8], correct_answer_texts: ['Green'] } })
		await vm.submitAnswer(7)
		await vm.nextQuestion()
		expect(vm.isRightOption(answers[1])).toBe(false)
		vm.jumpToQuestion(0)
		expect(vm.isRightOption(answers[1])).toBe(true)
	})

	it('Leitner: the preview and the final review name the right options', () => {
		const vm = createInstance(LeitnerMode)
		vm.applyAnswerPreview({ answerId: 8 }, { correct: true, correct_answer_ids: [8], correct_answer_texts: ['Green'] })
		expect(vm.isRightOption(answers[1])).toBe(true)
		expect(vm.isRightOption(answers[0])).toBe(false)
		vm.applyFinalReview({ new_box: 2, rating: 3 }, 3)
		expect(vm.isRightOption(answers[1])).toBe(true)
	})

	it('Smart Queue: the answer response names the right options', () => {
		const vm = createInstance(SmartQueue)
		vm.items = [{ id: 1, pool_name: 'P', answers }]
		vm.currentIndex = 0
		vm.handleAnswerResponse({ correct: false, correct_answer_ids: [8], correct_answer_texts: ['Green'] })
		expect(vm.isRightOption(answers[1])).toBe(true)
		expect(vm.isRightOption(answers[0])).toBe(false)
	})

	it('the review markup of all three modes marks through isRightOption, not answer.is_correct', () => {
		for (const file of ['TrainingMode.vue', 'LeitnerMode.vue', 'SmartQueue.vue']) {
			const source = readFileSync(resolve(__dirname, '../../src/components', file), 'utf8')
			const template = source.slice(0, source.indexOf('<script'))
			const review = template.slice(template.indexOf('class="answer-btn-review"'))
			const binding = review.slice(0, review.indexOf('}">') + 3)
			expect(binding, file).toMatch(/'answer-correct':\s*isRightOption\(answer\)/)
			expect(binding, file).toMatch(/&&\s*!isRightOption\(answer\)/)
			expect(binding, file).not.toMatch(/answer\.is_correct/)
		}
	})
})
