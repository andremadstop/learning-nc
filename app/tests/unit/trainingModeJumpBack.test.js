/**
 * Audit #42: the training search jumped back to an answered question and showed the answer
 * form again. The server accepts one answer per question, so every click failed and the learner
 * was stuck. A jump to an answered question now shows its stored feedback, and the explanation
 * of the previous question never appears under another one.
 *
 * The component definition is exercised directly (same convention as examModeAutoSubmit.test.js).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => stub('LevelUpOverlay'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import TrainingMode from '../../src/components/TrainingMode.vue'

function createInstance() {
	const props = {}
	for (const [name, def] of Object.entries(TrainingMode.props || {})) {
		props[name] = typeof def.default === 'function' ? def.default() : def.default
	}
	const instance = { ...props, poolId: 42, $emit: vi.fn(), $refs: {}, $nextTick: (fn) => fn && fn() }
	Object.assign(instance, TrainingMode.data.call(instance))
	for (const [name, def] of Object.entries(TrainingMode.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(TrainingMode.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function questions() {
	return [1, 2, 3].map((n) => ({
		id: n * 10,
		pool_position: n,
		text: `Question ${n}`,
		question_type: 'single',
		answers: [{ id: n * 100, text: `A${n}` }, { id: n * 100 + 1, text: `B${n}` }],
	}))
}

describe('TrainingMode — jumping to an answered question (audit #42)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows the stored feedback instead of an answer form', async () => {
		const vm = createInstance()
		vm.session = 5
		vm.questions = questions()
		axios.post.mockResolvedValueOnce({ data: { is_correct: false, correct_answer_texts: ['B1'] } })
		await vm.submitAnswer(100)
		await vm.nextQuestion()
		expect(vm.currentIndex).toBe(1)
		expect(vm.answered).toBe(false)

		vm.questionSearchTerm = '#1'
		vm.onQuestionSearch()
		vm.jumpToFirstResult()

		expect(vm.currentIndex).toBe(0)
		expect(vm.answered).toBe(true)
		expect(vm.isCorrect).toBe(false)
		expect(vm.correctAnswerTexts).toEqual(['B1'])
		expect(vm.selectedAnswerId).toBe(100)
	})

	it('an unanswered question still gets the answer form', () => {
		const vm = createInstance()
		vm.session = 5
		vm.questions = questions()
		vm.jumpToQuestion(2)
		expect(vm.currentIndex).toBe(2)
		expect(vm.answered).toBe(false)
	})

	it('a jump clears the explanation of the previous question', () => {
		const vm = createInstance()
		vm.session = 5
		vm.questions = questions()
		vm.answered = true
		vm.explainText = 'Explanation of question 1'
		vm.explainTaskId = 'task-1'
		vm.explainLoading = true
		vm.pbqPoints = 3
		vm.explainPollTimer = setInterval(() => {}, 1000)

		vm.jumpToQuestion(2)

		expect(vm.explainText).toBe('')
		expect(vm.explainTaskId).toBeNull()
		expect(vm.explainLoading).toBe(false)
		expect(vm.explainPollTimer).toBeNull()
		expect(vm.pbqPoints).toBe(0)
	})

	it('skipping clears the explanation too', () => {
		const vm = createInstance()
		vm.session = 5
		vm.questions = questions()
		vm.explainText = 'stale'
		vm.skipQuestion()
		expect(vm.currentIndex).toBe(1)
		expect(vm.explainText).toBe('')
	})
})
