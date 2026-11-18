/**
 * GitHub #98, the Pools search:
 *  - the number in front of a question hit ("#304") read like a count; it is the question's ID
 *    (the one Pool rules take) and now says so: "Frage-ID 304";
 *  - a question hit opens its pool with the question handed on (focusQuestionId), also for a pool
 *    of a course one leads (search finds those, the lookup only knew own and shared pools).
 * The missing pool name ("in {pool}") was the server's (QuestionSearchPoolName98Test).
 *
 * Harness from poolListAiOff70.test.js.
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
	app = createApp({ render: () => h(PoolList, { ref: instanceRef, onSelectPool: (p, options) => selected.push({ pool: p, options }), ...props }) })
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



describe('PoolList search: question hits (GitHub #98)', () => {
	const SEARCH = '/apps/learning/api/questions/search'
	const hits = [
		{ id: 304, pool_id: 11, pool_name: 'Brandschutz', text: 'Wo hängt der Feuerlöscher?' },
		{ id: 305, pool_id: 12, pool_name: 'Erste Hilfe', text: 'Wo hängt der Verbandskasten?' },
		{ id: 306, pool_id: 13, pool_name: 'Datenschutz', text: 'Wo hängt das Verzeichnis?' },
	]

	async function search(vm) {
		vm.searchTerm = 'hängt'
		vm.showSearchResults = true
		await vm.searchQuestions()
		await flush()
	}

	beforeEach(() => {
		api.routes['/apps/learning/api/pools'] = {
			own: [{ id: 11, name: 'Brandschutz' }],
			shared: [{ id: 12, name: 'Erste Hilfe', is_shared: true, permission: 'read' }],
		}
		api.routes['/apps/learning/api/my-course-pools'] = { pools: [{ id: 13, name: 'Datenschutz', is_shared: true, permission: 'read', courses: [] }] }
		api.routes[SEARCH] = hits
	})

	it('names the question ID as such and the pool by its name', async () => {
		const vm = await render({ userRole: 'instructor' })
		await search(vm)
		const rows = [...host.querySelectorAll('.search-results-section li')].filter((li) => li.querySelector('.result-id'))
		expect(rows).toHaveLength(3)
		expect(rows[0].querySelector('.result-id').textContent.trim()).toBe('Frage-ID 304')
		expect(rows[0].querySelector('.result-subtext').textContent.trim()).toBe('in Brandschutz')
	})

	it.each([
		[0, 11],
		[1, 12],
		[2, 13],
	])('hit %i opens pool %i with the question handed on', async (index, poolId) => {
		const vm = await render({ userRole: 'instructor' })
		await search(vm)
		const rows = [...host.querySelectorAll('.search-results-section li')].filter((li) => li.querySelector('.result-id'))
		rows[index].click()
		await flush()
		expect(selected).toHaveLength(1)
		expect(selected[0].pool.id).toBe(poolId)
		expect(selected[0].options).toEqual({ focusQuestionId: hits[index].id })
	})
})
