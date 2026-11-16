/**
 * GitHub #66: rich feedback per question — hint before answering, feedback per option,
 * background fact and key takeaway after answering.
 *
 * The pool JSON reader is held to the same cases as RichFeedbackFormat.php
 * (tests/Fixtures/rich-feedback-cases.json, RichFeedbackFormatTest.php), the import preview to
 * the server's messages, the editor to the request contract (QuestionController reads hint,
 * backgroundFact, keyTakeaway, optionFeedback by position) and the learner views to what the
 * server sends after an answer.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: { name: 'AccessibleDialog', template: '<div><slot /></div>' },
}))
vi.mock('../../src/components/PbqAuthorTool.vue', () => ({
	default: { name: 'PbqAuthorTool', template: '<div />' },
}))

import QuestionForm from '../../src/components/QuestionForm.vue'
import QuestionHint from '../../src/components/QuestionHint.vue'
import RichFeedbackPanel from '../../src/components/RichFeedbackPanel.vue'
import { analyzeJsonImport } from '../../src/utils/importPreview.js'
import {
	richFeedbackFromItem, richFeedbackTooLong, optionFeedbackToShow, hasRichFeedback, questionHint,
	answersWithFeedback, optionFeedbackFromAnswers,
	HINT_MAX_LENGTH, BACKGROUND_MAX_LENGTH, TAKEAWAY_MAX_LENGTH, WHY_MAX_LENGTH, MISCONCEPTION_MAX_LENGTH,
} from '../../src/utils/richFeedback.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(readFileSync(resolve(here, '../Fixtures/rich-feedback-cases.json'), 'utf-8'))

globalThis.t = (_app, str, vars) => (vars ? str.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k] ?? `{${k}}`)) : str)

let host, app

function mount(component, props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
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

/** The same item as RichFeedbackFormatTest::tooLongItem(). */
function tooLongItem(c) {
	const value = 'ä'.repeat(c.length)
	const item = { text: 'Q?', answers: [{ text: 'a', is_correct: true }, { text: 'b', is_correct: false }] }
	if (c.field === 'why' || c.field === 'misconception') {
		item.feedback_per_option = [{ option_index: 1, [c.field]: value }]
	} else {
		item[c.field] = value
	}
	return item
}

describe('pool JSON reader (shared fixture with RichFeedbackFormat.php)', () => {
	for (const c of fixture.cases) {
		it(c.name, () => {
			expect(richFeedbackFromItem(c.item, null, c.is_correct)).toEqual(c.expected)
		})
	}

	for (const c of fixture.too_long) {
		it(`limit: ${c.name}`, () => {
			expect(richFeedbackTooLong(richFeedbackFromItem(tooLongItem(c)), 2)).toBe(c.skipped)
		})
	}

	it('uses the server limits', () => {
		expect([HINT_MAX_LENGTH, BACKGROUND_MAX_LENGTH, TAKEAWAY_MAX_LENGTH, WHY_MAX_LENGTH, MISCONCEPTION_MAX_LENGTH])
			.toEqual([2000, 5000, 500, 5000, 1000])
	})

	it('a too long option beyond the imported answers does not refuse the question', () => {
		const rich = richFeedbackFromItem({ answers: [{}, {}, {}], feedback_per_option: [{ option_index: 2, why: 'x'.repeat(5001) }] })
		expect(richFeedbackTooLong(rich, 2)).toBe(false)
		expect(richFeedbackTooLong(rich, 3)).toBe(true)
	})
})

describe('import preview says what the server does', () => {
	it('skips a question with a too long field with the server message', () => {
		const result = analyzeJsonImport(JSON.stringify([tooLongItem({ field: 'hint', length: 2001 }), { text: 'Other', answers: [{ text: 'x', is_correct: true }, { text: 'y' }] }]))
		expect(result.errors).toEqual(['Item 1: A feedback field is too long — question skipped'])
		expect(result.items.filter((i) => i.question).length).toBe(1)
	})

	it('reports dropped languages and a contradicting verdict like the server (RichFeedbackFormatTest)', () => {
		const result = analyzeJsonImport(JSON.stringify([fixture.cases[2].item]))
		expect(result.warnings).toEqual([
			'Item 1: Feedback texts in de were not imported — one language per question',
			'Item 1: A "verdict" in feedback_per_option contradicts the marked correct answers — the marked answers count',
		])
	})
})

describe('what the learner sees after answering', () => {
	const rich = {
		option_feedback: [
			{ answer_id: 11, position: 0, is_correct: false, why: 'Blue is wrong.', misconception: 'Names' },
			{ answer_id: 12, position: 1, is_correct: true, why: 'Yellow is right.', misconception: null },
			{ answer_id: 13, position: 2, is_correct: false, why: 'Purple is a plum.', misconception: null },
		],
		background_fact: 'Chlorophyll.',
		key_takeaway: 'Ripe means yellow.',
	}
	// shuffled on screen
	const answers = [{ id: 13, text: 'Purple' }, { id: 11, text: 'Blue' }, { id: 12, text: 'Yellow' }]

	it('a wrong choice shows its own feedback and why the right one is right, in screen order', () => {
		expect(optionFeedbackToShow(rich, answers, [11]).map((o) => [o.answerId, o.selected, o.isCorrect])).toEqual([[11, true, false], [12, false, true]])
	})

	it('a right choice shows only its own feedback', () => {
		expect(optionFeedbackToShow(rich, answers, [12]).map((o) => o.answerId)).toEqual([12])
	})

	it('a partly right multi answer counts as wrong and shows the missed right option', () => {
		const multi = { option_feedback: [
			{ answer_id: 1, is_correct: true, why: 'a' }, { answer_id: 2, is_correct: true, why: 'b' }, { answer_id: 3, is_correct: false, why: 'c' },
		] }
		expect(optionFeedbackToShow(multi, [{ id: 1 }, { id: 2 }, { id: 3 }], [1]).map((o) => o.answerId)).toEqual([1, 2])
	})

	it('nothing without rich feedback', () => {
		expect(optionFeedbackToShow(null, answers, [11])).toEqual([])
		expect(hasRichFeedback(null)).toBe(false)
		expect(hasRichFeedback({ option_feedback: [], background_fact: ' ', key_takeaway: null })).toBe(false)
		expect(hasRichFeedback({ option_feedback: [], background_fact: null, key_takeaway: 'K' })).toBe(true)
	})

	it('the panel renders feedback, misconception, background and takeaway as plain text', () => {
		mount(RichFeedbackPanel, { rich: { ...rich, background_fact: '<b>bold</b>' }, answers, selectedIds: [11] })
		expect(host.textContent).toContain('Blue is wrong.')
		expect(host.textContent).toContain('Denkfehler:')
		expect(host.textContent).toContain('Names')
		expect(host.textContent).toContain('Yellow is right.')
		expect(host.textContent).not.toContain('Purple is a plum.')
		expect(host.textContent).toContain('Merksatz:')
		expect(host.querySelector('b')).toBeNull()
		expect(host.textContent).toContain('<b>bold</b>')
	})

	it('the panel renders nothing without feedback', () => {
		mount(RichFeedbackPanel, { rich: null })
		expect(host.querySelector('.rich-feedback')).toBeNull()
	})
})

describe('hint', () => {
	it('is behind a button, closed at first', async () => {
		mount(QuestionHint, { question: { id: 1, hint: 'Count the pairs.' } })
		const button = host.querySelector('button')
		expect(button.getAttribute('aria-expanded')).toBe('false')
		expect(host.querySelector('.question-hint__text').style.display).toBe('none')
		button.click()
		await nextTick()
		expect(button.getAttribute('aria-expanded')).toBe('true')
		expect(host.querySelector('.question-hint__text').style.display).toBe('')
		expect(host.textContent).toContain('Count the pairs.')
	})

	it('renders nothing for a question without one (an exam attempt never carries one)', () => {
		mount(QuestionHint, { question: { id: 1, text: 'Q' } })
		expect(host.querySelector('.question-hint')).toBeNull()
		expect(questionHint({ hint: '  ' })).toBe('')
	})
})

describe('question editor', () => {
	it('keys the feedback by the position each saved answer gets', () => {
		const form = answersWithFeedback([{ text: 'a' }, { text: 'b' }, { text: 'c' }], [{ position: 2, why: 'c!', misconception: null }])
		expect(form.map((a) => a.why)).toEqual(['', '', 'c!'])
		// the editor drops the blank second answer: c is saved at position 1
		const saved = [form[0], form[2]]
		expect(optionFeedbackFromAnswers(saved)).toEqual([{ position: 1, why: 'c!', misconception: null }])
	})

	function fillRequired() {
		for (const el of host.querySelectorAll('input[required], textarea[required]')) {
			el.value = el.tagName === 'TEXTAREA' ? 'Frage?' : 'Antwort'
			el.dispatchEvent(new Event('input', { bubbles: true }))
		}
	}

	function setValue(selector, value) {
		const el = host.querySelector(selector)
		el.value = value
		el.dispatchEvent(new Event('input', { bubbles: true }))
	}

	async function submit() {
		host.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await Promise.resolve()
	}

	it('sends hint, background, takeaway and the option feedback in the request contract', async () => {
		const onSave = vi.fn()
		mount(QuestionForm, { question: null, onSave })
		fillRequired()
		setValue('#question-hint', 'Zähle die Paare.')
		setValue('#question-background', 'Spinnentiere.')
		setValue('#question-takeaway', 'Acht Beine.')
		setValue('#answer-why-1', 'Falsch, das ist ein Insekt.')
		setValue('#answer-misconception-1', 'Spinne für Insekt halten')
		await submit()

		const payload = onSave.mock.calls[0][0]
		expect(payload.hint).toBe('Zähle die Paare.')
		expect(payload.backgroundFact).toBe('Spinnentiere.')
		expect(payload.keyTakeaway).toBe('Acht Beine.')
		expect(payload.optionFeedback).toEqual([{ position: 1, why: 'Falsch, das ist ein Insekt.', misconception: 'Spinne für Insekt halten' }])
		for (const answer of payload.answers) {
			expect(Object.keys(answer).sort()).toEqual(['is_correct', 'text'])
		}
	})

	it('loads the stored fields, and a cleared field is sent empty so the server clears it', async () => {
		const onSave = vi.fn()
		mount(QuestionForm, {
			question: {
				id: 5, text: 'Frage?', question_type: 'single', hint: 'Alter Hinweis', background_fact: 'BG', key_takeaway: 'KT',
				answers: [{ id: 1, text: 'Ja', is_correct: true }, { id: 2, text: 'Nein', is_correct: false }],
				option_feedback: [{ position: 1, why: 'Nein ist falsch.', misconception: null }],
			},
			onSave,
		})
		await nextTick()
		expect(host.querySelector('#question-hint').value).toBe('Alter Hinweis')
		expect(host.querySelector('#answer-why-1').value).toBe('Nein ist falsch.')
		setValue('#question-hint', '')
		await submit()

		const payload = onSave.mock.calls[0][0]
		expect(payload.hint).toBe('')
		expect(payload.backgroundFact).toBe('BG')
		expect(payload.optionFeedback).toEqual([{ position: 1, why: 'Nein ist falsch.', misconception: null }])
	})

	it('a question loaded without its rich feedback saves none of the fields, so nothing stored is wiped', async () => {
		const onSave = vi.fn()
		mount(QuestionForm, {
			question: { id: 7, text: 'Frage?', question_type: 'single', answers: [{ id: 1, text: 'Ja', is_correct: true }, { id: 2, text: 'Nein', is_correct: false }] },
			onSave,
		})
		await nextTick()
		await submit()
		const payload = onSave.mock.calls[0][0]
		for (const key of ['hint', 'backgroundFact', 'keyTakeaway', 'optionFeedback']) {
			expect(payload, key).not.toHaveProperty(key)
		}
	})

	it('an open question sends no option feedback', async () => {
		const onSave = vi.fn()
		mount(QuestionForm, {
			question: { id: 6, text: 'Frage?', question_type: 'open', answers: [{ id: 1, text: 'Musterantwort', is_correct: true }], option_feedback: [{ position: 0, why: 'x' }] },
			onSave,
		})
		await submit()
		expect(onSave.mock.calls[0][0].optionFeedback).toEqual([])
	})
})

describe('verdict labels (pre-existing: English in the FR/RU/AR columns)', () => {
	it('"Richtig" and "Falsch" are translated in every language', () => {
		const english = { Richtig: 'Correct', Falsch: 'Wrong' }
		for (const lang of ['fr', 'ru', 'ar', 'uk']) {
			const tr = JSON.parse(readFileSync(resolve(here, `../../l10n/${lang}.json`), 'utf-8')).translations
			// French "Correct" is French as well; "Wrong" is not.
			expect(tr.Falsch, lang).not.toBe(english.Falsch)
			if (lang !== 'fr') {
				expect(tr.Richtig, lang).not.toBe(english.Richtig)
			}
		}
	})
})
