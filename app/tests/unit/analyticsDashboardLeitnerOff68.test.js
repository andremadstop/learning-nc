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

	it('numbers the hardest questions in their order (a flex <li> has no list marker)', async () => {
		api.answerStats = answers({ hardest: [
			{ question_id: 3, question_text: 'Welcher Feuerlöscher?', total_answers: 4, wrong_rate: 75 },
			{ question_id: 5, question_text: 'Fluchtweg?', total_answers: 6, wrong_rate: 50 },
		] })
		await mount()
		const ranks = [...host.querySelectorAll('.hardest-item .hardest-rank')].map((el) => el.textContent.trim())
		expect(ranks).toEqual(['1.', '2.'])
	})

	it('writes counts as "label: n", which needs no plural form in any language (5.13.0 review)', async () => {
		api.answerStats = answers({ led_courses: 1, answers: 1, correct: 0, hardest: [
			{ question_id: 3, question_text: 'Welcher Feuerlöscher?', total_answers: 1, wrong_rate: 100 },
		] })
		await mount()
		expect(host.querySelector('.hardest-meta').textContent.trim()).toBe('100 % falsch · Antworten: 1')
		expect(host.querySelector('.answer-stats-note').textContent).toContain('Antworten: 1, davon richtig: 0')
		expect(host.textContent).not.toContain('1 Antworten')
	})

	it('names the viewer\'s own courses as the source of the answers', async () => {
		api.answerStats = answers({ led_courses: 1 })
		await mount()
		expect(host.querySelector('.answer-stats-note').textContent).toContain('aus deinen Kursen mit diesem Pool')
	})

	it('says the viewer leads none of the courses instead of showing "0 answers"', async () => {
		api.answerStats = answers({ led_courses: 0, learners: 0, attempts: 0, answers: 0, correct: 0, accuracy: null, hardest: [] })
		await mount()
		expect(host.querySelector('.answer-stats-none').textContent).toContain('Du leitest keinen der Kurse mit diesem Pool')
		expect(host.textContent).not.toContain('Antworten: 0, davon richtig: 0')
		expect(host.textContent).not.toContain('Lernende mit Antworten')
		expect(host.textContent).not.toContain('Versuche')
		expect(host.querySelector('.hardest-empty')).toBeNull()
		expect(host.querySelector('.card-questions .card-value').textContent).toBe('8')
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

	// Reviewer finding (GitHub #69): the student view is the learner's preview. The course figures
	// of a co-instructor (or an editor) stay out of it, and leaving the view brings them back.
	it('shows the learner\'s boxes instead of the course figures in the student view', async () => {
		api.answerStats = answers({ led_courses: 1 })
		await mount({ studentViewActive: true })
		expect(host.textContent).toContain('Karten in deinen Leitner-Boxen')
		expect(host.querySelector('.answer-stats')).toBeNull()
		expect(host.textContent).not.toContain('Lernende mit Antworten')
		expect(host.textContent).not.toContain('Welcher Feuerlöscher?')
		expect(emitted).toEqual(['leitner'])
	})

	it('reloads the course figures when the student view is left with the tab open', async () => {
		api.answerStats = answers({ led_courses: 1 })
		host = document.createElement('div')
		document.body.appendChild(host)
		emitted = []
		const { ref } = await import('vue')
		const studentView = ref(true)
		app = createApp({ render: () => h(AnalyticsDashboard, { poolId: 7, gamificationEnabled: false, studentViewActive: studentView.value, onBasis: (b) => emitted.push(b) }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.mount(host)
		const settle = async () => {
			for (let i = 0; i < 5; i++) {
				await new Promise((r) => setTimeout(r, 0))
				await nextTick()
			}
		}
		await settle()
		expect(host.querySelector('.answer-stats')).toBeNull()
		studentView.value = false
		await settle()
		expect(host.querySelector('.answer-stats-note').textContent).toContain('aus deinen Kursen mit diesem Pool')
		expect(emitted).toEqual(['leitner', 'answers'])
	})
})
