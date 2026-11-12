/**
 * 2026-11 audit H4: the exam sends every answer in one batch when the timer runs out. Fired at
 * 0 s left, that request reached the server after the deadline and was refused, so the learner
 * scored 0 %. The countdown now submits a few seconds before the deadline.
 *
 * Same convention as examModePractice.test.js: the component definition is exercised directly.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
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

globalThis.t = (app, text) => text

import ExamMode from '../../src/components/ExamMode.vue'

function createInstance() {
	const instance = {
		poolId: 42,
		courseId: null,
		practice: false,
		practiceConfig: null,
		totalQuestions: 0,
		contentLanguage: '',
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

describe('ExamMode — automatic submission before the deadline', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-12T10:00:00Z'))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('submits at 5 seconds left, not earlier and not at zero', () => {
		const vm = createInstance()
		const finish = vi.fn()
		vm.finishExam = finish
		vm.examDeadlineAt = Math.floor(Date.now() / 1000) + 10
		vm.startTimer()

		vi.advanceTimersByTime(4000) // 6 s left
		expect(vm.timeLeftSeconds).toBe(6)
		expect(finish).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1000) // 5 s left
		expect(vm.timeLeftSeconds).toBe(5)
		expect(finish).toHaveBeenCalledWith({ forceTimedOut: true })
		clearInterval(vm.timerInterval)
	})
})
