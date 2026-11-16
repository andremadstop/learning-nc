/**
 * Fresh-eyes C4 (persona run with AI off): after a wrong answer, Training offered
 * "Explain via VirtuProf". That button hands the question to the VirtuProf chat, which answers
 * with the AI, so with AI off it promised a chatbot the admin had switched off. It now follows
 * the same AI check as its neighbour "Explain this" (GET /api/ai/available). With AI on nothing
 * changes.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub, slotStub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, render: () => null } }),
	slotStub: (name, tag) => ({ default: { name, render() { return (globalThis.__h)(tag, {}, this.$slots.default?.()) } } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'div'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/QuestionHint.vue', () => stub('QuestionHint'))
vi.mock('../../src/components/RichFeedbackPanel.vue', () => stub('RichFeedbackPanel'))
vi.mock('../../src/components/QuestionMediaPlayer.vue', () => stub('QuestionMediaPlayer'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => stub('LevelUpOverlay'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

globalThis.__h = h
globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m)) : text)
globalThis.n = (app, one, many, count) => (count === 1 ? one : many)

import TrainingMode from '../../src/components/TrainingMode.vue'

let app = null
let host = null

async function renderAnsweredWrong({ aiAvailable, questionType = 'single' }) {
	host = document.createElement('div')
	document.body.appendChild(host)
	let vm = null
	app = createApp({ render: () => h(TrainingMode, { poolId: 7, ref: (r) => { vm = r } }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	await nextTick()
	vm.session = 3
	vm.questions = [{
		id: 11,
		text: 'Which colour do you get when you mix blue and yellow paint?',
		question_type: questionType,
		answers: [{ id: 1, text: 'Red', is_correct: false }, { id: 2, text: 'Green', is_correct: true }],
	}]
	vm.currentIndex = 0
	vm.answered = true
	vm.isCorrect = false
	vm.lastSelectedAnswerId = 1
	vm.correctAnswerTexts = ['Green']
	vm.aiAvailable = aiAvailable
	await nextTick()
	return host.textContent
}

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

describe('TrainingMode — "Explain via VirtuProf" follows the AI switch (fresh-eyes C4)', () => {
	it('is not offered after a wrong answer when AI is off', async () => {
		const text = await renderAnsweredWrong({ aiAvailable: false })
		expect(text).toContain('Incorrect')
		expect(text).not.toContain('Explain via VirtuProf')
	})

	it('is not offered on an open question when AI is off', async () => {
		const text = await renderAnsweredWrong({ aiAvailable: false, questionType: 'open' })
		expect(text).toContain('Incorrect')
		expect(text).not.toContain('Explain via VirtuProf')
	})

	it('stays after a wrong answer when AI is on', async () => {
		const text = await renderAnsweredWrong({ aiAvailable: true })
		expect(text).toContain('Explain via VirtuProf')
	})
})
