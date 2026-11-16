/**
 * GitHub #72 (comment 07:42): an error a tab reported (e.g. a refused stamp) stood at the top of
 * the course page, stayed there on every other tab and could not be closed. What a tab reports is
 * now kept apart from the load error of the course: it can be closed, and it goes away when another
 * tab is opened or the course is loaded again. The load error (course not found, no access) stays:
 * without the course there is nothing else on the page.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { course: null, status: 200 } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/{courseId}') {
				return api.status === 200
					? Promise.resolve({ data: api.course })
					: Promise.reject(Object.assign(new Error('http'), { response: { status: api.status } }))
			}
			if (url === '/apps/learning/api/settings/tools') return Promise.resolve({ data: { enabled_tools: null } })
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: vi.fn(() => ({ uid: 'teacher' })) }))

const { stub, emittingTab } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
	// A tab that reports an error or a sub-tab change on a click, like the real tabs do.
	emittingTab: (name) => ({
		default: {
			name,
			emits: ['error', 'tab-change'],
			template: `<div class="tab-stub" data-tab="${name}">
				<button class="emit-error" @click="$emit('error', 'Die Datei ist zu groß.')">err</button>
				<button class="emit-sub" @click="$emit('tab-change', 'schedule')">sub</button>
			</div>`,
		},
	}),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('../../src/components/CourseTabLernraum.vue', () => emittingTab('CourseTabLernraum'))
vi.mock('../../src/components/CourseTabTeilnehmer.vue', () => emittingTab('CourseTabTeilnehmer'))
vi.mock('../../src/components/CourseTabWettbewerb.vue', () => emittingTab('CourseTabWettbewerb'))
vi.mock('../../src/components/CourseTabKommunikation.vue', () => emittingTab('CourseTabKommunikation'))
vi.mock('../../src/components/CourseTabVerwaltung.vue', () => emittingTab('CourseTabVerwaltung'))
vi.mock('../../src/components/ExamReadiness.vue', () => stub('ExamReadiness'))
vi.mock('../../src/components/CourseSummary.vue', () => stub('CourseSummary'))
vi.mock('../../src/components/CourseRecentSessions.vue', () => stub('CourseRecentSessions'))

import CourseDetail from '../../src/components/CourseDetail.vue'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('course page: a tab error can be closed and does not follow to other tabs (GitHub #72)', () => {
	let host, app, detail

	async function mountDetail() {
		host = document.createElement('div')
		document.body.appendChild(host)
		const detailRef = ref(null)
		app = createApp({ render: () => h(CourseDetail, { ref: detailRef, courseId: 5, userRole: 'instructor', studentViewActive: false }) })
		app.config.globalProperties.t = globalThis.t
		app.config.globalProperties.n = globalThis.n
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		detail = detailRef.value
	}

	async function click(el) {
		expect(el).toBeTruthy()
		el.click()
		await flush()
	}

	const megaButton = (label) => Array.from(host.querySelectorAll('.mega-tab-button')).find((b) => b.textContent.trim() === label)
	const pageError = () => host.querySelector('.detail-error')

	afterEach(() => {
		app?.unmount()
		host?.remove()
		api.status = 200
	})

	function ownCourse() {
		api.course = { id: 5, title: 'Arbeitsschutz', status: 'active', is_instructor: true, mode_config: { training: true, exam: true }, pools: [], members: [] }
	}

	it('the close button removes the error a tab reported', async () => {
		ownCourse()
		await mountDetail()
		await click(megaButton('Verwaltung'))
		await click(host.querySelector('[data-tab="CourseTabVerwaltung"] .emit-error'))
		expect(pageError()?.textContent).toContain('Die Datei ist zu groß.')

		await click(host.querySelector('[data-test="detail-action-error-close"]'))
		expect(pageError()).toBeNull()
	})

	it('opening another course area or another sub-tab removes it', async () => {
		ownCourse()
		await mountDetail()
		await click(megaButton('Verwaltung'))
		await click(host.querySelector('[data-tab="CourseTabVerwaltung"] .emit-error'))
		expect(pageError()).not.toBeNull()
		await click(megaButton('Teilnehmer'))
		expect(pageError()).toBeNull()

		await click(megaButton('Verwaltung'))
		await click(host.querySelector('[data-tab="CourseTabVerwaltung"] .emit-error'))
		expect(pageError()).not.toBeNull()
		await click(host.querySelector('[data-tab="CourseTabVerwaltung"] .emit-sub'))
		expect(pageError()).toBeNull()
	})

	it('a course that cannot be loaded keeps its message, without a close button', async () => {
		api.status = 404
		await mountDetail()
		expect(pageError()?.textContent).toContain('Kurs nicht gefunden.')
		expect(host.querySelector('[data-test="detail-action-error-close"]')).toBeNull()
		expect(detail.error).toBe('Kurs nicht gefunden.')
	})
})
