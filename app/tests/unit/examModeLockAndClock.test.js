/**
 * Audit #36, #181, #31, #239 (ExamMode):
 * - #36: the read-only second tab holds none of the answers. It must neither end nor abort the
 *   exam, and at the deadline it waits for the result instead of submitting an empty batch.
 * - #181: the countdown runs on the server's clock (offset from server_time), and a start inside
 *   the previous exam's grace period gets a translated message.
 * - #31: aborting a counting exam says it still counts as an attempt.
 * - #239: the instructor note in the review comes from the server review entry.
 *
 * Same convention as examModeAutoSubmit.test.js: the component definition is exercised directly.
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

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import ExamMode from '../../src/components/ExamMode.vue'

function createInstance(props = {}) {
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
		...props,
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

describe('ExamMode — read-only second tab (audit #36)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-14T10:00:00Z'))
	})
	afterEach(() => {
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	it('cannot end or abort the exam', async () => {
		const vm = createInstance()
		vm.lockDenied = true
		vm.session = 7
		vm.screen = 'exam'
		const confirm = vi.fn(() => true)
		vi.stubGlobal('confirm', confirm)

		vm.confirmEndExam()
		vm.confirmAbortExam()
		await vm.finishExam()

		expect(confirm).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.screen).toBe('exam')
	})

	it('polls for the result at the deadline instead of submitting', () => {
		const vm = createInstance()
		vm.lockDenied = true
		vm.session = 7
		vm.screen = 'exam'
		const finish = vi.fn()
		const refresh = vi.fn()
		vm.finishExam = finish
		vm.refreshSessionStatus = refresh
		vm.examDeadlineAt = Math.floor(Date.now() / 1000) + 6
		vm.startTimer()

		vi.advanceTimersByTime(1000) // 5 s left: the answering tab submits now
		expect(finish).not.toHaveBeenCalled()
		expect(refresh).toHaveBeenCalledTimes(1)

		vi.advanceTimersByTime(3000) // throttled: at most one status read per 5 s
		expect(refresh).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(2000)
		expect(refresh).toHaveBeenCalledTimes(2)
		expect(finish).not.toHaveBeenCalled()
		clearInterval(vm.timerInterval)
	})

	it('stays read-only at the deadline when the answering tab frees the lock to submit', async () => {
		const store = new Map()
		vi.stubGlobal('localStorage', {
			getItem: (k) => (store.has(k) ? store.get(k) : null),
			setItem: (k, v) => store.set(k, String(v)),
			removeItem: (k) => store.delete(k),
		})
		const vm = createInstance()
		vm.session = 7
		vm.screen = 'exam'
		vm.questions = [{ id: 10, text: 'Q10', question_type: 'single', answers: [{ id: 100, text: 'Yes' }] }]
		vm.refreshSessionStatus = vi.fn()
		// Tab A holds the lock.
		store.set('learning_exam_lock_7', JSON.stringify({ tabId: 'tab_A', ts: Date.now() }))
		vm.startExamLock()
		expect(vm.lockDenied).toBe(true)
		vm.examDeadlineAt = Math.floor(Date.now() / 1000) + 6
		vm.startTimer()

		vi.advanceTimersByTime(1000) // deadline lead reached while locked
		expect(vm.refreshSessionStatus).toHaveBeenCalledTimes(1)

		// Tab A starts finishExam: it frees the lock before its batch reaches the server.
		store.delete('learning_exam_lock_7')
		vi.advanceTimersByTime(20000) // heartbeats and ticks would have taken the lock over

		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.lockDenied).toBe(true)
		expect(store.has('learning_exam_lock_7')).toBe(false)
		expect(vm.refreshSessionStatus.mock.calls.length).toBeGreaterThan(1)
		await vm.finishExam({ forceTimedOut: true })
		expect(axios.post).not.toHaveBeenCalled()
		clearInterval(vm.timerInterval)
		vm.stopLockHeartbeat()
	})

	it('the answering tab still submits at the deadline', () => {
		const vm = createInstance()
		const finish = vi.fn()
		vm.finishExam = finish
		vm.examDeadlineAt = Math.floor(Date.now() / 1000) + 6
		vm.startTimer()
		vi.advanceTimersByTime(1000)
		expect(finish).toHaveBeenCalledWith({ forceTimedOut: true })
		clearInterval(vm.timerInterval)
	})
})

describe('ExamMode — countdown on the server clock (audit #181)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-14T10:00:00Z'))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('a local clock two minutes fast does not end the exam early', () => {
		const vm = createInstance()
		const finish = vi.fn()
		vm.finishExam = finish
		const localNow = Math.floor(Date.now() / 1000)
		const serverNow = localNow - 120 // the local clock runs 2 minutes ahead
		vm.clockOffsetSeconds = serverNow - Date.now() / 1000
		vm.examDeadlineAt = serverNow + 60
		vm.startTimer()

		vi.advanceTimersByTime(1000)
		expect(vm.timeLeftSeconds).toBe(59)
		expect(finish).not.toHaveBeenCalled()
		clearInterval(vm.timerInterval)
	})

	it('a status read keeps the countdown on the server clock', async () => {
		const vm = createInstance()
		vm.session = 7
		vm.screen = 'exam'
		const serverNow = Math.floor(Date.now() / 1000) - 120
		axios.get.mockResolvedValueOnce({ data: { server_time: serverNow, remaining_seconds: 300, completed: false } })

		await vm.refreshSessionStatus()

		expect(Math.round(vm.serverNowSeconds())).toBe(serverNow)
		vm.examDeadlineAt = serverNow + 300
		vm.finishExam = vi.fn()
		vm.startTimer()
		vi.advanceTimersByTime(1000)
		expect(vm.timeLeftSeconds).toBe(299)
		clearInterval(vm.timerInterval)
	})

	it('a start inside the previous exam\'s grace period gets a translated message', async () => {
		const vm = createInstance()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Exam timed out' } } })
		axios.get.mockResolvedValue({ data: {} })

		await vm.startExam({ timeLimitSeconds: 600, questionCount: 10 })

		expect(showError).toHaveBeenCalledWith('Your previous exam has just run out of time. Please try again in a moment.')
	})
})

describe('ExamMode — abort and review texts', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('aborting a counting exam says it still counts (audit #31)', () => {
		const vm = createInstance()
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		vm.confirmAbortExam()
		expect(confirm.mock.calls[0][0]).toContain('It still counts as an attempt')
	})

	it('aborting a practice exam keeps the discard text', () => {
		const vm = createInstance({ practice: true, practiceConfig: { passPercent: 75 } })
		const confirm = vi.fn(() => false)
		vi.stubGlobal('confirm', confirm)
		vm.confirmAbortExam()
		expect(confirm.mock.calls[0][0]).toContain('Your progress will be discarded')
	})

	it('the review shows the instructor note from the server review (audit #239)', async () => {
		const vm = createInstance()
		vm.session = 7
		vm.questions = [
			{ id: 10, text: 'Q10', question_type: 'single', answers: [{ id: 100, text: 'Yes' }] },
			{ id: 11, text: 'Q11', question_type: 'single', answers: [{ id: 110, text: 'No' }] },
		]
		vm.userAnswers = { 10: 100 }
		axios.post.mockImplementation((url) => {
			if (url.endsWith('/complete')) {
				return Promise.resolve({ data: {
					total_questions: 2, correct_answers: 1, score_percentage: 50,
					review: [
						{ questionId: 10, questionText: 'Q10', is_correct: true, correct_answer_texts: ['Yes'], answers: [], instructor_note: 'Remember the port list.' },
						{ questionId: 11, questionText: 'Q11', is_correct: false, correct_answer_texts: ['Right'], answers: [], instructor_note: null },
					],
				} })
			}
			return Promise.resolve({ data: {} })
		})

		await vm.finishExam()

		expect(vm.detailedResults[0].noteVisible).toBe(true)
		expect(vm.detailedResults[0].instructorNote).toBe('Remember the port list.')
		expect(vm.detailedResults[1].noteVisible).toBe(false)
	})
})
