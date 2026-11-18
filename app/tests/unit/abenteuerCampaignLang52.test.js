/**
 * GitHub #52 / WCAG 3.1.2 (Language of Parts): the campaign titles and descriptions the server
 * lists come from German-only campaign files. Outside a German interface they carry lang="de",
 * so a screen reader switches to a German voice instead of reading German with an English one —
 * and the persona sweep can tell this known German content from an untranslated interface string.
 * The translated fallback campaigns and a German interface get no mark.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

const lang = vi.hoisted(() => ({ value: 'en' }))

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str) => str,
	t: (_app, str) => str,
	n: (_app, s, p, c) => (c === 1 ? s : p),
	getLanguage: () => lang.value,
	isRTL: () => false,
}))

globalThis.t = (_app, str) => str
globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)

import axios from '@nextcloud/axios'
import AbenteuerMode from '../../src/components/AbenteuerMode.vue'

const mounted = []

async function mountWith(campaignsResponse) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/story/progress')) return Promise.resolve({ data: [] })
		if (url.endsWith('/story/campaigns')) return campaignsResponse()
		return Promise.resolve({ data: {} })
	})
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp(AbenteuerMode, {})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	app.mount(el)
	mounted.push(() => { app.unmount(); el.remove() })
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
	return el
}

const serverCampaigns = () => Promise.resolve({
	data: { campaigns: [{ campaign_id: 'phishing_friday', title: 'Phishing Friday', description: 'Freitagnachmittag. Klaus hat geklickt.', graph_mode: true }] },
})

afterEach(() => {
	while (mounted.length) mounted.pop()()
	lang.value = 'en'
	vi.clearAllMocks()
})

describe('campaign card language (GitHub #52, WCAG 3.1.2)', () => {
	it('marks the server campaign title and description German in an English interface', async () => {
		const el = await mountWith(serverCampaigns)
		const title = el.querySelector('.ab-campaign-title')
		const desc = el.querySelector('.ab-campaign-desc')
		expect(title, 'campaign card rendered').not.toBeNull()
		expect(title.getAttribute('lang')).toBe('de')
		expect(desc.getAttribute('lang')).toBe('de')
		// The rest of the list stays in the interface language.
		expect(el.querySelector('.ab-title').hasAttribute('lang')).toBe(false)
	})

	it('adds no mark in a German interface', async () => {
		lang.value = 'de'
		const el = await mountWith(serverCampaigns)
		expect(el.querySelector('.ab-campaign-title').hasAttribute('lang')).toBe(false)
		expect(el.querySelector('.ab-campaign-desc').hasAttribute('lang')).toBe(false)
	})

	it('adds no mark to the translated fallback campaigns', async () => {
		const el = await mountWith(() => Promise.reject(new Error('offline')))
		const titles = [...el.querySelectorAll('.ab-campaign-title')]
		expect(titles.length).toBeGreaterThan(0)
		for (const node of titles) expect(node.hasAttribute('lang')).toBe(false)
	})
})
