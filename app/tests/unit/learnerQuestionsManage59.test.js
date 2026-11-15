import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)),
}))
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name, template = '<div />') => ({ default: { name, template } }),
}))
vi.mock('../../src/components/AccessibleDialog.vue', () => stub('AccessibleDialog', '<div class="dialog"><slot /><div class="dialog-actions"><slot name="actions" /></div></div>'))
vi.mock('../../src/components/CourseFeed.vue', () => stub('CourseFeed'))
vi.mock('../../src/components/BuddyMatching.vue', () => stub('BuddyMatching'))
vi.mock('../../src/components/StudentKnowledgeContribute.vue', () => stub('StudentKnowledgeContribute'))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { createApp, h, nextTick } from 'vue'
import { setTestLanguage } from './support/l10nCatalog.js'
import CourseTabKommunikation from '../../src/components/CourseTabKommunikation.vue'
import CourseLearnerQuestions from '../../src/components/CourseLearnerQuestions.vue'

// GitHub #59 (rest): instructors could only answer a learner's question, never hide or delete a
// nonsense one; a learner could not take back a question sent by mistake; nothing at the tab
// said that questions were waiting; the admin inbox sat at the very bottom of the settings.

function mount(component, props, listeners = {}) {
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp({ render: () => h(component, { ...props, ...listeners }) })
	app.mount(el)
	return { el, unmount: () => { app.unmount(); el.remove() } }
}

async function flush() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

const buttonByText = (root, text) => [...root.querySelectorAll('button')].find((b) => b.textContent.trim() === text)

const TICKETS = [
	{ id: 1, subject: 'Folie 3', message: 'Unklar', status: 'open', answer_text: null },
	{ id: 2, subject: 'Frist', message: 'Bis wann?', status: 'answered', answer_text: 'Bis Freitag.' },
]

function instructorTab(listeners = {}, props = {}) {
	return mount(CourseTabKommunikation, {
		courseId: 7,
		course: { id: 7 },
		userRole: 'instructor',
		actsAsInstructor: true,
		activeTab: 'requests',
		...props,
	}, listeners)
}

describe('instructors manage the questions from learners (GitHub #59)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setTestLanguage('de')
		axios.get.mockImplementation((url) => Promise.resolve(url.endsWith('/open-count')
			? { data: { open_count: 0 } }
			: { data: { tickets: TICKETS, open_count: 1 } }))
		axios.post.mockResolvedValue({ data: {} })
		axios.delete.mockResolvedValue({ data: { deleted: true } })
	})

	it('archives a question: it leaves the inbox and the badge is refreshed from the server', async () => {
		const counts = []
		const { el, unmount } = instructorTab({ 'onOpen-questions-count': (n) => counts.push(n) })
		await flush()
		expect(counts).toEqual([1])

		buttonByText(el.querySelectorAll('.ticket-item-cd')[0], 'Archivieren').click()
		await flush()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/support-tickets/1/archive')
		expect(el.querySelectorAll('.ticket-item-cd')).toHaveLength(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/7/support-tickets/open-count')
		expect(counts).toEqual([1, 0])
		unmount()
	})

	it('the archive lists only archived questions, and they can be restored', async () => {
		const { el, unmount } = instructorTab()
		await flush()
		axios.get.mockImplementation((url) => Promise.resolve(url.endsWith('/open-count')
			? { data: { open_count: 1 } }
			: { data: { tickets: [{ id: 3, subject: 'Unsinn', message: 'asdf', status: 'archived', answer_text: null }], open_count: 1 } }))

		buttonByText(el, 'Archivierte Fragen anzeigen').click()
		await flush()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/learning/api/courses/7/support-tickets', { params: { archived: 1 } })
		const item = el.querySelector('.ticket-item-cd')
		expect(item.querySelector('.ticket-status-cd').textContent).toBe('Archiviert')
		expect(item.querySelector('textarea')).toBeNull()

		buttonByText(item, 'Wiederherstellen').click()
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/support-tickets/3/restore')
		expect(el.querySelectorAll('.ticket-item-cd')).toHaveLength(0)
		expect(el.textContent).toContain('Keine archivierten Fragen.')
		unmount()
	})

	it('deletes only after the confirmation dialog, and "Nicht löschen" deletes nothing', async () => {
		const { el, unmount } = instructorTab()
		await flush()

		buttonByText(el.querySelectorAll('.ticket-item-cd')[1], 'Löschen').click()
		await flush()
		expect(axios.delete).not.toHaveBeenCalled()
		expect(el.querySelector('.dialog').textContent).toContain('„Frist“')

		buttonByText(el.querySelector('.dialog'), 'Nicht löschen').click()
		await flush()
		expect(el.querySelector('.dialog')).toBeNull()
		expect(axios.delete).not.toHaveBeenCalled()

		buttonByText(el.querySelectorAll('.ticket-item-cd')[1], 'Löschen').click()
		await flush()
		buttonByText(el.querySelector('.dialog-actions'), 'Löschen').click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith('/apps/learning/api/courses/7/support-tickets/2')
		expect(el.querySelectorAll('.ticket-item-cd')).toHaveLength(1)
		expect(el.querySelector('.dialog')).toBeNull()
		unmount()
	})

	it('a failed delete keeps the question and says so', async () => {
		axios.delete.mockRejectedValue({ response: { status: 403 } })
		const { el, unmount } = instructorTab()
		await flush()
		buttonByText(el.querySelectorAll('.ticket-item-cd')[0], 'Löschen').click()
		await flush()
		buttonByText(el.querySelector('.dialog-actions'), 'Löschen').click()
		await flush()
		expect(showError).toHaveBeenCalledWith('Die Frage konnte nicht gelöscht werden.')
		expect(el.querySelectorAll('.ticket-item-cd')).toHaveLength(2)
		unmount()
	})

	it('the "Fragen von Lernenden" pill shows the count, with a text for screen readers', async () => {
		const { el, unmount } = instructorTab({}, { openQuestionsCount: 3 })
		await flush()
		const pill = [...el.querySelectorAll('.kommunikation-pill')].find((b) => b.textContent.includes('Fragen von Lernenden'))
		expect(pill.querySelector('.hidden-visually').textContent).toBe('Unbeantwortete Fragen: 3')
		expect(pill.querySelector('[aria-hidden="true"]').textContent).toBe('3')
		unmount()

		const none = instructorTab({}, { openQuestionsCount: 0 })
		await flush()
		expect(none.el.querySelector('.kommunikation-pill-count')).toBeNull()
		none.unmount()
	})
})

describe('a learner withdraws an unanswered question (GitHub #59)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setTestLanguage('de')
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: TICKETS } })
	})

	it('offers the withdrawal only on the open question without an answer', async () => {
		const { el, unmount } = mount(CourseLearnerQuestions, { courseId: 7 })
		await flush()
		const [open, answered] = el.querySelectorAll('.question-item')
		expect(buttonByText(open, 'Frage zurückziehen')).toBeTruthy()
		expect(buttonByText(answered, 'Frage zurückziehen')).toBeUndefined()
		unmount()
	})

	it('withdraws after the confirmation and removes the question', async () => {
		axios.delete.mockResolvedValue({ data: { deleted: true } })
		const { el, unmount } = mount(CourseLearnerQuestions, { courseId: 7 })
		await flush()
		buttonByText(el, 'Frage zurückziehen').click()
		await flush()
		expect(axios.delete).not.toHaveBeenCalled()
		buttonByText(el.querySelector('.dialog-actions'), 'Zurückziehen').click()
		await flush()
		expect(axios.delete).toHaveBeenCalledWith('/apps/learning/api/courses/7/questions/mine/1')
		expect(el.querySelectorAll('.question-item')).toHaveLength(1)
		unmount()
	})

	it('a question answered in the meantime (409) is reloaded with its answer', async () => {
		axios.delete.mockRejectedValue({ response: { status: 409 } })
		const { el, unmount } = mount(CourseLearnerQuestions, { courseId: 7 })
		await flush()
		buttonByText(el, 'Frage zurückziehen').click()
		await flush()
		axios.get.mockResolvedValue({ data: { can_ask: true, questions: [{ ...TICKETS[0], status: 'answered', answer_text: 'Jetzt klar?' }, TICKETS[1]] } })
		buttonByText(el.querySelector('.dialog-actions'), 'Zurückziehen').click()
		await flush()
		expect(showError).toHaveBeenCalledWith('Die Frage wurde inzwischen beantwortet und kann nicht mehr zurückgezogen werden.')
		expect(el.querySelector('.dialog')).toBeNull()
		expect(el.textContent).toContain('Jetzt klar?')
		unmount()
	})
})
