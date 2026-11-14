import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub, slotStub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
	slotStub: (name, cls) => ({ default: { name, template: `<div class="${cls}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'nc-button'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'nc-note'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))

import ExamMode from '../../src/components/ExamMode.vue'
import axios from '@nextcloud/axios'

describe('ExamMode review of a PBQ (audit #37)', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	async function finishWith(review, { submitted }) {
		host = document.createElement('div')
		document.body.appendChild(host)
		const exam = ref(null)
		app = createApp({ render: () => h(ExamMode, { ref: exam, poolId: 3, totalQuestions: 1 }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		const vm = exam.value
		vm.session = 11
		vm.questions = [{ id: 5, text: 'Order the steps', question_type: 'pbq', pbq_subtype: 'ranking', answers: [] }]
		if (submitted) {
			vm.pbqAnswers[5] = { a: 1 }
			vm.userAnswers[5] = 'pbq_submitted'
		}
		axios.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/complete')
			? { total_questions: 1, correct_answers: 0, score_percentage: 0, review }
			: {} }))
		await vm.finishExam()
		await nextTick()
		return host.querySelector('.review-item')
	}

	it('shows the points the server gave instead of "Skipped"', async () => {
		const item = await finishWith([{ questionId: 5, questionText: 'Order the steps', questionType: 'pbq', is_correct: false,
			correct_answer_texts: [], answers: [], is_pbq: true, pbq_points: 2, pbq_max_points: 3 }], { submitted: true })

		expect(item).not.toBeNull()
		expect(item.textContent).toContain('2/3 points')
		expect(item.textContent).not.toContain('Skipped')
	})

	it('a PBQ without an answer is still shown as skipped', async () => {
		const item = await finishWith([], { submitted: false })

		expect(item.textContent).toContain('Skipped')
	})
})
