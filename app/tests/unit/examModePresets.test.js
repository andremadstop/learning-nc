/**
 * ExamMode setup cards (GitHub #35).
 *
 * Before, a pool's exam mode offered two hard-coded CompTIA presets — 90 questions / 90 minutes
 * and a 45-question "Practice Exam" — judged on the 720/900 scale. The 45-question card was a
 * real counting exam despite its name, and the 720/900 verdict ignored the course pass mark.
 * Now the exam card is sized by the pool, a certifying course judges by its pass mark, and the
 * second card is a practice run that never counts (exam_kind 'practice').
 *
 * Same convention as the other component tests: the component definition is exercised directly,
 * with every computed bound as a live getter so methods see real values.
 */
import fs from 'fs'
import path from 'path'
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
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

import axios from '@nextcloud/axios'
import ExamMode from '../../src/components/ExamMode.vue'

const examModeSource = fs.readFileSync(path.resolve(__dirname, '../../src/components/ExamMode.vue'), 'utf8')
const trainingServiceSource = fs.readFileSync(path.resolve(__dirname, '../../lib/Service/TrainingService.php'), 'utf8')

function createInstance(props = {}) {
	const instance = {
		poolId: 42,
		courseId: 7,
		practice: false,
		practiceConfig: null,
		totalQuestions: 30,
		contentLanguage: '',
		courseCertification: null,
		poolPractice: null,
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
	const now = Math.floor(Date.now() / 1000)
	return {
		data: {
			session_id: 900,
			questions: [{ id: 10, question_type: 'single', text: 'Q', answers: [{ id: 100, text: 'A' }] }],
			server_time: now,
			started_at: now,
			time_limit_seconds: 2700,
			exam_deadline_at: now + 2700,
			attempt_no: 1,
			...overrides,
		},
	}
}

const practiceRules = { questions: 20, minutes: 0, passPercent: 70 }

describe('ExamMode setup — exam card sized by the pool', () => {
	it('asks for every question of a small pool, 1.5 minutes each', () => {
		const vm = createInstance({ totalQuestions: 30 })
		const [exam] = vm.visiblePresets

		expect(exam.kind).toBe('exam')
		expect(exam.questionCount).toBe(30)
		expect(exam.timeLimitSeconds).toBe(45 * 60)
		expect(exam.meta).toBe('Questions: up to 30 · time limit: 45 min · pass mark: 720/900')
	})

	it('caps a large pool at 90 questions and the time at the server maximum of 120 minutes', () => {
		const vm = createInstance({ totalQuestions: 400 })
		const [exam] = vm.visiblePresets

		expect(exam.questionCount).toBe(90)
		expect(exam.timeLimitSeconds).toBe(7200)
	})

	it('disables the card when the pool has no questions instead of starting a 60-second whole-pool exam', () => {
		const vm = createInstance({ totalQuestions: 0 })
		const [exam] = vm.visiblePresets

		expect(exam.disabled).toBe(true)
		expect(exam.meta).toBe('No questions available')
	})

	it('no longer speaks of the real CompTIA exam, and the 45-question "Practice Exam" is gone', () => {
		const vm = createInstance()

		expect(vm.setupDescription).toBe('Exam mode: no feedback until the end.')
		expect(vm.visiblePresets.map((p) => p.title)).not.toContain('Practice Exam')
		expect(examModeSource).not.toContain('Simulate the real CompTIA exam')
		expect(examModeSource).not.toContain('45 questions · 45 minutes')
	})

	it('keeps the deterministic answer shuffling of exam sessions', () => {
		expect(trainingServiceSource).toContain('private function shuffleAnswersForSession')
		expect(trainingServiceSource).toContain('$answers = $this->shuffleAnswersForSession($answers, $sessionId, (int)$q->getId());')
	})
})

describe('ExamMode setup — certifying course', () => {
	it('says the exam counts and names the course pass mark', () => {
		const vm = createInstance({ courseCertification: { passPercent: 80, countsForCertificate: true } })

		expect(vm.setupDescription).toBe('This exam counts towards your certificate. Pass mark: 80%. No feedback until the end.')
		expect(vm.visiblePresets[0].meta).toBe('Questions: up to 30 · time limit: 45 min · pass mark: 80%')
	})

	it('says so when the certificate does not need this pool', () => {
		const vm = createInstance({ courseCertification: { passPercent: 80, countsForCertificate: false } })

		expect(vm.setupDescription).toBe('This pool is not required for the certificate. No feedback until the end.')
	})

	it('ignores a certificate rule without a course (exam outside a course never counts)', () => {
		const vm = createInstance({ courseId: null, courseCertification: { passPercent: 80, countsForCertificate: true } })

		expect(vm.certificationRule).toBeNull()
		expect(vm.setupDescription).toBe('Exam mode: no feedback until the end.')
	})
})

describe('ExamMode result — course pass mark instead of 720/900', () => {
	it('judges by the course pass mark even when the completion carried no certification block', () => {
		const vm = createInstance({ courseCertification: { passPercent: 70, countsForCertificate: true } })
		// 15/20 = 75 % → 675/900 would be "Not passed" on the preset scale.
		vm.resultsData = { total_questions: 20, correct_answers: 15, score_percentage: 75 }

		expect(vm.usesPercentScore).toBe(true)
		expect(vm.passedExam).toBe(true)
		expect(vm.coursePassMark).toEqual({ threshold: 70, passed: true })
	})

	it('fails below the course pass mark', () => {
		const vm = createInstance({ courseCertification: { passPercent: 80, countsForCertificate: true } })
		vm.resultsData = { total_questions: 20, correct_answers: 15, score_percentage: 75 }

		expect(vm.passedExam).toBe(false)
	})

	it('rounds like the server (CertificationTrigger): 149/200 = 74.5 % passes 75 %', () => {
		const vm = createInstance({ courseCertification: { passPercent: 75, countsForCertificate: true } })
		vm.resultsData = { total_questions: 200, correct_answers: 149, score_percentage: 74.5 }

		expect(vm.passedExam).toBe(true)
	})

	it('prefers the server judgement when the block has one', () => {
		const vm = createInstance({ courseCertification: { passPercent: 70, countsForCertificate: true } })
		vm.resultsData = { total_questions: 20, correct_answers: 15, score_percentage: 75, certification: { applicable: true, threshold: 90, exam_passed: false } }

		expect(vm.coursePassMark).toEqual({ threshold: 90, passed: false })
	})

	it('keeps the 720/900 scale outside a certifying course', () => {
		const vm = createInstance()
		vm.resultsData = { total_questions: 20, correct_answers: 16, score_percentage: 80 }

		expect(vm.usesPercentScore).toBe(false)
		expect(vm.passedExam).toBe(true) // 16/20 → 720/900
		expect(examModeSource).toContain("{{ t('learning', 'Passing score: {score}/{max}', { score: examPassScore, max: examMaxScore }) }}")
	})
})

describe('ExamMode setup — practice run card', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('is hidden when the course offers no practice exams', () => {
		const vm = createInstance({ poolPractice: null })

		expect(vm.visiblePresets.map((p) => p.kind)).toEqual(['exam'])
	})

	it('is hidden outside a course', () => {
		const vm = createInstance({ courseId: null, poolPractice: practiceRules })

		expect(vm.visiblePresets.map((p) => p.kind)).toEqual(['exam'])
	})

	it('follows the course practice rules, capped by the pool, and says it does not count', () => {
		const vm = createInstance({ totalQuestions: 12, poolPractice: { questions: 20, minutes: 15, passPercent: 65 } })
		const practiceRun = vm.visiblePresets[1]

		expect(practiceRun.kind).toBe('practice-run')
		expect(practiceRun.title).toBe('Practice run')
		expect(practiceRun.meta).toBe('Questions: up to 12 · time limit: 15 min · pass mark: 65%')
		expect(practiceRun.note).toBe('Does not count towards the certificate. Review with explanations at the end.')
	})

	/** The request body is the contract: without examKind the server starts a COUNTING exam. */
	it('starts with examKind practice in the request body, without a client-chosen length', async () => {
		axios.post.mockResolvedValue(startResponse({ exam_kind: 'practice', pass_percent: 70, time_limit_seconds: null, exam_deadline_at: null }))
		const vm = createInstance({ poolPractice: practiceRules })

		await vm.startExam(vm.visiblePresets[1])

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/training/start', { poolId: 42, mode: 'exam', examKind: 'practice', courseId: 7 })
		expect(vm.practiceRun).toBe(true)
		expect(vm.screen).toBe('exam')
		vm.stopStatusPolling()
		vm.releaseExamLock()
	})

	// An untimed practice run must not fall back to the exam card's time limit and auto-submit.
	it('runs no countdown when the practice rules have no time limit', async () => {
		axios.post.mockResolvedValue(startResponse({ exam_kind: 'practice', pass_percent: 70, time_limit_seconds: null, exam_deadline_at: null }))
		const vm = createInstance({ poolPractice: practiceRules })
		const finish = vi.fn()
		vm.finishExam = finish

		await vm.startExam(vm.visiblePresets[1])

		expect(vm.isTimed).toBe(false)
		expect(vm.timerInterval).toBeNull()
		vi.advanceTimersByTime(5000)
		expect(finish).not.toHaveBeenCalled()
		vm.stopStatusPolling()
		vm.releaseExamLock()
	})

	it('the exam card sends no examKind and asks for the pool-sized length', async () => {
		axios.post.mockResolvedValue(startResponse())
		const vm = createInstance({ poolPractice: practiceRules, totalQuestions: 30 })

		await vm.startExam(vm.visiblePresets[0])

		const body = axios.post.mock.calls[0][1]
		expect(body).toEqual({ poolId: 42, mode: 'exam', limit: 30, timeLimitSeconds: 2700, courseId: 7 })
		expect(body).not.toHaveProperty('examKind')
		expect(vm.practiceRun).toBe(false)
		vm.retakeExam()
	})

	it('a practice run result takes the verdict from the server', () => {
		const vm = createInstance({ poolPractice: practiceRules, courseCertification: { passPercent: 90, countsForCertificate: true } })
		vm.practiceRun = true
		vm.resultsData = { exam_kind: 'practice', passed: true, pass_percent: 70, correct_answers: 15, total_questions: 20, score_percentage: 75 }

		expect(vm.isPracticeResult).toBe(true)
		expect(vm.passedExam).toBe(true)
		expect(vm.coursePassMark).toBeNull()
		expect(vm.certificationMessage).toBeNull()
	})
})
