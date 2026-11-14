/**
 * QuestionList (2026-11 audit):
 *  #107 QuestionForm reset its `saving` right after emitting 'save', and saveQuestion had no guard:
 *       a double click on "Speichern" sent two POSTs and created the question twice.
 *  #108 The search ran over all of the user's pools (50 hits) and was filtered to this pool
 *       afterwards, and a hit on another page only reloaded page 1.
 *  #109 The pagination showed a literal "–": an escape in template text is not decoded.
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

async function mount() {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(QuestionList, { poolId: 7, poolName: 'Netze' })
	app.config.globalProperties.t = globalThis.t
	app.config.warnHandler = () => {}
	vm = app.mount(host)
	await flush()
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockImplementation((url, config) => {
		if (url.endsWith('/questions')) return Promise.resolve(page(config.params.offset, config.params.limit))
		if (url.endsWith('/ai/available')) return Promise.resolve({ data: { available: false } })
		if (url.endsWith('/questions/search')) {
			return Promise.resolve({ data: [{ id: 1110, pool_id: 7, text: 'VLAN tagging', position: 110 }] })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
})
afterEach(() => { app?.unmount(); host?.remove() })

describe('QuestionList pagination (audit #109)', () => {
	it('shows a real dash, not the escape sequence', async () => {
		await mount()
		const info = host.querySelector('.pagination-info').textContent
		expect(info).not.toContain('\\u')
		expect(info.replace(/\s+/g, ' ').trim()).toBe('1–50 / 120')
	})
})

describe('QuestionList search (audit #108)', () => {
	it('searches in this pool on the server', async () => {
		await mount()
		vm.searchTerm = 'VLAN'
		await vm.doSearch()
		const call = axios.get.mock.calls.find(([url]) => url.endsWith('/questions/search'))
		expect(call[1].params).toEqual({ query: 'VLAN', poolId: 7 })
	})

	it('a hit on another page opens that page', async () => {
		await mount()
		await vm.jumpToSearchResult({ id: 1110, pool_id: 7, position: 110 })
		await flush()
		const offsets = axios.get.mock.calls.filter(([url]) => url.endsWith('/questions')).map(([, c]) => c.params.offset)
		expect(offsets.at(-1)).toBe(100)
		expect(vm.currentPage).toBe(2)
		expect(host.querySelector('.question-item[data-question-id="1110"]')).not.toBeNull()
	})
})

describe('QuestionList save (audit #107)', () => {
	it('a second save while the first POST runs sends nothing', async () => {
		let release
		axios.post.mockImplementation(() => new Promise((resolve) => { release = () => resolve({ data: { id: 5 } }) }))
		await mount()
		vm.showCreateDialog()
		await flush()

		const first = vm.saveQuestion({ text: 'Neu', answers: [] })
		const second = vm.saveQuestion({ text: 'Neu', answers: [] })
		await flush()
		expect(host.querySelector('.question-form-stub').dataset.busy).toBe('true')
		release()
		await Promise.all([first, second])

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(vm.savingQuestion).toBe(false)
	})
})
