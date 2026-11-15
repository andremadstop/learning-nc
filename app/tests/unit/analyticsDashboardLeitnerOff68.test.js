/**
 * GitHub #68: the pool window's Statistics tab showed the viewer's own Leitner boxes — empty for
 * a pool that hangs only in courses without Leitner ("0 questions" next to a pool of eight). With
 * answer statistics from GET /api/pools/{poolId}/answer-stats and leitner_in_use=false it shows
 * attempts, hit rate and the hardest questions instead; otherwise the boxes stay.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { answerStats: null, answerStatsFails: false } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/api/leitner/stats')) {
				return Promise.resolve({ data: { total: 0, mastered: 0, box_1: 0, accuracy: 0, mastery_percentage: 0 } })
			}
			if (url.endsWith('/answer-stats')) {
				return api.answerStatsFails
					? Promise.reject(Object.assign(new Error('403'), { response: { status: 403 } }))
					: Promise.resolve({ data: api.answerStats })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button type="button"><slot /></button>' } }))

import AnalyticsDashboard from '../../src/components/AnalyticsDashboard.vue'

let host, app, emitted

const answers = (overrides = {}) => ({
	leitner_in_use: false,
	question_count: 8,
	learners: 2,
	attempts: 4,
	answers: 20,
	correct: 15,
	accuracy: 75,
	hardest: [{ question_id: 3, question_text: 'Welcher Feuerlöscher?', total_answers: 4, wrong_rate: 75 }],
	...overrides,
})

async function mount(props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	emitted = []
	app = createApp({ render: () => h(AnalyticsDashboard, { poolId: 7, gamificationEnabled: false, onBasis: (b) => emitted.push(b), ...props }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.mount(host)
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
	api.answerStats = null
	api.answerStatsFails = false
})

afterEach(() => {
	app?.unmount()
	host?.remove()
	vi.restoreAllMocks()
})

describe('Pool statistics without Leitner (GitHub #68)', () => {
	it('shows questions, attempts, hit rate and the hardest questions instead of the boxes', async () => {
		api.answerStats = answers()
		await mount()
		expect(host.textContent).toContain('Fragen im Pool')
		expect(host.querySelector('.card-questions .card-value').textContent).toBe('8')
		expect(host.textContent).toContain('Versuche')
		expect(host.querySelector('.card-accuracy .card-value').textContent).toBe('75%')
		expect(host.querySelector('.hardest-item').textContent).toContain('Welcher Feuerlöscher?')
		expect(host.querySelector('.hardest-item').textContent).toContain('75 % falsch')
		expect(host.textContent).not.toContain('Leitner Box Distribution')
		expect(host.textContent).not.toContain('Karten in deinen Leitner-Boxen')
		expect(host.textContent).not.toContain('Due Today')
		expect(host.querySelector('.mastery-ring')).toBeNull()
		expect(emitted).toEqual(['answers'])
	})

	it('says so when no question was answered wrong often enough yet', async () => {
		api.answerStats = answers({ hardest: [], answers: 0, correct: 0, accuracy: null })
		await mount()
		expect(host.querySelector('.hardest-empty')).not.toBeNull()
		expect(host.querySelector('.card-accuracy .card-value').textContent).toBe('–')
	})

	it('keeps the box distribution when a course holding the pool uses Leitner', async () => {
		api.answerStats = answers({ leitner_in_use: true })
		await mount()
		expect(host.textContent).toContain('Leitner Box Distribution')
		expect(host.textContent).toContain('Karten in deinen Leitner-Boxen')
		expect(host.querySelector('.answer-stats')).toBeNull()
		expect(emitted).toEqual(['leitner'])
	})

	it('keeps the box distribution for a viewer who may not read the answers (403)', async () => {
		api.answerStatsFails = true
		await mount()
		expect(host.textContent).toContain('Leitner Box Distribution')
		expect(host.querySelector('.error')).toBeNull()
	})

	it('names where the back button leads instead of "Back to Mode Selector"', async () => {
		api.answerStats = answers()
		await mount({ backLabel: 'Verwalten' })
		const back = host.querySelector('.header button')
		expect(back.textContent).toContain('Zurück zu „Verwalten“')
		expect(host.textContent).not.toContain('Mode Selector')
	})
})
