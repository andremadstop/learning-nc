/**
 * GitHub #69: an admin or co-instructor whose course pools belong to a colleague saw "My Pools
 * (0) — No pools yet" and could not reach the pools to fix a question. The list has a tab
 * "Pools meiner Kurse" (GET /api/my-course-pools) that names the courses and opens the pool with
 * the permission the server named, so the pool window offers Manage only to editors.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.split(`{${k}}`).join(String(v)), String(text))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

const { api } = vi.hoisted(() => ({ api: { routes: {}, calls: [] } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			api.calls.push(url)
			return Promise.resolve({ data: api.routes[url] ?? {} })
		}),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { stub, slotStub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
	slotStub: (name, cls) => ({ default: { name, template: `<div class="${cls}"><slot /></div>` } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => slotStub('NcButton', 'nc-button'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => slotStub('NcNoteCard', 'nc-note'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty">{{ name }}</div>' } }))
vi.mock('@nextcloud/vue/components/NcActions', () => stub('NcActions'))
vi.mock('@nextcloud/vue/components/NcActionButton', () => stub('NcActionButton'))
vi.mock('../../src/components/ShareDialog.vue', () => stub('ShareDialog'))
vi.mock('../../src/components/DailyChallengeCard.vue', () => stub('DailyChallengeCard'))
vi.mock('../../src/components/AccessibleDialog.vue', () => stub('AccessibleDialog'))
vi.mock('../../src/components/PoolGenerator.vue', () => stub('PoolGenerator'))
vi.mock('../../src/components/PoolBulkImportDialog.vue', () => stub('PoolBulkImportDialog'))

import PoolList from '../../src/components/PoolList.vue'

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

let host, app, selected

async function render(props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	selected = []
	const instanceRef = ref(null)
	app = createApp({ render: () => h(PoolList, { ref: instanceRef, onSelectPool: (p) => selected.push(p), ...props }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(host)
	await flush()
	return instanceRef.value
}

const COURSE_POOLS = [
	{ id: 4, name: 'Fire safety', user_id: 'teacher', permission: 'read', is_shared: true, courses: [{ id: 1, title: 'Annual safety training' }, { id: 2, title: 'Refresher' }] },
	{ id: 5, name: 'Data protection', user_id: 'teacher', permission: 'edit', is_shared: true, courses: [{ id: 1, title: 'Annual safety training' }] },
]

beforeEach(() => {
	api.routes = {
		'/apps/learning/api/pools': { own: [], shared: [] },
		'/apps/learning/api/my-course-pools': { pools: COURSE_POOLS },
	}
	api.calls = []
})

afterEach(() => {
	app?.unmount()
	host?.remove()
})

const tabLabels = () => [...host.querySelectorAll('[role="tab"]')].map((b) => b.textContent.trim())

describe('PoolList: Pools meiner Kurse (GitHub #69)', () => {
	it('opens on the course pools when there are no own pools, naming the courses', async () => {
		await render({ userRole: 'student' })
		expect(tabLabels()).toContain('Pools meiner Kurse (2)')
		expect(host.querySelector('.course-pools-tab').getAttribute('aria-selected')).toBe('true')
		expect(host.textContent).not.toContain('No pools yet')
		const cards = [...host.querySelectorAll('.course-pool-card')]
		expect(cards.map((c) => c.querySelector('h4').textContent.trim())).toEqual(['Fire safety', 'Data protection'])
		expect(cards[0].querySelector('.course-pool-courses').textContent).toContain('Annual safety training, Refresher')
		expect(cards[0].querySelector('.permission-badge').textContent.trim()).toBe('View only')
		expect(cards[1].querySelector('.permission-badge').textContent.trim()).toBe('Can edit')
	})

	it('opens a pool with the permission the server named', async () => {
		await render({ userRole: 'instructor' })
		host.querySelector('.course-pool-card').click()
		expect(selected).toHaveLength(1)
		expect(selected[0]).toMatchObject({ id: 4, is_shared: true, permission: 'read' })
	})

	it('keeps "My Pools" first when the user has pools of their own', async () => {
		api.routes['/apps/learning/api/pools'] = { own: [{ id: 9, name: 'Mine', created_at: '2026-01-01' }], shared: [] }
		await render({ userRole: 'instructor' })
		expect(tabLabels()).toContain('Pools meiner Kurse (2)')
		expect(host.querySelector('.course-pools-tab').getAttribute('aria-selected')).toBe('false')
		expect(host.querySelectorAll('.course-pool-card')).toHaveLength(0)
	})

	it('has no tab without course pools, and none in the student view', async () => {
		api.routes['/apps/learning/api/my-course-pools'] = { pools: [] }
		await render({ userRole: 'instructor' })
		expect(tabLabels().some((l) => l.startsWith('Pools meiner Kurse'))).toBe(false)
		app.unmount(); host.remove()

		api.routes['/apps/learning/api/my-course-pools'] = { pools: COURSE_POOLS }
		api.calls = []
		await render({ userRole: 'student', studentViewActive: true })
		expect(tabLabels().some((l) => l.startsWith('Pools meiner Kurse'))).toBe(false)
		expect(api.calls).not.toContain('/apps/learning/api/my-course-pools')
	})
})
