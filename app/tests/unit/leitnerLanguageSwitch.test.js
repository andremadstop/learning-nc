/**
 * Audit 2026-11 (S28): switching the question language during a Leitner review replaced the whole
 * queue with a fresh /leitner/due list. That list no longer contains the cards already rated in this
 * session (next_review moved into the future) and is re-sorted, while currentIndex stayed — so unseen
 * cards were skipped, the counter jumped and currentItem could become null. A language switch must
 * only swap the translated text, keeping queue length, order and the current card.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebrateMastery: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => stub('LevelUpOverlay'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import LeitnerMode from '../../src/components/LeitnerMode.vue'

function createInstance(props = {}) {
	const instance = { poolId: 3, courseId: null, contentLanguage: '', fsrsDetailedStats: false, ...props, $emit: vi.fn() }
	for (const mixin of LeitnerMode.mixins || []) {
		if (typeof mixin.data === 'function') Object.assign(instance, mixin.data.call(instance))
		for (const [name, fn] of Object.entries(mixin.methods || {})) instance[name] = fn.bind(instance)
	}
	Object.assign(instance, LeitnerMode.data.call(instance))
	for (const [name, def] of Object.entries(LeitnerMode.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(LeitnerMode.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function card(id, lang) {
	return {
		id,
		question_id: 1000 + id,
		box: 2,
		question_type: 'single',
		text: `${lang} question ${id}`,
		explanation: `${lang} explanation ${id}`,
		answers: [{ id: id * 10, text: `${lang} answer ${id}` }],
	}
}

describe('LeitnerMode question-language switch during a review', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('keeps queue length, order and the current card, and swaps in the translated text', async () => {
		const vm = createInstance()
		vm.started = true
		vm.dueQuestions = [1, 2, 3, 4, 5, 6].map((id) => card(id, 'de'))
		vm.currentIndex = 2 // cards 1 and 2 already rated this session
		vm.questionLanguage = 'en'

		// Server: rated cards 1 and 2 are no longer due, the rest comes back re-sorted.
		axios.get.mockResolvedValue({ data: [6, 4, 3, 5].map((id) => card(id, 'en')) })
		await vm.refreshDueQuestionsForLanguage()

		expect(vm.dueQuestions.map((q) => q.id)).toEqual([1, 2, 3, 4, 5, 6])
		expect(vm.currentIndex).toBe(2)
		expect(vm.currentItem.id).toBe(3)
		expect(vm.currentItem.text).toBe('en question 3')
		expect(vm.currentItem.answers[0].text).toBe('en answer 3')
		expect(vm.dueQuestions[5].text).toBe('en question 6')
		// cards the server no longer returns keep their (already seen) text
		expect(vm.dueQuestions[0].text).toBe('de question 1')
	})

	it('does not lose the current card when fewer cards are still due than the current index', async () => {
		const vm = createInstance()
		vm.started = true
		vm.dueQuestions = [1, 2, 3].map((id) => card(id, 'de'))
		vm.currentIndex = 2
		vm.questionLanguage = 'en'
		axios.get.mockResolvedValue({ data: [card(3, 'en')] })
		await vm.refreshDueQuestionsForLanguage()

		expect(vm.dueQuestions).toHaveLength(3)
		expect(vm.currentItem).not.toBeNull()
		expect(vm.currentItem.text).toBe('en question 3')
	})

	it('keeps scheduling fields of the local card (box set by an earlier rating)', async () => {
		const vm = createInstance()
		vm.started = true
		vm.dueQuestions = [card(1, 'de')]
		vm.dueQuestions[0].box = 4
		vm.questionLanguage = 'en'
		axios.get.mockResolvedValue({ data: [{ ...card(1, 'en'), box: 2 }] })
		await vm.refreshDueQuestionsForLanguage()
		expect(vm.dueQuestions[0].box).toBe(4)
		expect(vm.dueQuestions[0].text).toBe('en question 1')
	})
})
