/**
 * Codeberg #10: a scenario / info text shown before the question.
 *
 * Covers the request contract end to end on the client: the form loads and emits the field,
 * the list sends it in the PUT/POST body, the translation dialog sends it, and the learner view
 * renders it as plain text (never HTML) only when there is something to show.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: { name: 'AccessibleDialog', template: '<div><slot /></div>' },
}))
vi.mock('../../src/components/PbqAuthorTool.vue', () => ({
	default: { name: 'PbqAuthorTool', template: '<div />' },
}))

import axios from '@nextcloud/axios'
import QuestionForm from '../../src/components/QuestionForm.vue'
import QuestionList from '../../src/components/QuestionList.vue'
import QuestionScenario from '../../src/components/QuestionScenario.vue'
import TranslationDialog from '../../src/components/TranslationDialog.vue'
import DailyChallengeCard from '../../src/components/DailyChallengeCard.vue'
import { SCENARIO_MAX_LENGTH, questionScenario } from '../../src/utils/questionScenario.js'

globalThis.t = (_app, str) => str

let host, app

function mount(component, props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = (_app, str) => str
	app.config.globalProperties.n = (_app, s) => s
	app.config.warnHandler = () => {}
	app.mount(host)
	return host
}

beforeEach(() => {
	document.body.innerHTML = ''
	vi.clearAllMocks()
})
afterEach(() => app?.unmount())

describe('questionScenario()', () => {
	it('returns the trimmed scenario or an empty string', () => {
		expect(questionScenario({ scenario: '  Fall 1\nZeile 2 ' })).toBe('Fall 1\nZeile 2')
		expect(questionScenario({ scenario: '   ' })).toBe('')
		expect(questionScenario({ scenario: null })).toBe('')
		expect(questionScenario(null)).toBe('')
	})

	it('matches the server limit', () => {
		expect(SCENARIO_MAX_LENGTH).toBe(10000)
	})
})

describe('QuestionScenario (learner view)', () => {
	it('renders the text with its line breaks', () => {
		mount(QuestionScenario, { question: { scenario: 'Ein Patient kommt um 3 Uhr.\n\nEr ist verwirrt.' } })
		const text = host.querySelector('.question-scenario__text')
		expect(text).toBeTruthy()
		expect(text.textContent).toBe('Ein Patient kommt um 3 Uhr.\n\nEr ist verwirrt.')
	})

	it('shows markup as text, never as HTML', () => {
		mount(QuestionScenario, { question: { scenario: '<img src=x onerror=alert(1)><b>fett</b>' } })
		expect(host.querySelector('img')).toBeNull()
		expect(host.querySelector('b')).toBeNull()
		expect(host.textContent).toContain('<b>fett</b>')
	})

	it('renders nothing without a scenario', () => {
		mount(QuestionScenario, { question: { scenario: '  ' } })
		expect(host.querySelector('.question-scenario')).toBeNull()
	})
})

describe('QuestionForm emits the scenario', () => {
	function formWith(question) {
		const onSave = vi.fn()
		mount(QuestionForm, { question, onSave })
		return onSave
	}

	function setScenario(value) {
		const el = host.querySelector('#question-scenario')
		el.value = value
		el.dispatchEvent(new Event('input', { bubbles: true }))
	}

	function fillRequired() {
		for (const el of host.querySelectorAll('input[required], textarea[required]')) {
			el.value = el.tagName === 'TEXTAREA' ? 'Was tust du zuerst?' : 'Antwort'
			el.dispatchEvent(new Event('input', { bubbles: true }))
		}
	}

	async function submit() {
		host.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await Promise.resolve()
	}

	it('sends a new scenario', async () => {
		const onSave = formWith(null)
		fillRequired()
		setScenario('Ausgangslage: Serverraum, 2 Uhr nachts.')
		await submit()
		expect(onSave).toHaveBeenCalledTimes(1)
		expect(onSave.mock.calls[0][0].scenario).toBe('Ausgangslage: Serverraum, 2 Uhr nachts.')
	})

	it('keeps the stored scenario when an existing question is edited', async () => {
		const onSave = formWith({
			id: 5, text: 'Frage?', scenario: 'Gespeichertes Szenario', question_type: 'single',
			answers: [{ id: 1, text: 'A', is_correct: true }, { id: 2, text: 'B', is_correct: false }],
		})
		await nextTick()
		expect(host.querySelector('#question-scenario').value).toBe('Gespeichertes Szenario')
		await submit()
		expect(onSave.mock.calls[0][0].scenario).toBe('Gespeichertes Szenario')
	})

	it('sends an empty string (not a missing key) when the scenario is cleared', async () => {
		const onSave = formWith({
			id: 5, text: 'Frage?', scenario: 'Weg damit', question_type: 'single',
			answers: [{ id: 1, text: 'A', is_correct: true }, { id: 2, text: 'B', is_correct: false }],
		})
		setScenario('')
		await submit()
		const payload = onSave.mock.calls[0][0]
		expect(Object.prototype.hasOwnProperty.call(payload, 'scenario')).toBe(true)
		expect(payload.scenario).toBe('')
	})

	it('caps the input at the server limit', () => {
		formWith(null)
		expect(host.querySelector('#question-scenario').getAttribute('maxlength')).toBe(String(SCENARIO_MAX_LENGTH))
	})
})

describe('QuestionList sends the scenario to the API', () => {
	const payload = { text: 'Q?', scenario: 'Szenario', questionType: 'single', answers: [], imageFile: null, removeImage: false }

	function listThis(editing) {
		return { editingQuestion: editing, poolId: 9, closeDialog: vi.fn(), loadQuestions: vi.fn() }
	}

	it('in the PUT body when editing', async () => {
		axios.put.mockResolvedValue({ data: {} })
		await QuestionList.methods.saveQuestion.call(listThis({ id: 5 }), payload)
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/questions/5', expect.objectContaining({ scenario: 'Szenario' }))
	})

	it('in the POST body when creating', async () => {
		axios.post.mockResolvedValue({ data: { id: 6 } })
		await QuestionList.methods.saveQuestion.call(listThis(null), payload)
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/questions', expect.objectContaining({ scenario: 'Szenario', poolId: 9 }))
	})
})

describe('TranslationDialog sends the scenario translation', () => {
	function dialogThis(scenario) {
		return {
			question: { id: 5, scenario: 'Original', answers: [] },
			languages: [{ key: 'en', label: 'English' }],
			questionTranslations: { en: { text: 'Question?', explanation: '', scenario } },
			answerTranslations: {},
			existingQuestionTranslations: { en: true },
			existingAnswerTranslations: {},
			saving: false,
			error: '',
			$emit: vi.fn(),
		}
	}

	it('with the translated text', async () => {
		axios.put.mockResolvedValue({ data: {} })
		await TranslationDialog.methods.save.call(dialogThis('Translated scenario'))
		expect(axios.put).toHaveBeenCalledWith('/apps/learning/api/questions/5/translations/en',
			expect.objectContaining({ text: 'Question?', scenario: 'Translated scenario' }))
	})

	it('as an empty string when cleared, so a stored translation is removed', async () => {
		axios.put.mockResolvedValue({ data: {} })
		await TranslationDialog.methods.save.call(dialogThis('  '))
		const body = axios.put.mock.calls[0][1]
		expect(body.scenario).toBe('')
	})
})

describe('DailyChallengeCard shows the scenario (Codex review)', () => {
	it('renders it above the challenge question', async () => {
		axios.get.mockResolvedValue({ data: {
			available: true, completed: false, xp_reward: 15, pool_name: 'Erste Hilfe',
			question: { id: 1, text: 'Was tust du?', scenario: 'Kollege am Boden.', question_type: 'single', answers: [] },
		} })
		mount(DailyChallengeCard)
		await new Promise((resolve) => setTimeout(resolve, 0))
		await nextTick()
		expect(host.querySelector('.question-scenario__text')?.textContent).toBe('Kollege am Boden.')
	})
})
