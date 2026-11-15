/**
 * GitHub #36, fresh-eyes review of the persona run "off" (gamification, AI and Leitner switched
 * off, an annual mandatory training): a reviewer without code knowledge found game parts and
 * misleading labels that the earlier #36 work had missed. Rendered for real, with the switch off
 * and — the IT courses that must not change — on.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { routes: {} } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => Promise.resolve({ data: api.routes[url] ?? {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'learner', displayName: 'Lena' })) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
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
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcActions', () => stub('NcActions'))
vi.mock('@nextcloud/vue/components/NcActionButton', () => stub('NcActionButton'))
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/ShareDialog.vue', () => stub('ShareDialog'))
vi.mock('../../src/components/DailyChallengeCard.vue', () => slotStub('DailyChallengeCard', 'daily-challenge-stub'))
vi.mock('../../src/components/AccessibleDialog.vue', () => stub('AccessibleDialog'))
vi.mock('../../src/components/PoolGenerator.vue', () => stub('PoolGenerator'))
vi.mock('../../src/components/PoolBulkImportDialog.vue', () => stub('PoolBulkImportDialog'))

import ExamMode from '../../src/components/ExamMode.vue'
import PoolList from '../../src/components/PoolList.vue'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app

async function render(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	const instanceRef = ref(null)
	app = createApp({ render: () => h(component, { ref: instanceRef, ...props }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return instanceRef.value
}

beforeEach(() => {
	api.routes = {}
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

describe('ExamMode: which exam, and no speed score with gamification off (GitHub #36)', () => {
	it('names the pool in the heading', async () => {
		await render(ExamMode, { poolId: 3, courseId: 7, totalQuestions: 8, poolName: 'Brandschutz' })
		expect(host.querySelector('.exam-title').textContent).toBe('Prüfung: Brandschutz')
	})

	it('keeps the old heading without a pool name, and the practice exam its own', async () => {
		await render(ExamMode, { poolId: 3, totalQuestions: 8 })
		expect(host.querySelector('.exam-title').textContent).toBe('Exam Mode')
		app.unmount()
		host.remove()
		await render(ExamMode, { courseId: 7, totalQuestions: 8, practice: true, practiceConfig: { questions: 8, minutes: 10, passPercent: 70 }, poolName: 'Brandschutz' })
		expect(host.querySelector('.exam-title').textContent).toBe('Course practice exam')
	})

	async function results(props) {
		const exam = await render(ExamMode, { poolId: 3, courseId: 7, totalQuestions: 8, ...props })
		exam.resultsData = { total_questions: 8, correct_answers: 8, score_percentage: 100 }
		exam.detailedResults = []
		exam.screen = 'results'
		await nextTick()
		return Array.from(host.querySelectorAll('.stat-label')).map((el) => el.textContent.trim())
	}

	it('shows no questions-per-minute tile with gamification off', async () => {
		const labels = await results({ gamificationEnabled: false })
		expect(labels).toContain('Score')
		expect(labels).not.toContain('Q/min')
	})

	it('keeps the tile with gamification on', async () => {
		expect(await results({})).toContain('Q/min')
	})
})

describe('PoolList: daily goal, daily challenge and XP multiplier (GitHub #36)', () => {
	async function renderPools(props) {
		api.routes['/apps/learning/api/v1/user/state'] = {
			daily_progress: { cards_reviewed_today: 0, daily_goal: 20, goal_reached: false },
			xp_multiplier: 2,
		}
		api.routes['/apps/learning/api/pools'] = []
		return render(PoolList, { userRole: 'student', ...props })
	}

	it('shows no daily goal, bonus XP hint, daily challenge or multiplier with gamification off', async () => {
		await renderPools({ gamificationEnabled: false })
		expect(host.querySelector('.daily-goal-widget')).toBeNull()
		expect(host.querySelector('.daily-challenge-stub')).toBeNull()
		expect(host.querySelector('.xp-multiplier-badge')).toBeNull()
		expect(host.textContent).not.toContain('bonus XP')
	})

	it('keeps them with gamification on', async () => {
		await renderPools({})
		expect(host.querySelector('.daily-goal-widget')).not.toBeNull()
		expect(host.querySelector('.daily-challenge-stub')).not.toBeNull()
		expect(host.querySelector('.xp-multiplier-badge')).not.toBeNull()
		expect(host.textContent).toContain('+10 bonus XP')
	})
})
