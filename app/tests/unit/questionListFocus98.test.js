/**
 * GitHub #98: a question picked in the Pools search opened its pool, and the question had to be
 * found by hand (Ctrl+F). QuestionList now takes it as focusQuestionId: it opens the question's
 * page (the in-pool search by ID gives its position), scrolls to it, marks it briefly and gives it
 * the focus; where the pool may be edited it also opens the editor. 'focus-done' clears it.
 *
 * Harness from questionListSearchSave.test.js.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/components/QuestionForm.vue', () => ({
	default: { name: 'QuestionForm', props: ['question', 'busy'], emits: ['save', 'close'], template: '<div class="question-form-stub" :data-busy="String(busy)" />' },
}))
vi.mock('../../src/components/TranslationDialog.vue', () => ({ default: { name: 'TranslationDialog', template: '<div />' } }))
vi.mock('../../src/components/ImportDialog.vue', () => ({ default: { name: 'ImportDialog', template: '<div />' } }))
vi.mock('../../src/components/AIGenerator.vue', () => ({ default: { name: 'AIGenerator', template: '<div />' } }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({ default: { name: 'AccessibleDialog', template: '<div />' } }))
vi.mock('../../src/components/QuestionScenario.vue', () => ({ default: { name: 'QuestionScenario', template: '<div />' } }))

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useA11yStore } from '../../src/stores/a11yStore.js'
import QuestionList from '../../src/components/QuestionList.vue'

globalThis.t = (_app, str, vars) => (vars ? str.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : str)

const flush = async () => {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

function page(offset, limit, total = 120) {
	const questions = []
	for (let i = offset; i < Math.min(offset + limit, total); i++) {
		questions.push({ id: 1000 + i, pool_id: 7, text: 'Frage ' + i, question_type: 'single', answers: [] })
	}
	return { data: { questions, total } }
}

let host, app, vm

let focusDone = 0

async function mount(props = {}, pinia = null) {
	host = document.createElement('div')
	document.body.appendChild(host)
	focusDone = 0
	app = createApp(QuestionList, { poolId: 7, poolName: 'Netze', onFocusDone: () => { focusDone++ }, ...props })
	app.config.globalProperties.t = globalThis.t
	app.config.warnHandler = () => {}
	if (pinia) app.use(pinia)
	vm = app.mount(host)
	await flush()
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockImplementation((url, config) => {
		if (url.endsWith('/questions')) return Promise.resolve(page(config.params.offset, config.params.limit))
		if (url.endsWith('/ai/available')) return Promise.resolve({ data: { available: false } })
		if (url.endsWith('/questions/search')) {
			// a numeric query matches the ID and any text containing the digits
			return Promise.resolve({ data: [{ id: 1110, pool_id: 7, text: 'VLAN tagging', position: 110 }, { id: 9, pool_id: 7, text: 'Port 1110', position: 3 }] })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
})
afterEach(() => { app?.unmount(); host?.remove() })


describe('QuestionList opens the question from the pool search (GitHub #98)', () => {
	beforeEach(() => {
		Element.prototype.scrollIntoView = vi.fn()
	})

	it('a question on the first page: scrolled to, marked, focused and opened in the editor', async () => {
		await mount({ focusQuestionId: 1004 })
		await flush()
		const item = host.querySelector('.question-item[data-question-id="1004"]')
		expect(item.scrollIntoView).toHaveBeenCalled()
		expect(item.classList.contains('question-item--focus')).toBe(true)
		expect(document.activeElement).toBe(item)
		expect(vm.showDialog).toBe(true)
		expect(vm.editingQuestion.id).toBe(1004)
		expect(focusDone).toBe(1)
		expect(axios.get.mock.calls.some(([url]) => url.endsWith('/questions/search'))).toBe(false)
	})

	it('a question on another page: its page opens through the search by ID', async () => {
		await mount({ focusQuestionId: 1110 })
		await flush()
		const search = axios.get.mock.calls.find(([url]) => url.endsWith('/questions/search'))
		expect(search[1].params).toEqual({ query: '1110', poolId: 7 })
		expect(vm.currentPage).toBe(2)
		expect(host.querySelector('.question-item[data-question-id="1110"]').classList.contains('question-item--focus')).toBe(true)
		expect(vm.editingQuestion.id).toBe(1110)
	})

	// IDs 1–9 are one digit; the server's search used to refuse queries under two characters
	// (QuestionSearchIdLookup98Test). The list asks with the bare ID and the pool.
	it('a one-digit ID on another page: asked for as is, its page opens', async () => {
		axios.get.mockImplementation((url, config) => {
			if (url.endsWith('/questions')) {
				const p = page(config.params.offset, config.params.limit)
				if (config.params.offset === 100) p.data.questions[3] = { id: 7, pool_id: 7, text: 'Frage 7', question_type: 'single', answers: [] }
				return Promise.resolve(p)
			}
			if (url.endsWith('/ai/available')) return Promise.resolve({ data: { available: false } })
			if (url.endsWith('/questions/search')) return Promise.resolve({ data: [{ id: 7, pool_id: 7, text: 'Frage 7', position: 103 }] })
			return Promise.reject(new Error('unexpected ' + url))
		})
		await mount({ focusQuestionId: 7 })
		await flush()
		const search = axios.get.mock.calls.find(([url]) => url.endsWith('/questions/search'))
		expect(search[1].params).toEqual({ query: '7', poolId: 7 })
		expect(vm.currentPage).toBe(2)
		expect(host.querySelector('.question-item[data-question-id="7"]').classList.contains('question-item--focus')).toBe(true)
		expect(vm.editingQuestion.id).toBe(7)
	})

	it('a read-only pool ("Fragen ansehen"): scrolled to and marked, no editor', async () => {
		await mount({ focusQuestionId: 1004, readonly: true })
		await flush()
		expect(host.querySelector('.question-item[data-question-id="1004"]').classList.contains('question-item--focus')).toBe(true)
		expect(vm.showDialog).toBe(false)
		expect(focusDone).toBe(1)
	})

	it('a question that is not in the pool any more: no editor, the list stays, and the focus is done', async () => {
		await mount({ focusQuestionId: 4242 })
		await flush()
		expect(vm.showDialog).toBe(false)
		expect(host.querySelector('.question-item--focus')).toBeNull()
		expect(focusDone).toBe(1)
	})

	// Review of #98: only prefers-reduced-motion was honoured; the app's own "Ruhige Darstellung"
	// (QuestionConceal and the player respect it) still scrolled smoothly and pulsed twice.
	it('"Ruhige Darstellung" on: jumped to without smooth scrolling, marked without the pulse', async () => {
		window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		const pinia = createPinia()
		setActivePinia(pinia)
		useA11yStore(pinia).setEnabled(false)
		await mount({ focusQuestionId: 1004 }, pinia)
		await flush()
		const item = host.querySelector('.question-item[data-question-id="1004"]')
		expect(item.scrollIntoView).toHaveBeenCalledWith({ behavior: 'auto', block: 'center' })
		expect(item.classList.contains('question-item--focus')).toBe(true)
		expect(item.classList.contains('question-item--still')).toBe(true)
		setActivePinia(undefined)
	})

	it('animations on (and no reduced motion): smooth, with the pulse', async () => {
		window.matchMedia = vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
		const pinia = createPinia()
		setActivePinia(pinia)
		await mount({ focusQuestionId: 1004 }, pinia)
		await flush()
		const item = host.querySelector('.question-item[data-question-id="1004"]')
		expect(item.scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' })
		expect(item.classList.contains('question-item--still')).toBe(false)
		setActivePinia(undefined)
	})

	it('without a question nothing is searched or opened', async () => {
		await mount()
		await flush()
		expect(vm.showDialog).toBe(false)
		expect(focusDone).toBe(0)
	})
})
