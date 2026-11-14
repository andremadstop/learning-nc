/**
 * Pool generator draft review: the real computed properties and methods of PoolDraftReview.vue
 * and the draft handling of PoolGenerator.vue (audit #127: this file used to run Array operations
 * written in the test body, so no change to either component could turn it red).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('../../src/components/AiConsentHint.vue', () => ({ default: { name: 'AiConsentHint', template: '<div />' } }))

import axios from '@nextcloud/axios'
import PoolDraftReview from '../../src/components/PoolDraftReview.vue'
import PoolGenerator from '../../src/components/PoolGenerator.vue'

function makeDraft(overrides = {}) {
	return {
		text: 'What protocol operates at Layer 4?',
		answers: [
			{ text: 'TCP', is_correct: true },
			{ text: 'ARP', is_correct: false },
			{ text: 'ICMP', is_correct: false },
			{ text: 'Ethernet', is_correct: false },
		],
		explanation: 'TCP is a transport layer protocol.',
		difficulty: 'medium',
		source_chunk: 'Chapter 3 discusses transport layer protocols...',
		_status: 'accepted',
		...overrides,
	}
}

// A component's computed + methods bound to a fake instance carrying the given state.
function bind(component, state) {
	const vm = { $emit: vi.fn(), ...state }
	for (const [name, def] of Object.entries(component.computed || {})) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(component.methods || {})) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

const review = (questions) => bind(PoolDraftReview, { questions, editingIndex: null })

describe('PoolDraftReview', () => {
	describe('acceptedCount', () => {
		it('counts only accepted questions', () => {
			const vm = review([makeDraft(), makeDraft({ _status: 'rejected' }), makeDraft()])
			expect(vm.acceptedCount).toBe(2)
		})

		it('is 0 when all are rejected', () => {
			expect(review([makeDraft({ _status: 'rejected' })]).acceptedCount).toBe(0)
		})
	})

	describe('allAccepted', () => {
		it('is false for an empty list', () => {
			expect(review([]).allAccepted).toBe(false)
		})

		it('is true only when every question is accepted', () => {
			expect(review([makeDraft(), makeDraft()]).allAccepted).toBe(true)
			expect(review([makeDraft(), makeDraft({ _status: 'rejected' })]).allAccepted).toBe(false)
		})
	})

	describe('toggleStatus', () => {
		it('flips one question between accepted and rejected', () => {
			const vm = review([makeDraft(), makeDraft()])
			vm.toggleStatus(1)
			expect(vm.questions.map(q => q._status)).toEqual(['accepted', 'rejected'])
			vm.toggleStatus(1)
			expect(vm.questions[1]._status).toBe('accepted')
		})
	})

	describe('toggleAll', () => {
		it('rejects all when all are accepted', () => {
			const vm = review([makeDraft(), makeDraft()])
			vm.toggleAll()
			expect(vm.questions.every(q => q._status === 'rejected')).toBe(true)
		})

		it('accepts all when some are rejected', () => {
			const vm = review([makeDraft(), makeDraft({ _status: 'rejected' })])
			vm.toggleAll()
			expect(vm.questions.every(q => q._status === 'accepted')).toBe(true)
		})
	})

	describe('setCorrectAnswer', () => {
		it('marks exactly the chosen answer correct', () => {
			const vm = review([makeDraft(), makeDraft()])
			vm.setCorrectAnswer(1, 2)
			expect(vm.questions[1].answers.map(a => a.is_correct)).toEqual([false, false, true, false])
			expect(vm.questions[0].answers.map(a => a.is_correct)).toEqual([true, false, false, false])
		})
	})

	describe('deleteQuestion', () => {
		it('emits the list without the question and leaves the prop untouched', () => {
			const questions = [makeDraft({ text: 'Q1' }), makeDraft({ text: 'Q2' }), makeDraft({ text: 'Q3' })]
			const vm = review(questions)
			vm.deleteQuestion(1)
			const [event, updated] = vm.$emit.mock.calls[0]
			expect(event).toBe('update:questions')
			expect(updated.map(q => q.text)).toEqual(['Q1', 'Q3'])
			expect(questions).toHaveLength(3)
		})
	})
})

describe('PoolGenerator draft handling', () => {
	const generator = (state = {}) => bind(PoolGenerator, { ...PoolGenerator.data.call({}), ...state })

	beforeEach(() => {
		axios.post.mockReset()
	})

	it('handleGenerationResult marks every generated question accepted and opens the review', () => {
		const vm = generator()
		vm.handleGenerationResult({ questions: [makeDraft({ _status: undefined, text: 'What is DHCP?' })] })
		expect(vm.step).toBe('review')
		expect(vm.draftQuestions).toHaveLength(1)
		expect(vm.draftQuestions[0]._status).toBe('accepted')
		expect(vm.draftQuestions[0].text).toBe('What is DHCP?')
	})

	it('handleGenerationResult stays on the input step with an error when no question came back', () => {
		const vm = generator({ step: 'generating' })
		vm.handleGenerationResult({ questions: [] })
		expect(vm.step).toBe('input')
		expect(vm.error).not.toBe('')
		expect(vm.draftQuestions).toEqual([])
	})

	it('createPool sends only accepted questions, without the internal _status field', async () => {
		axios.post.mockResolvedValue({ data: { imported: 2 } })
		const vm = generator({
			poolTitle: 'Network+',
			draftQuestions: [
				makeDraft({ text: 'Keep 1' }),
				makeDraft({ text: 'Drop', _status: 'rejected' }),
				makeDraft({ text: 'Keep 2' }),
			],
		})
		await vm.createPool()
		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/learning/api/pool-generator/create-pool')
		expect(body.title).toBe('Network+')
		expect(body.questions.map(q => q.text)).toEqual(['Keep 1', 'Keep 2'])
		for (const q of body.questions) {
			expect(q).not.toHaveProperty('_status')
			expect(q).toHaveProperty('answers')
			expect(q).toHaveProperty('explanation')
			expect(q).toHaveProperty('source_chunk')
		}
		expect(vm.$emit).toHaveBeenCalledWith('created')
	})
})
