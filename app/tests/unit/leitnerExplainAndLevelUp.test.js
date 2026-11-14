/**
 * #101: a failed or unreachable AI explanation left explainTaskId set, so the button stayed hidden
 *       with no message and no retry; leaving Leitner did not stop the poll.
 * #102: the level-up overlay mounts with the result screen, after the review set the levels, and
 *       its watcher was not immediate, so Leitner and Smart Queue never showed it.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebrateMastery: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: () => false }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import LeitnerMode from '../../src/components/LeitnerMode.vue'
import LevelUpOverlay from '../../src/components/LevelUpOverlay.vue'

function createLeitnerVm() {
	const vm = { $emit: vi.fn(), $nextTick: (fn) => fn && fn(), $refs: {}, poolId: 1, contentLanguage: '' }
	Object.assign(vm, LeitnerMode.data.call(vm))
	for (const [name, fn] of Object.entries(LeitnerMode.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('LeitnerMode AI explanation (audit #101)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		axios.get.mockReset()
		showError.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('a failed task brings the button back and says so', async () => {
		const vm = createLeitnerVm()
		vm.explainTaskId = 'task-1'
		vm.explainLoading = true
		axios.get.mockResolvedValue({ data: { status: 'failed' } })
		vm.pollExplain()

		await vi.advanceTimersByTimeAsync(1600)

		expect(vm.explainTaskId).toBeNull()
		expect(vm.explainLoading).toBe(false)
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('a poll error brings the button back as well', async () => {
		const vm = createLeitnerVm()
		vm.explainTaskId = 'task-1'
		axios.get.mockRejectedValue(new Error('offline'))
		vm.pollExplain()

		await vi.advanceTimersByTimeAsync(1600)

		expect(vm.explainTaskId).toBeNull()
	})

	it('leaving the view stops the poll', async () => {
		const vm = createLeitnerVm()
		vm.explainTaskId = 'task-1'
		axios.get.mockResolvedValue({ data: { status: 'running' } })
		vm.pollExplain()
		await vi.advanceTimersByTimeAsync(1600)
		const calls = axios.get.mock.calls.length

		LeitnerMode.beforeUnmount.call(vm)
		await vi.advanceTimersByTimeAsync(6000)

		expect(axios.get.mock.calls.length).toBe(calls)
	})
})

describe('Level-up overlay (audit #102)', () => {
	let host, app
	afterEach(() => {
		app?.unmount()
		host?.remove()
		vi.useRealTimers()
	})

	it('shows a level-up that happened before it was mounted', async () => {
		vi.useFakeTimers()
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(LevelUpOverlay, { levelBefore: 3, levelAfter: 4 }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await nextTick()

		expect(host.querySelector('.levelup-overlay')).not.toBeNull()
		expect(host.textContent).toContain('Level 4')
	})

	it('Leitner keeps a level-up from the middle of the review for the result screen', () => {
		const vm = createLeitnerVm()
		vm.dueQuestions = [{ id: 1 }, { id: 2 }]
		vm.applyFinalReview({ correct: true, new_box: 2, level_before: 3, level_after: 4 }, 3)
		vm.applyFinalReview({ correct: true, new_box: 2, level_before: 4, level_after: 4 }, 3)

		expect([vm.levelBefore, vm.levelAfter]).toEqual([3, 4])

		vm.finishReview = LeitnerMode.methods.finishReview.bind(Object.assign(vm, { checkInitialized: vi.fn(), fetchStreak: vi.fn() }))
		vm.finishReview()
		expect([vm.levelBefore, vm.levelAfter]).toEqual([0, 0])
	})
})
