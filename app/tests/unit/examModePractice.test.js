/**
 * ExamMode as a course practice exam (Codeberg #9).
 *
 * Same convention as the other component tests (no @vue/test-utils): the component definition
 * is exercised directly, with every computed bound as a live getter so methods see real values.
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

function startResponse(overrides = {}) {
	return {
		data: {
			session_id: 501,
			questions: [{ id: 10, question_type: 'single', text: 'Q', answers: [{ id: 100, text: 'A' }] }],
			server_time: Math.floor(Date.now() / 1000),
			started_at: Math.floor(Date.now() / 1000),
			time_limit_seconds: null,
			exam_deadline_at: null,
			attempt_no: 1,
			exam_kind: 'practice',
			pass_percent: 75,
			...overrides,
		},
	}
}

describe('ExamMode — course practice exam', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('offers exactly one card built from the course configuration', () => {
		const vm = createInstance({ practice: true, practiceConfig: { questions: 30, minutes: 0, passPercent: 70 } })
		expect(vm.visiblePresets).toHaveLength(1)
		expect(vm.visiblePresets[0].meta).toBe('Questions: 30 · no time limit · pass mark: 70%')
	})

	it('leaves the CompTIA presets untouched outside practice mode', () => {
		const vm = createInstance({ poolId: 42 })
		expect(vm.visiblePresets.map((p) => p.id)).toEqual(['full', 'light'])
	})

	it('starts through the course endpoint, not the pool endpoint', async () => {
		axios.post.mockResolvedValue(startResponse())
		const vm = createInstance({ practice: true, practiceConfig: { questions: 1, minutes: 0, passPercent: 75 } })
		await vm.startExam(vm.visiblePresets[0])
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/courses/7/practice-exam/start', {})
		expect(vm.screen).toBe('exam')
	})

	/**
	 * The regression that matters: with no deadline and timeLeftSeconds 0, the countdown's
	 * `timeLeftSeconds <= 0` branch auto-submitted the exam one second after it started.
	 */
	it('never runs a countdown for an untimed practice exam', async () => {
		axios.post.mockResolvedValue(startResponse())
		const vm = createInstance({ practice: true, practiceConfig: { questions: 1, minutes: 0, passPercent: 75 } })
		const finish = vi.spyOn(vm, 'finishExam')
		vm.finishExam = finish
		await vm.startExam(vm.visiblePresets[0])

		expect(vm.isTimed).toBe(false)
		expect(vm.timerInterval).toBeNull()
		vi.advanceTimersByTime(5000)
		expect(finish).not.toHaveBeenCalled()
		expect(vm.screen).toBe('exam')
		vm.stopStatusPolling()
		vm.releaseExamLock()
	})

	it('runs the countdown when the practice exam has a time limit', async () => {
		const now = Math.floor(Date.now() / 1000)
		axios.post.mockResolvedValue(startResponse({ time_limit_seconds: 1800, exam_deadline_at: now + 1800 }))
		const vm = createInstance({ practice: true, practiceConfig: { questions: 1, minutes: 30, passPercent: 75 } })
		await vm.startExam(vm.visiblePresets[0])
		expect(vm.isTimed).toBe(true)
		expect(vm.timerInterval).not.toBeNull()
		vm.retakeExam()
	})

	it('takes the pass verdict from the server, not from the 720/900 scale', () => {
		const vm = createInstance({ practice: true })
		// 80 % would pass 720/900, but the course threshold was 85 % and the server said no.
		vm.resultsData = { exam_kind: 'practice', passed: false, pass_percent: 85, correct_answers: 8, total_questions: 10, score_percentage: 80 }
		expect(vm.passedExam).toBe(false)
		vm.resultsData = { ...vm.resultsData, passed: true }
		expect(vm.passedExam).toBe(true)
	})

	it('flags a withheld review so questions are not all shown as wrong', () => {
		const vm = createInstance({ practice: true })
		vm.resultsData = { exam_kind: 'practice', review_withheld: true, review: [] }
		expect(vm.reviewWithheld).toBe(true)
	})
})
