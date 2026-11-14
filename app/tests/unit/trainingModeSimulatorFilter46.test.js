/**
 * GitHub #46: Training offered "Simulators only" on every pool, also one without a single
 * interactive simulation (question_type 'pbq'). The filter now needs hasSimulatorQuestions, which
 * the pool window (from the question list) and the course (pbq_count per pool) pass in.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn(), celebrateStreak: vi.fn(), isStreakMilestone: vi.fn(() => false) }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => stub('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => stub('QuestionScenario'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => stub('LevelUpOverlay'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

globalThis.t = (app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
globalThis.n = (app, s, p, c) => (c === 1 ? s : p)

import TrainingMode from '../../src/components/TrainingMode.vue'

let app = null
let host = null

async function render(props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(TrainingMode, { poolId: 42, totalQuestions: 12, ...props })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
})

describe('TrainingMode "Simulators only" (GitHub #46)', () => {
	it('is not offered on a pool without simulations', async () => {
		await render({})
		expect(host.textContent).not.toContain('Simulators only')
		expect(host.querySelector('.question-type-filter')).toBeNull()
	})

	it('is offered on a pool with simulations', async () => {
		await render({ hasSimulatorQuestions: true })
		expect(host.textContent).toContain('Simulators only')
	})
})
