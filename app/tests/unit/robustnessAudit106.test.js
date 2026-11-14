/**
 * Audit #106: three small robustness gaps.
 * - TrainingMode kept polling the AI status every 1.5 s after the training was left, and a
 *   failed explanation kept its task id, which hid the explain button for good.
 * - ShareDialog: a refused permission change left the select on the refused value.
 * - practicumEngine: blocked localStorage threw from persist(), so a step never advanced.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (_app, text) => text
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	t: (_app, text) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import TrainingMode from '../../src/components/TrainingMode.vue'
import ShareDialog from '../../src/components/ShareDialog.vue'
import { PracticumEngine } from '../../src/utils/practicumEngine.js'

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
	vi.clearAllMocks()
})

describe('TrainingMode explain polling (audit #106)', () => {
	it('stops polling when the training is left', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue({ data: { status: 'running' } })
		const vm = { explainTaskId: 't1', explainPollTimer: null }
		TrainingMode.methods.pollExplain.call(vm)

		TrainingMode.beforeUnmount.call(vm)
		await vi.advanceTimersByTimeAsync(4500)

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('a failed explanation clears the task id so the button comes back', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue({ data: { status: 'failed' } })
		const vm = { explainTaskId: 't1', explainPollTimer: null, explainLoading: true }
		TrainingMode.methods.pollExplain.call(vm)

		await vi.advanceTimersByTimeAsync(1500)

		expect(vm.explainTaskId).toBeNull()
		expect(vm.explainLoading).toBe(false)
	})

	it('a failed status request clears the task id too', async () => {
		vi.useFakeTimers()
		axios.get.mockRejectedValue(new Error('500'))
		const vm = { explainTaskId: 't1', explainPollTimer: null, explainLoading: true }
		TrainingMode.methods.pollExplain.call(vm)

		await vi.advanceTimersByTimeAsync(1500)

		expect(vm.explainTaskId).toBeNull()
	})
})

describe('ShareDialog permission change (audit #106)', () => {
	it('a refused change puts the select back on the stored permission', async () => {
		axios.put.mockRejectedValue(new Error('403'))
		const share = { shared_with: 'bob', permission: 'read' }
		const select = { value: 'edit' }

		await ShareDialog.methods.updatePermission.call({ poolId: 3 }, share, 'edit', select)

		expect(select.value).toBe('read')
		expect(share.permission).toBe('read')
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('a saved change keeps the new value', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const share = { shared_with: 'bob', permission: 'read' }
		const select = { value: 'edit' }

		await ShareDialog.methods.updatePermission.call({ poolId: 3 }, share, 'edit', select)

		expect(select.value).toBe('edit')
		expect(share.permission).toBe('edit')
	})
})

describe('practicumEngine with blocked storage (audit #106)', () => {
	const session = { id: 's1', steps: [{ stepId: 'a' }, { stepId: 'b' }] }
	const blocked = () => { throw new DOMException('blocked', 'SecurityError') }

	// A stand-in storage whose one method misbehaves, installed as the global localStorage.
	function withStorage(method, impl, fn) {
		const store = {
			getItem: () => null,
			setItem: () => {},
			removeItem: () => {},
			[method]: impl,
		}
		vi.stubGlobal('localStorage', store)
		try {
			fn()
		} finally {
			vi.unstubAllGlobals()
		}
	}

	it('persist does not throw when storage refuses the write', () => {
		withStorage('setItem', blocked, () => {
			const engine = new PracticumEngine(session)
			expect(() => engine.persist()).not.toThrow()
		})
	})

	it('restore returns null when storage cannot be read', () => {
		withStorage('getItem', blocked, () => {
			expect(PracticumEngine.restore('s1', session)).toBeNull()
		})
	})

	it('restore returns null for a corrupt entry', () => {
		withStorage('getItem', () => '{not json', () => {
			expect(PracticumEngine.restore('s1', session)).toBeNull()
		})
	})

	it('reset does not throw when storage refuses the delete', () => {
		withStorage('removeItem', blocked, () => {
			const engine = new PracticumEngine(session)
			expect(() => engine.reset()).not.toThrow()
			expect(engine.stepIndex).toBe(0)
		})
	})
})
