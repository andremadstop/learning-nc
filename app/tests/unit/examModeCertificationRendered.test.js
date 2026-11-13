/**
 * GitHub #35: the certification note on the exam result screen, rendered.
 *
 * examModeCertification.test.js checks the message object; this mounts the component so a
 * field the template does not read (the expiry line) turns red.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub, slotStub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
	slotStub: (name, cls) => ({ default: { name, template: `<div class="${cls}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'nc-button'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'nc-note'))
vi.mock('@nextcloud/vue/components/NcModal', () => stub('NcModal'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))

import ExamMode from '../../src/components/ExamMode.vue'

const EXPIRES = 1767225600
const expiredOn = new Date(EXPIRES * 1000).toLocaleDateString()
const expiredCert = { verification_id: 'vid-old', issued_at: 1735689600, expires_at: EXPIRES, expired: true, revoked: false }
const policy = { limit_per_day: 5, cooldown_minutes: 10, scope: 'pool' }

describe('ExamMode certification note rendered (GitHub #35)', () => {
	let host, app

	async function showResult(certification) {
		host = document.createElement('div')
		document.body.appendChild(host)
		const exam = ref(null)
		app = createApp({ render: () => h(ExamMode, { ref: exam, poolId: 3, courseId: 7, totalQuestions: 20 }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		exam.value.resultsData = { total_questions: 20, correct_answers: 12, score_percentage: 60, certification }
		exam.value.detailedResults = []
		exam.value.screen = 'results'
		await nextTick()
		return host.querySelector('.certification-result')
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('passed earlier with an expired certificate: the note states the expiry and when a retake counts', async () => {
		const note = await showResult({ applicable: true, passed: true, threshold: 70, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: expiredCert, attempt_policy: policy })

		expect(note).not.toBeNull()
		expect(note.textContent).toContain(`expired on ${expiredOn}`)
		expect(note.textContent).toContain('once the renewal period opens')
		expect(note.textContent).not.toContain('Open certificate')
	})

	it('not passed in the renewal: the expiry line is rendered above the verdict', async () => {
		const note = await showResult({ applicable: true, passed: false, threshold: 80, exam_passed: false, requirement: 'any_exam', certificate_issued: false, certificate: expiredCert, attempt_policy: policy })

		const lines = Array.from(note.querySelectorAll('.certification-result__text')).map((p) => p.textContent.trim())
		expect(lines).toEqual([`Your certificate for this course expired on ${expiredOn}.`, 'Not passed yet. The pass mark is 80%.'])
	})
})
