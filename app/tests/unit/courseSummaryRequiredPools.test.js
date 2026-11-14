/**
 * GitHub #39: the required-pool list of "Completion & certificate" renders through the shared
 * CertPoolResult component. CourseSummary.test.js never renders the template, so this mounts the
 * real component and checks the list looks as before the extraction (name, best result, state,
 * three grid cells per row).
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

describe('CourseSummary required-pool list (GitHub #39)', () => {
	it('shows name, best result and passed / not passed per pool as three grid cells', async () => {
		axios.get.mockImplementation((url) => {
			if (url === '/apps/learning/api/courses/7/pass-status') {
				return Promise.resolve({ data: {
					applicable: true, passed: false, score: 88, threshold: 80, requirement: 'required_pools',
					pools: [
						{ pool_id: 3, title: 'Brandschutz', best_score: 88, passed: true },
						{ pool_id: 4, title: 'Erste Hilfe', best_score: null, passed: false },
					],
				} })
			}
			return Promise.resolve({ data: {} })
		})
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(CourseSummary, {
				courseId: 7, courseName: 'Unterweisung',
				course: { cert_enabled: true, mode_config: { course_summary: false } },
			}),
		})
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()

		const rows = host.querySelectorAll('.zeugnis-pool')
		expect(rows).toHaveLength(2)

		const [passed, open] = rows
		expect(passed.children).toHaveLength(3)
		expect(passed.children[0].textContent).toBe('Brandschutz')
		expect(passed.children[1].textContent.trim()).toBe('Bestes Ergebnis: 88 %')
		expect(passed.children[2].textContent.trim()).toBe('✓ Bestanden')
		expect(passed.children[2].classList.contains('cert-pool-result__state--passed')).toBe(true)
		expect(passed.classList.contains('zeugnis-pool--passed')).toBe(true)

		expect(open.children).toHaveLength(3)
		expect(open.children[1].textContent.trim()).toBe('Noch keine Prüfung')
		expect(open.children[2].textContent.trim()).toBe('Noch nicht bestanden')
		expect(open.children[2].classList.contains('cert-pool-result__state--passed')).toBe(false)
	})
})
