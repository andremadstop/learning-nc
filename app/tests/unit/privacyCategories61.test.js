/**
 * GitHub #61: the "Data categories" of the privacy information in the personal settings.
 *
 * Two cards showed an unexplained number badge (the entry count), the cards stood in two ~350px
 * columns, and the whole Art. 13 text was open below the settings. Mounts the real PrivacyInfo.vue:
 * no bare count badge, one column, and with `collapsible` (personal settings) every category is a
 * closed <details> whose <summary> carries the title; the public page keeps them open.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import fs from 'fs'
import { resolve } from 'path'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { setTestLanguage, translate } from './support/l10nCatalog.js'
import PrivacyInfo from '../../src/components/PrivacyInfo.vue'

let host
let app

async function render(props = {}) {
	setTestLanguage('de')
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(PrivacyInfo, { locale: 'de', ...props }) })
	app.config.globalProperties.t = translate
	app.config.warnHandler = () => {}
	app.mount(host)
	await nextTick()
	return host.querySelector('.privacy-info__category-grid')
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

describe('#61 data categories', () => {
	it('no card shows a bare number badge (the ai and external cards hold two entries)', async () => {
		const grid = await render()
		for (const card of grid.querySelectorAll('.privacy-info__category-card')) {
			const header = card.querySelector('.privacy-info__category-header')
			expect(header.textContent.trim()).not.toMatch(/\d+$/)
			expect(header.querySelector('.privacy-info__category-count')).toBeNull()
		}
	})

	it('in the personal settings every category is a closed <details> with its title in <summary>', async () => {
		const grid = await render({ collapsible: true })
		const cards = [...grid.querySelectorAll('.privacy-info__category-card')]
		expect(cards).toHaveLength(7)
		for (const card of cards) {
			expect(card.tagName).toBe('DETAILS')
			expect(card.open).toBe(false)
			const summary = card.querySelector(':scope > summary')
			expect(summary).not.toBeNull()
			expect(summary.querySelector('h5').textContent.trim()).not.toBe('')
		}
		expect(cards[0].querySelector('summary h5').textContent.trim()).toBe('Lerndaten')
		expect(cards[1].querySelector('summary h5').textContent.trim()).toBe('Persönlichkeitsprofil / Telos & KI-Interaktionen')
	})

	it('the public privacy page keeps the categories open', async () => {
		const grid = await render()
		for (const card of grid.querySelectorAll('.privacy-info__category-card')) {
			expect(card.tagName).toBe('SECTION')
		}
	})

	it('the personal settings pass collapsible; the category grid is one column', () => {
		const settings = fs.readFileSync(resolve(__dirname, '../../src/components/PersonalSettings.vue'), 'utf8')
		expect(settings).toMatch(/<PrivacyInfo[^>]*\bcollapsible\b/)
		const sfc = fs.readFileSync(resolve(__dirname, '../../src/components/PrivacyInfo.vue'), 'utf8')
		const rule = [...sfc.matchAll(/\n\.privacy-info__category-grid \{([^}]*)\}/g)].map((m) => m[1]).find((r) => r.includes('grid-template-columns'))
		expect(rule).toMatch(/grid-template-columns:\s*minmax\(0,\s*1fr\);/)
		expect(rule).not.toMatch(/auto-fit/)
	})
	it('the disclosure triangle is decorative: empty CSS alt text, so it is not read aloud', () => {
		const sfc = fs.readFileSync(resolve(__dirname, '../../src/components/PrivacyInfo.vue'), 'utf8')
		const rule = [...sfc.matchAll(/\.privacy-info__category-header::before \{([^}]*)\}/g)].map((m) => m[1]).find((r) => r.includes('content'))
		expect(rule).toMatch(/content:\s*'▸'\s*\/\s*'';/)
	})
})
