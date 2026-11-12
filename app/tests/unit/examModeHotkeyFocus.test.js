/**
 * Audit 2026-11 (S27): the exam hotkey handler sits on window and treated every Enter as
 * "skip / next". Enter on a focused answer button, nav dot or "End Exam" button was
 * preventDefault()ed (so the native click never happened) and then skipped the question.
 *
 * Same harness as examModePractice.test.js; the key events are real DOM events that bubble
 * from the focused control to the window listener.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

import axios from '@nextcloud/axios'
import ExamMode from '../../src/components/ExamMode.vue'

function createInstance(props = {}) {
	const instance = {
		poolId: null,
		courseId: 7,
		practice: false,
		practiceConfig: null,
		totalQuestions: 0,
		contentLanguage: '',
		...props,
		$emit: vi.fn(),
		$refs: {},
		$nextTick: (fn) => fn && fn(),
	}
	Object.assign(instance, ExamMode.data.call(instance))
	for (const [name, def] of Object.entries(ExamMode.computed)) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(ExamMode.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function examInstance() {
	const instance = createInstance()
	instance.screen = 'exam'
	instance.isLoading = false
	instance.questions = [
		{ id: 10, question_type: 'single', text: 'Q1', answers: [{ id: 100, text: 'A' }, { id: 101, text: 'B' }] },
		{ id: 11, question_type: 'single', text: 'Q2', answers: [{ id: 110, text: 'A' }] },
	]
	instance.currentQuestionIndex = 0
	instance.skipQuestion = vi.fn()
	instance.advanceToNext = vi.fn()
	return instance
}

let cleanup = []

function pressEnterOn(el, instance) {
	window.addEventListener('keydown', instance.handleExamHotkeys)
	cleanup.push(() => window.removeEventListener('keydown', instance.handleExamHotkeys))
	const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
	el.dispatchEvent(event)
	return event
}

function mountEl(html) {
	const host = document.createElement('div')
	host.innerHTML = html
	document.body.appendChild(host)
	cleanup.push(() => host.remove())
	return host
}

describe('ExamMode Enter hotkey and focused controls', () => {
	beforeEach(() => {
		cleanup = []
	})
	afterEach(() => {
		cleanup.forEach((fn) => fn())
		vi.clearAllMocks()
	})

	it('Enter on a focused answer button does not skip the question and keeps the native click', () => {
		const instance = examInstance()
		const host = mountEl('<div class="answer-options"><button class="answer-btn">A</button></div>')
		const event = pressEnterOn(host.querySelector('.answer-btn'), instance)
		expect(instance.skipQuestion).not.toHaveBeenCalled()
		expect(event.defaultPrevented).toBe(false)
		expect(instance.userAnswers[10]).toBeUndefined()
	})

	it('Enter on a nav dot (role=button div) does not skip the question it jumped to', () => {
		const instance = examInstance()
		const host = mountEl('<div class="nav-bar"><div class="nav-dot" role="button" tabindex="0">2</div></div>')
		pressEnterOn(host.querySelector('.nav-dot'), instance)
		expect(instance.skipQuestion).not.toHaveBeenCalled()
		expect(instance.advanceToNext).not.toHaveBeenCalled()
	})

	it('Enter on the End Exam button (text inside a button) does not skip', () => {
		const instance = examInstance()
		const host = mountEl('<button class="end-exam-btn"><span class="label">End Exam</span></button>')
		const event = pressEnterOn(host.querySelector('.label'), instance)
		expect(instance.skipQuestion).not.toHaveBeenCalled()
		expect(event.defaultPrevented).toBe(false)
	})

	it('Enter with nothing focused still skips an unanswered question', () => {
		const instance = examInstance()
		const event = pressEnterOn(document.body, instance)
		expect(instance.skipQuestion).toHaveBeenCalledTimes(1)
		expect(event.defaultPrevented).toBe(true)
	})

	it('number keys still answer while an answer button is focused', () => {
		const instance = examInstance()
		instance.answerQuestion = vi.fn()
		const host = mountEl('<button class="answer-btn">A</button>')
		window.addEventListener('keydown', instance.handleExamHotkeys)
		cleanup.push(() => window.removeEventListener('keydown', instance.handleExamHotkeys))
		host.querySelector('button').dispatchEvent(new KeyboardEvent('keydown', { key: '2', bubbles: true, cancelable: true }))
		expect(instance.answerQuestion).toHaveBeenCalledWith(101)
	})
})
