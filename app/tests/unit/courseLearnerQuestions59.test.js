import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => url.replace('{courseId}', String(params.courseId))) }))
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { createApp, nextTick } from 'vue'
import { kommunikationTabIds } from '../../src/utils/courseView.js'
import { setTestLanguage } from './support/l10nCatalog.js'
import CourseLearnerQuestions from '../../src/components/CourseLearnerQuestions.vue'

// GitHub #59: without VirtuProf a learner had no way to ask the instructors, and answers were
// only shown inside VirtuProf. The communication tab now has "Frage stellen" with the form and
// the learner's own questions with their answers.

function mount(props) {
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp(CourseLearnerQuestions, props)
	app.mount(el)
	return { el, unmount: () => { app.unmount(); el.remove() } }
}

async function flush() {
	for (let i = 0; i < 3; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('learner questions in the communication tab (GitHub #59)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setTestLanguage('de')
	})

	it('every learner gets the leaf, with or without VirtuProf, learning partners or AI', () => {
		expect(kommunikationTabIds({ mode_config: { buddies: false } })).toContain('my-questions')
		expect(kommunikationTabIds(null, { aiEnabled: false })).toContain('my-questions')
		expect(kommunikationTabIds(null, { actsAsInstructor: true })).not.toContain('my-questions')
	})

	it('lists the own questions with their answers from the course endpoint', async () => {
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: [
			{ id: 2, subject: 'Frist', message: 'Bis wann?', status: 'answered', answer_text: 'Bis Freitag.' },
			{ id: 1, subject: 'Folie 3', message: 'Unklar', status: 'open', answer_text: null },
		] } })
		const { el, unmount } = mount({ courseId: 7 })
		await flush()

		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/questions/mine')
		const items = el.querySelectorAll('.question-item')
		expect(items).toHaveLength(2)
		expect(items[0].textContent).toContain('Bis Freitag.')
		expect(items[1].querySelector('.question-status').textContent).toBe('Offen')
		expect(el.querySelector('.question-form')).not.toBeNull()
		unmount()
	})

	it('sends the question to the course and shows it at the top', async () => {
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: [] } })
		axios.post.mockResolvedValue({ data: { question: { id: 9, subject: 'Hallo', message: 'Wie geht X?', status: 'open', answer_text: null } } })
		const { el, unmount } = mount({ courseId: 7 })
		await flush()

		const subject = el.querySelector('#learner-question-subject')
		const message = el.querySelector('#learner-question-message')
		subject.value = 'Hallo'
		subject.dispatchEvent(new Event('input'))
		message.value = 'Wie geht X?'
		message.dispatchEvent(new Event('input'))
		await nextTick()
		el.querySelector('.question-form button').click()
		await flush()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/questions', { subject: 'Hallo', message: 'Wie geht X?' })
		expect(showSuccess).toHaveBeenCalled()
		expect(el.querySelectorAll('.question-item')).toHaveLength(1)
		expect(message.value).toBe('')
		unmount()
	})

	it('keeps the typed question when sending fails', async () => {
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: [] } })
		axios.post.mockRejectedValue({ response: { status: 500 } })
		const { el, unmount } = mount({ courseId: 7 })
		await flush()

		const message = el.querySelector('#learner-question-message')
		message.value = 'Wichtig'
		message.dispatchEvent(new Event('input'))
		await nextTick()
		el.querySelector('.question-form button').click()
		await flush()

		expect(showError).toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
		expect(message.value).toBe('Wichtig')
		unmount()
	})

	it('an empty question cannot be sent', async () => {
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: [] } })
		const { el, unmount } = mount({ courseId: 7 })
		await flush()

		expect(el.querySelector('.question-form button').disabled).toBe(true)
		unmount()
	})

	it('an instructor in the student view gets a note instead of the form', async () => {
		axios.get.mockResolvedValue({ data: { can_ask: false, questions: [] } })
		const { el, unmount } = mount({ courseId: 7 })
		await flush()

		expect(el.querySelector('.question-form')).toBeNull()
		expect(el.querySelector('.note').textContent).toContain('Fragen von Lernenden')
		unmount()
	})
})
