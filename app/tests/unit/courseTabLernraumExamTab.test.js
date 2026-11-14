/**
 * GitHub #39: the learner's Exam tab in a course's Lernraum.
 *
 * Mounts the real CourseTabLernraum (the createInstance harness in CourseTabLernraum.test.js never
 * renders the template, so a v-if/v-else chain bug is invisible to it):
 *  (e) "No pools assigned" sat under a filled pool list — its v-else hung on the blocker warning.
 *  (a) the cross-course Smart Queue hero is gone from the course; no queue-count request.
 *  (c/d/d2) a certified course shows each pool's certificate state from /pass-status instead of
 *      the learning-path "Required" badge; Training/Leitner and uncertified courses are unchanged.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { slotStub, emptyStub } = vi.hoisted(() => ({
	slotStub: (name, tag = 'div', cls = '') => ({ default: { name, template: `<${tag} class="${cls}"><slot /><slot name="description" /></${tag}>`, props: ['name'] } }),
	emptyStub: (name) => ({ default: { name, template: '<div />' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'button'))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => slotStub('NcCheckboxRadioSwitch'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-content">{{ name }}<slot name="description" /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => emptyStub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcModal', () => slotStub('NcModal'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'div', 'nc-note-card'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => emptyStub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => emptyStub('CourseMaterials'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => emptyStub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => emptyStub('LeitnerMode'))
vi.mock('../../src/components/TrainingMode.vue', () => emptyStub('TrainingMode'))
vi.mock('../../src/components/TrainingPrivacyNotice.vue', () => emptyStub('TrainingPrivacyNotice'))
vi.mock('../../src/components/ExamMode.vue', () => ({
	default: {
		name: 'ExamMode',
		props: ['poolId', 'courseId', 'practice', 'practiceConfig', 'totalQuestions', 'contentLanguage', 'courseCertification', 'poolPractice'],
		emits: ['back'],
		template: '<button class="exam-mode-back" @click="$emit(\'back\')">back</button>',
	},
}))

import axios from '@nextcloud/axios'
import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

const PASS_STATUS_URL = '/apps/learning/api/courses/7/pass-status'
const QUEUE_COUNT_URL = '/apps/learning/api/leitner/queue/count'

let app = null
let host = null
let vm = null
let passStatusResponse = null

async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
		await nextTick()
	}
}

async function mount({ course, coursePools, activeTab = 'exam' }) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(CourseTabLernraum, {
			courseId: 7, course, coursePools, allPools: [], activeTab, userRole: 'student', contentLanguage: 'de',
			ref: (r) => { vm = r },
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return host
}

function calls(url) {
	return axios.get.mock.calls.filter(([u]) => u === url).length
}

function rowFor(name) {
	return [...host.querySelectorAll('.pool-item')].find((el) => el.textContent.includes(name))
}

beforeEach(() => {
	vi.clearAllMocks()
	passStatusResponse = null
	axios.get.mockImplementation((url) => {
		if (url === PASS_STATUS_URL) {
			return passStatusResponse instanceof Error ? Promise.reject(passStatusResponse) : Promise.resolve({ data: passStatusResponse })
		}
		if (url === QUEUE_COUNT_URL) {
			return Promise.resolve({ data: { count: 3 } })
		}
		if (url.includes('/video-progress/course/')) {
			return Promise.resolve({ data: { videos: [] } })
		}
		return Promise.resolve({ data: [] })
	})
})

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
	vm = null
})

const pools = [
	{ pool_id: 3, pool_name: 'Brandschutz', question_count: 40, sort_order: 1, required: true },
	{ pool_id: 4, pool_name: 'Erste Hilfe', question_count: 25, sort_order: 2, required: true },
]
const itCourse = { is_instructor: false, mode_config: { training: true, leitner: true, exam: true } }
const certCourse = { ...itCourse, cert_enabled: true, cert_required_pool_ids: [3, 4], cert_pass_percent: 80 }
const requiredPoolsStatus = {
	applicable: true,
	passed: false,
	score: 88,
	threshold: 80,
	requirement: 'required_pools',
	pools: [
		{ pool_id: 3, title: 'Brandschutz', best_score: 88, passed: true },
		{ pool_id: 4, title: 'Erste Hilfe', best_score: null, passed: false },
	],
}

describe('CourseTabLernraum student pool list: empty state (GitHub #39 e)', () => {
	it('a filled pool list without blockers shows no "No pools assigned"', async () => {
		await mount({ course: itCourse, coursePools: pools })

		expect(host.querySelectorAll('.pool-item')).toHaveLength(2)
		expect(host.textContent).not.toContain('No pools assigned')
		expect(host.querySelector('.required-lock-note')).toBeNull()
	})

	it('without pools the empty state shows', async () => {
		await mount({ course: itCourse, coursePools: [] })

		expect(host.querySelectorAll('.pool-item')).toHaveLength(0)
		expect(host.textContent).toContain('No pools assigned')
	})

	it('an enforced, unfinished pool shows the warning and no empty state', async () => {
		await mount({ course: itCourse, coursePools: [{ ...pools[0], required_enforced: true, required_completed: false }, pools[1]] })

		expect(host.querySelector('.required-lock-note')).not.toBeNull()
		expect(host.textContent).not.toContain('No pools assigned')
	})
})

describe('CourseTabLernraum: no cross-course Smart Queue in the course (GitHub #39 a)', () => {
	it.each(['training', 'leitner', 'exam'])('the %s tab has no Smart Queue hero and asks for no queue count', async (tab) => {
		await mount({ course: itCourse, coursePools: pools, activeTab: tab })

		expect(host.querySelector('.smart-queue-hero')).toBeNull()
		expect(host.textContent).not.toContain('Smart Queue')
		expect(calls(QUEUE_COUNT_URL)).toBe(0)
	})
})

describe('CourseTabLernraum Exam tab: certificate state per pool (GitHub #39 c/d/d2)', () => {
	it('a certified course shows best result and passed / not passed per required pool, without "Required"', async () => {
		passStatusResponse = requiredPoolsStatus
		await mount({ course: certCourse, coursePools: pools })

		expect(calls(PASS_STATUS_URL)).toBe(1)
		const passedRow = rowFor('Brandschutz')
		expect(passedRow.textContent).toContain('Bestes Ergebnis: 88 %')
		expect(passedRow.textContent).toContain('✓ Bestanden')
		const openRow = rowFor('Erste Hilfe')
		expect(openRow.textContent).toContain('Noch keine Prüfung')
		expect(openRow.textContent).toContain('Noch nicht bestanden')
		expect(host.querySelector('.required-badge:not(.required-enforced-badge)')).toBeNull()
		expect(host.textContent).not.toContain('Required')
		expect(host.textContent).toContain('Bestehensgrenze: 80 %')
	})

	it('pools the certificate does not require show no certificate state', async () => {
		passStatusResponse = { ...requiredPoolsStatus, pools: [requiredPoolsStatus.pools[0]] }
		await mount({ course: { ...certCourse, cert_required_pool_ids: [3] }, coursePools: pools })

		expect(rowFor('Brandschutz').textContent).toContain('✓ Bestanden')
		expect(rowFor('Erste Hilfe').querySelector('.exam-cert-status')).toBeNull()
	})

	it('the "Enforced" lock stays visible in the Exam tab', async () => {
		passStatusResponse = requiredPoolsStatus
		await mount({ course: certCourse, coursePools: [{ ...pools[0], required_enforced: true, required_completed: true }, pools[1]] })

		expect(rowFor('Brandschutz').textContent).toContain('Enforced')
	})

	it('"any exam" courses show the overall best result once above the list', async () => {
		passStatusResponse = { applicable: true, passed: true, score: 91, threshold: 75, requirement: 'any_exam', pools: [] }
		await mount({ course: { ...certCourse, cert_required_pool_ids: [] }, coursePools: pools })

		const summary = host.querySelector('.exam-cert-summary')
		expect(summary.textContent).toContain('Bestehensgrenze: 75 %')
		expect(summary.textContent).toContain('Bestes Ergebnis: 91 %')
		expect(summary.textContent).toContain('✓ Bestanden')
		expect(host.querySelectorAll('.pool-item .exam-cert-status')).toHaveLength(0)
	})

	it('Training in a certified course keeps "Required" and asks for no pass status', async () => {
		passStatusResponse = requiredPoolsStatus
		await mount({ course: certCourse, coursePools: pools, activeTab: 'training' })

		expect(host.querySelectorAll('.required-badge')).toHaveLength(2)
		expect(calls(PASS_STATUS_URL)).toBe(0)
	})

	it('a course without certification keeps "Required" in the Exam tab and asks for no pass status', async () => {
		await mount({ course: itCourse, coursePools: pools })

		expect(host.querySelectorAll('.required-badge')).toHaveLength(2)
		expect(host.querySelector('.exam-cert-summary')).toBeNull()
		expect(calls(PASS_STATUS_URL)).toBe(0)
	})

	it('a failing pass status leaves the list usable and shows no state', async () => {
		passStatusResponse = new Error('429')
		await mount({ course: certCourse, coursePools: pools })

		expect(host.querySelectorAll('.pool-item')).toHaveLength(2)
		expect(host.querySelector('.exam-cert-status')).toBeNull()
		expect(host.querySelector('.exam-cert-summary')).toBeNull()
	})

	it('loads once on entering the tab and once more after returning from an exam', async () => {
		passStatusResponse = requiredPoolsStatus
		await mount({ course: certCourse, coursePools: pools })
		expect(calls(PASS_STATUS_URL)).toBe(1)

		vm.selectedLearningPool = pools[0]
		await flush()
		expect(calls(PASS_STATUS_URL)).toBe(1)

		passStatusResponse = { ...requiredPoolsStatus, pools: [requiredPoolsStatus.pools[0], { ...requiredPoolsStatus.pools[1], best_score: 82, passed: true }] }
		host.querySelector('.exam-mode-back').click()
		await flush()

		expect(calls(PASS_STATUS_URL)).toBe(2)
		expect(rowFor('Erste Hilfe').textContent).toContain('Bestes Ergebnis: 82 %')
	})
})
