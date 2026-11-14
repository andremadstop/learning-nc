/**
 * Audit #58 follow-up: PbqRanking reports its displayed order once on mount, so an untouched
 * ranking can be submitted complete. In a graded exam that report must not count as an answer:
 * ExamMode stores every PbqRenderer 'change' in pbqAnswers and finishExam grades every PBQ found
 * there, so a ranking the learner only opened and then skipped was graded on the author's order.
 *
 * Mounts ExamMode with the real PbqRenderer and PbqRanking.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', async () => {
	const { h: hh } = await import('vue')
	return {
		default: {
			name: 'NcButton',
			emits: ['click'],
			props: { disabled: Boolean },
			render() { return hh('button', { class: 'nc-btn', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) },
		},
	}
})
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))

import ExamMode from '../../src/components/ExamMode.vue'
import axios from '@nextcloud/axios'

const ranking = {
	id: 5,
	text: 'Order the steps',
	question_type: 'pbq',
	pbq_subtype: 'ranking',
	pbq_config: { items: [
		{ id: 'a', text: 'First', correct_position: 1 },
		{ id: 'b', text: 'Second', correct_position: 2 },
	] },
	answers: [],
}
const single = { id: 6, text: 'Pick one', question_type: 'single', answers: [{ id: 61, text: 'Yes' }, { id: 62, text: 'No' }] }

let host, app

beforeEach(() => {
	vi.clearAllMocks()
	axios.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/complete')
		? { total_questions: 2, correct_answers: 0, score_percentage: 0, review: [] }
		: {} }))
})

afterEach(() => {
	app?.unmount()
	host?.remove()
})

async function openRanking() {
	host = document.createElement('div')
	document.body.appendChild(host)
	const exam = ref(null)
	app = createApp({ render: () => h(ExamMode, { ref: exam, poolId: 3, totalQuestions: 2 }) })
	app.config.globalProperties.t = globalThis.t
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	const vm = exam.value
	vm.session = 11
	vm.questions = [ranking, single]
	vm.currentQuestionIndex = 0
	vm.screen = 'exam'
	await nextTick()
	await nextTick()
	expect(host.querySelectorAll('.pbq-ranking__item').length).toBeGreaterThan(0)
	return vm
}

function button(label) {
	return [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === label)
}

function batchBody() {
	const call = axios.post.mock.calls.find(([url]) => url.endsWith('/submitBatch'))
	return call ? call[1] : null
}

describe('ExamMode with a ranking PBQ (audit #58)', () => {
	it('a ranking that was only opened and then skipped is not graded', async () => {
		const vm = await openRanking()

		button('Überspringen').click()
		await nextTick()
		vm.userAnswers[6] = 61
		await vm.finishExam()

		expect(vm.pbqAnswers[5]).toBeUndefined()
		const body = batchBody()
		expect(body.answers.map((a) => a.questionId)).toEqual([6])
	})

	it('submitting the untouched ranking sends the displayed order', async () => {
		const vm = await openRanking()

		button('PBQ abschicken').click()
		await nextTick()
		await vm.finishExam()

		expect(batchBody().answers).toContainEqual({ questionId: 5, pbqAnswers: { a: 1, b: 2 } })
	})
})
