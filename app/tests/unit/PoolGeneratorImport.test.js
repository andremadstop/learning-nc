/**
 * GitHub #34: the pool generator's "CSV/JSON Import" tab was a dead end — a note ("create a pool
 * first, then use the import function inside the pool view") and a Close button. Without an AI
 * provider (the reporter's self-hosted instance) it was the only tab that could do anything.
 *
 * Now the tab creates the pool and hands it to PoolList, which opens it; App opens an empty
 * pool its owner can edit in Manage, next to the Import button.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => text,
	t: (_app, text) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))
const { api } = vi.hoisted(() => ({ api: { available: false, providerFails: false } }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((url) => {
			if (url.endsWith('/pool-generator/provider-info')) {
				return api.providerFails
					? Promise.reject(new Error('500'))
					: Promise.resolve({ data: { provider: api.available ? 'gemini' : 'disabled', available: api.available } })
			}
			return Promise.resolve({ data: {} })
		}),
		post: vi.fn(() => Promise.resolve({ status: 201, data: { id: 42, name: 'Network+ Ch. 3', user_id: 'u' } })),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { withProps } = vi.hoisted(() => ({
	withProps: (name, props, template) => ({ default: { name, props, template } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => withProps('NcButton', ['variant', 'disabled'], '<button type="button" :disabled="disabled"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => withProps('NcNoteCard', ['type'], '<div class="nc-note" :data-type="type"><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => withProps('NcLoadingIcon', ['size'], '<span />'))
vi.mock('../../src/components/PoolDraftReview.vue', () => withProps('PoolDraftReview', ['questions'], '<div />'))
vi.mock('../../src/components/AiConsentHint.vue', () => withProps('AiConsentHint', [], '<div />'))

import axios from '@nextcloud/axios'

let host, app, vm

async function flush() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountGenerator() {
	const { default: PoolGenerator } = await import('../../src/components/PoolGenerator.vue')
	const events = []
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(PoolGenerator, {
			ref: 'c',
			onClose: () => events.push(['close']),
			onImportInto: (pool) => events.push(['import-into', pool]),
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	vm = app.mount(host).$refs.c
	await flush()
	return events
}

async function type(selector, value) {
	const input = host.querySelector(selector)
	input.value = value
	input.dispatchEvent(new Event('input'))
	await flush()
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	api.available = false
	api.providerFails = false
	axios.post.mockClear()
})

describe('PoolGenerator: the CSV/JSON import tab (GitHub #34)', () => {
	it('creates the pool and hands it on for the import, instead of only saying "create a pool first"', async () => {
		api.available = true
		const events = await mountGenerator()
		host.querySelectorAll('.pg-tab')[2].click()
		await flush()

		const button = host.querySelector('.pg-create-for-import')
		expect(button).not.toBeNull()
		expect(button.disabled).toBe(true) // no name yet

		await type('#pg-import-pool-name', '  Network+ Ch. 3 ')
		expect(button.disabled).toBe(false)
		button.click()
		await flush()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/pools', { name: 'Network+ Ch. 3' })
		expect(events).toContainEqual(['import-into', expect.objectContaining({ id: 42 })])
		expect(events).toContainEqual(['close'])
	})

	it('without an AI provider it opens on the import tab, the one tab that works', async () => {
		api.available = false
		await mountGenerator()
		expect(vm.activeTab).toBe('import')
		expect(host.querySelector('.pg-create-for-import')).not.toBeNull()
	})

	it('with an AI provider it still opens on the text tab', async () => {
		api.available = true
		await mountGenerator()
		expect(vm.activeTab).toBe('text')
	})

	it('a failed pool request keeps the dialog open and says so', async () => {
		const events = await mountGenerator()
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { error: 'Pool name must be 1-200 characters' } } })
		await type('#pg-import-pool-name', 'x')
		host.querySelector('.pg-create-for-import').click()
		await flush()
		expect(events).toEqual([])
		expect(host.querySelector('.pg-error').textContent).toContain('Pool name must be 1-200 characters')
	})
})

describe('PoolList: a pool created for the import opens right away (GitHub #34)', () => {
	it('closes the generator, reloads the list and opens the new pool', async () => {
		vi.doMock('../../src/components/ShareDialog.vue', () => withProps('ShareDialog', [], '<div />'))
		vi.doMock('../../src/components/DailyChallengeCard.vue', () => withProps('DailyChallengeCard', [], '<div />'))
		const { default: PoolList } = await import('../../src/components/PoolList.vue')
		const ctx = { showPoolGenerator: true, loadPools: vi.fn(), selectPool: vi.fn() }
		PoolList.methods.openPoolForImport.call(ctx, { id: 42, name: 'Network+ Ch. 3' })
		expect(ctx.showPoolGenerator).toBe(false)
		expect(ctx.loadPools).toHaveBeenCalled()
		expect(ctx.selectPool).toHaveBeenCalledWith({ id: 42, name: 'Network+ Ch. 3' })
	})
})
