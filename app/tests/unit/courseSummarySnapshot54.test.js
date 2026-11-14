/**
 * GitHub #54: a course without certification showed "Save certificate" (the summary snapshot)
 * and a "no certificate" card next to the statistics.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'learner' })) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../../src/components/Certificate.vue', () => ({ default: { name: 'Certificate', template: '<div />' } }))
vi.mock('../../src/components/AiConsentHint.vue', () => ({ default: { name: 'AiConsentHint', template: '<div />' } }))

import axios from '@nextcloud/axios'
import CourseSummary from '../../src/components/CourseSummary.vue'

let app = null
let host = null

afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

async function flush() {
	for (let i = 0; i < 6; i++) {
		await Promise.resolve()
		await nextTick()
	}
}


const summary = {
	mastery: { total_mastered: 2, total_questions: 10, mastery_rate: 20 },
	sessions: { total_sessions: 3, overall_accuracy: 70 },
	xp: { total_xp: 0, current_level: 1 }, badges: [], streak: { current_streak: 0, longest_streak: 0 },
	swarm: { total_contributions: 0, approved_contributions: 0 }, duels: { total_duels: 0, wins: 0, win_rate: 0 },
	trouble_spots: [],
}

async function render(certifies) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/pass-status')) {
			return Promise.resolve({ data: certifies
				? { applicable: true, passed: false, score: null, threshold: 80, requirement: 'any_pool', pools: [] }
				: { applicable: false } })
		}
		if (url.endsWith('/summary/snapshot')) return Promise.reject({ response: { status: 404 } })
		if (url.endsWith('/summary')) return Promise.resolve({ data: summary })
		return Promise.resolve({ data: {} })
	})
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(CourseSummary, {
			courseId: 7, courseName: 'Teamkurs', gamificationEnabled: false,
			course: { cert_enabled: certifies, mode_config: { course_summary: true, leitner: false } },
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
}

describe('CourseSummary snapshot in a course without a certificate (GitHub #54)', () => {
	it('offers no "save certificate" and names no certificate', async () => {
		await render(false)
		expect(host.querySelector('.summary-grid'), 'statistics rendered').not.toBeNull()
		expect(host.querySelector('.save-snapshot-button')).toBeNull()
		expect(host.textContent).not.toContain('Zeugnis')
		expect(host.textContent).not.toContain('Zertifikat')
	})

	it('keeps the snapshot in a certifying course and reports success only after the server', async () => {
		await render(true)
		const button = host.querySelector('.save-snapshot-button')
		expect(button).not.toBeNull()
		axios.post.mockRejectedValueOnce({ response: { status: 500, data: {} } })
		button.click()
		await flush()
		expect(host.textContent).not.toContain('Snapshot gespeichert.')
	})
})
