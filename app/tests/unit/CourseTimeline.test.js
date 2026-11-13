/**
 * GitHub #35: the learners' schedule timeline.
 *
 * Every student got a 403 for the schedule and the timeline swallowed it as "no schedule". Now a
 * failed read says so (except a 403, which means "not a member" and stays quiet), and a schedule
 * entry shows progress when its chapter_ref is a pool's chapter key.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
})

const { api } = vi.hoisted(() => ({ api: { schedule: null, progress: null } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url === '/apps/learning/api/courses/{courseId}/schedule') return api.schedule()
			if (url === '/apps/learning/api/courses/{courseId}/my-progress') return api.progress()
			return Promise.resolve({ data: {} })
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))

import axios from '@nextcloud/axios'
import CourseTimeline from '../../src/components/CourseTimeline.vue'
import { buildChapterProgressMap } from '../../src/utils/chapterProgress.js'

function httpError(status) {
	const error = new Error('HTTP ' + status)
	error.response = { status }
	return Promise.reject(error)
}

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('CourseTimeline (GitHub #35)', () => {
	let host, app

	async function mountTimeline() {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CourseTimeline, { courseId: 5 }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
	}

	beforeEach(() => {
		vi.clearAllMocks()
		api.progress = () => Promise.resolve({ data: { pools: [] } })
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('shows the schedule a student may now read', async () => {
		api.schedule = () => Promise.resolve({ data: { schedule: [{ id: 1, chapter_ref: 'chapter-01', chapter_title: 'Basics', target_date: '2026-12-01' }] } })
		await mountTimeline()

		expect(host.querySelector('.station-title').textContent).toBe('Basics')
		expect(host.querySelector('[data-test="timeline-error"]')).toBeNull()
	})

	it('a server error is shown as an error with a retry, not as "no schedule"', async () => {
		api.schedule = () => httpError(500)
		await mountTimeline()

		expect(host.querySelector('[data-test="timeline-error"]')).toBeTruthy()
		expect(host.textContent).not.toContain('Kein Zeitplan vorhanden.')

		api.schedule = () => Promise.resolve({ data: { schedule: [{ id: 1, chapter_ref: 'a', chapter_title: 'After retry' }] } })
		host.querySelector('.timeline-retry').click()
		await flush()
		expect(host.querySelector('[data-test="timeline-error"]')).toBeNull()
		expect(host.querySelector('.station-title').textContent).toBe('After retry')
	})

	it('a 403 (no longer a member) stays quiet', async () => {
		api.schedule = () => httpError(403)
		await mountTimeline()

		expect(host.querySelector('[data-test="timeline-error"]')).toBeNull()
		expect(host.textContent).toContain('Kein Zeitplan vorhanden.')
	})

	it('a schedule entry named after a pool chapter key shows that pool\'s progress', async () => {
		api.schedule = () => Promise.resolve({ data: { schedule: [{ id: 1, chapter_ref: 'chapter-03', chapter_title: 'Switching' }] } })
		api.progress = () => Promise.resolve({ data: { pools: [{ pool_id: 11, pool_name: 'Switching pool', chapter_key: 'chapter-03', mastered: 3, total_questions: 4 }] } })
		await mountTimeline()

		expect(host.querySelector('.progress-pct').textContent).toBe('75%')
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/courses/{courseId}/my-progress')
	})
})

describe('buildChapterProgressMap', () => {
	it('matches by chapter key and by pool name, and adds up pools that share a key', () => {
		const map = buildChapterProgressMap([
			{ pool_name: 'Part A', chapter_key: 'ch-1', mastered: 2, total_questions: 10 },
			{ pool_name: 'Part B', chapter_key: 'ch-1', mastered: 8, total_questions: 10 },
			{ pool_name: 'Loose pool', chapter_key: null, mastered: 1, total_questions: 4 },
		])

		expect(map['ch-1']).toBe(50)
		expect(map['Part A']).toBe(20)
		expect(map['Loose pool']).toBe(25)
	})

	it('leaves out pools without questions', () => {
		expect(buildChapterProgressMap([{ pool_name: 'Empty', chapter_key: 'x', mastered: 0, total_questions: 0 }])).toEqual({})
	})
})
