/**
 * #58: an untouched ranking PBQ was submitted as {} and scored 0, while the screen showed the
 *      items in order; the component's own summary counted the displayed order as the answer.
 * #103: the builder's cable template had no cables and no remediation_options, so the PBQ
 *       rendered nothing and could only be submitted empty.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text) => String(text)
})
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button type="button"><slot /></button>' } }))

import PbqRanking from '../../src/components/PbqRanking.vue'
import PbqCable from '../../src/components/PbqCable.vue'
import PbqAuthorTool from '../../src/components/PbqAuthorTool.vue'

let host, app

function mount(component, props) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(component, props) })
	app.config.globalProperties.t = globalThis.t
	app.mount(host)
}

afterEach(() => {
	app?.unmount()
	host?.remove()
})

const rankingConfig = { items: [
	{ id: 'a', text: 'First', correct_position: 1 },
	{ id: 'b', text: 'Second', correct_position: 2 },
	{ id: 'c', text: 'Third', correct_position: 3 },
] }

describe('PbqRanking (audit #58)', () => {
	it('reports the displayed order once on mount, so an untouched ranking is a complete answer', async () => {
		const updates = []
		mount(PbqRanking, { config: rankingConfig, value: {}, onUpdate: (id, pos) => updates.push([id, pos]) })
		await nextTick()

		expect(updates).toEqual([['a', 1], ['b', 2], ['c', 3]])
	})

	it('does not report anything in the result view or when an answer exists', async () => {
		const updates = []
		mount(PbqRanking, { config: rankingConfig, value: {}, disabled: true, onUpdate: (id, pos) => updates.push([id, pos]) })
		await nextTick()
		app.unmount(); host.remove()
		mount(PbqRanking, { config: rankingConfig, value: { a: 2, b: 1, c: 3 }, onUpdate: (id, pos) => updates.push([id, pos]) })
		await nextTick()

		expect(updates).toEqual([])
	})

	it('the result summary counts only submitted positions, as the server does', async () => {
		mount(PbqRanking, { config: rankingConfig, value: {}, disabled: true })
		await nextTick()

		expect(host.textContent).toContain('0/3 an korrekter Position')
	})
})

describe('PBQ builder cable template (audit #103)', () => {
	it('renders a cable with both remediation selects', async () => {
		const data = PbqAuthorTool.data.call({ $props: {}, question: null, initialSubtype: '', initialConfig: null })
		const config = JSON.parse(data.rawConfigForms.cable)

		mount(PbqCable, { config, value: {} })
		await nextTick()

		expect(host.querySelector('svg')).not.toBeNull()
		const selects = host.querySelectorAll('select')
		expect(selects).toHaveLength(2)
		const options = [...selects].map((s) => [...s.options].map((o) => o.value))
		expect(options[0]).toContain(config.questions[0].correct_problem)
		expect(options[1]).toContain(config.questions[0].correct_solution)
	})
})
