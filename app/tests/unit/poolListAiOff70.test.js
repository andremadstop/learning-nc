/**
 * GitHub #70: with the admin's AI off the pool list still offered "Fragen generieren", which opens
 * the AI pool generator; it only said that no AI provider is set up. The button now follows
 * GET /api/ai/available (the source QuestionList already uses). Its CSV/JSON tab adds nothing
 * beyond "Pools importieren" and the Manage import, which stay.
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

beforeEach(() => {
	api.routes = {
		'/apps/learning/api/pools': { own: [], shared: [] },
		'/apps/learning/api/my-course-pools': { pools: [] },
	}
	api.calls = []
})

afterEach(() => {
	app?.unmount()
	host?.remove()
})


describe('PoolList: "Fragen generieren" follows the AI switch (GitHub #70)', () => {
	it('is hidden for instructors when AI is off, import and create stay', async () => {
		api.routes['/apps/learning/api/ai/available'] = { available: false }
		await render({ userRole: 'instructor' })
		expect(host.querySelector('.pool-list-generate')).toBeNull()
		expect(host.textContent).not.toContain('Fragen generieren')
		expect(host.textContent).toContain('Pools importieren')
		expect(host.textContent).toContain('+ Pool erstellen')
	})

	it('is shown for instructors when AI is available (IT courses as before)', async () => {
		api.routes['/apps/learning/api/ai/available'] = { available: true }
		await render({ userRole: 'instructor' })
		expect(host.querySelector('.pool-list-generate')).not.toBeNull()
		expect(host.textContent).toContain('Fragen generieren')
	})

	it('learners do not ask for the AI state', async () => {
		await render({ userRole: 'student' })
		expect(api.calls.some((u) => String(u).endsWith('/api/ai/available'))).toBe(false)
	})
})
