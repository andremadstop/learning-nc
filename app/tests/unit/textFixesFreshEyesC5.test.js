/**
 * Fresh-eyes C5 (persona "off"): a pool without a single answer showed "0 % accuracy" on
 * "Completion & certificate", and the compliance report's "Incl. already expired certificates"
 * read like the label of a missing checkbox.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import PoolAccuracyList from '../../src/components/PoolAccuracyList.vue'

globalThis.t = (_app, text) => String(text)

let app = null
let host = null
afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

function mount(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.mount(host)
}

describe('pool accuracy without answers', () => {
	it('says "no answers yet" instead of 0 %', async () => {
		mount(PoolAccuracyList, { pools: [{ pool_id: 1, pool_name: 'Demo', answered: 0, accuracy: 0 }, { pool_id: 2, pool_name: 'Fire', answered: 4, accuracy: 75 }] })
		await nextTick()
		const values = [...host.querySelectorAll('.pool-accuracy-value')].map((el) => el.textContent.trim())
		expect(values).toEqual(['Noch keine Antworten', '75% accuracy'])
	})
})

describe('compliance report hint', () => {
	// "Incl. already expired certificates" read like the label of a missing checkbox
	it('states that expired certificates are part of the report', () => {
		const template = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')
		expect(template).toContain("<p class=\"cert-report-hint\">{{ t('learning', 'Der Bericht enthält auch bereits abgelaufene Zertifikate.') }}</p>")
		expect(template).not.toContain('Inkl. bereits abgelaufener Zertifikate')
	})
})
