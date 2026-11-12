/**
 * GitHub #34, the dialog side: pasted JSON went to the CSV parser, the preview counted answers
 * the server then refused, parse errors left the preview silently empty, and a 429 (rate
 * limit) was reported as a format error. Plus the dead end of an empty pool in Training.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars) => String(text).replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
	t: (_app, text) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { withProps } = vi.hoisted(() => ({
	withProps: (name, props, template) => ({ default: { name, props, template } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => withProps('NcButton', ['variant', 'disabled'], '<button type="button" :disabled="disabled"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => withProps('NcNoteCard', ['type'], '<div class="nc-note" :data-type="type"><slot /></div>'))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => withProps('NcEmptyContent', ['name', 'description'], '<div class="nc-empty">{{ description }}</div>'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => withProps('NcProgressBar', ['value'], '<div />'))
vi.mock('../../src/components/AccessibleDialog.vue', () => withProps('AccessibleDialog', ['name', 'size'], '<div class="dialog"><slot /><slot name="actions" /></div>'))

import axios from '@nextcloud/axios'

let host, app, vm

async function mount(Component, props = {}, listeners = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(Component, { ...props, ...listeners, ref: 'c' }) })
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	const root = app.mount(host)
	vm = root.$refs.c
	await nextTick()
	return host
}

async function flush() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	axios.post.mockReset()
})

describe('ImportDialog (GitHub #34)', () => {
	async function dialog() {
		const { default: ImportDialog } = await import('../../src/components/ImportDialog.vue')
		await mount(ImportDialog, { poolId: 3 })
	}

	async function paste(text) {
		const area = host.querySelector('textarea')
		area.value = text
		area.dispatchEvent(new Event('input'))
		await flush()
	}

	it('pasted text that starts like JSON switches the dialog to JSON', async () => {
		await dialog()
		expect(vm.format).toBe('csv')
		await paste('  [{"question":"Capital of France?","options":["Berlin","Paris","Rome"],"answer":1}]')
		expect(vm.format).toBe('json')
		expect(host.querySelector('.preview-item').textContent).toContain('3 answers')
	})

	it('the documented pools wrapper previews its questions instead of an empty preview', async () => {
		await dialog()
		await paste(JSON.stringify({ _meta: { source: 'x' }, pools: [{ name: 'P', questions: [{ text: 'Q?', answers: [{ text: 'a', is_correct: true }, { text: 'b' }] }] }] }))
		expect(host.querySelectorAll('.preview-item')).toHaveLength(1)
		expect(host.querySelector('.preview-summary').textContent).toContain('1 of 1 items can be imported')
	})

	it('an item the server refuses shows the reason, never an answer count', async () => {
		await dialog()
		await paste(JSON.stringify([{ text: 'Only one answer', answers: ['a'] }]))
		const item = host.querySelector('.preview-item')
		expect(item.textContent).toContain('Item 1: Need at least 2 answers')
		expect(item.textContent).not.toMatch(/answers\)/)
		expect(host.querySelector('.preview-summary').textContent).toContain('0 of 1 items can be imported')
	})

	it('broken JSON is reported in the preview, not left empty', async () => {
		await dialog()
		host.querySelectorAll('.tab-btn')[1].click()
		await flush()
		await paste('[{"text": "x",]')
		const note = host.querySelector('.preview-error')
		expect(note).not.toBeNull()
		expect(note.textContent).toContain('The JSON cannot be read')
	})

	it('pasted CSV whose first field starts with [ or { stays CSV and can be imported as CSV', async () => {
		await dialog()
		await paste('[Network] Which port does SSH use?,21,22,23,1\n{Linux} Which command lists files?,ls,cd,pwd,1\n')
		expect(vm.format).toBe('csv')
		const items = host.querySelectorAll('.preview-item')
		expect(items).toHaveLength(2)
		expect(host.querySelector('.preview-summary').textContent).toContain('2 of 2 items can be imported')
		// editing keeps it CSV, it does not flip on the next change
		await paste('[Network] Which port does SSH use?,21,22,23,1\n')
		expect(vm.format).toBe('csv')
	})

	it('broken JSON pasted on the CSV tab does not switch the format', async () => {
		await dialog()
		await paste('[{"text": "x",]')
		expect(vm.format).toBe('csv')
	})

	it('after the user picks CSV by hand, pasted JSON no longer switches the tab', async () => {
		await dialog()
		host.querySelectorAll('.tab-btn')[1].click()
		await flush()
		host.querySelectorAll('.tab-btn')[0].click()
		await flush()
		expect(vm.format).toBe('csv')
		await paste('[{"text":"Q?","answers":["a","b"],"answer":0}]')
		expect(vm.format).toBe('csv')
	})

	it('broken JSON on the CSV tab shows a hint with the parse error and points to the JSON tab', async () => {
		await dialog()
		await paste('  [{"text": "x",]')
		expect(vm.format).toBe('csv')
		const hint = host.querySelector('.json-hint')
		expect(hint).not.toBeNull()
		expect(hint.textContent).toContain('looks like JSON')
		expect(hint.textContent).toContain('JSON tab')
		let parseMessage = ''
		try { JSON.parse('[{"text": "x",]') } catch (e) { parseMessage = e.message }
		expect(hint.textContent).toContain(parseMessage)
	})

	it('a CSV whose first field starts with [ shows no JSON hint', async () => {
		await dialog()
		await paste('[Network] Which port does SSH use?,21,22,23,1\n')
		expect(host.querySelector('.json-hint')).toBeNull()
	})

	it('the JSON hint is not shown on the JSON tab', async () => {
		await dialog()
		host.querySelectorAll('.tab-btn')[1].click()
		await flush()
		await paste('[{"text": "x",]')
		expect(host.querySelector('.json-hint')).toBeNull()
	})

	it('clicking the tab that is already active keeps the automatic switch to JSON', async () => {
		await dialog()
		host.querySelectorAll('.tab-btn')[0].click()
		await flush()
		await paste('[{"text":"Q?","answers":["a","b"],"answer":0}]')
		expect(vm.format).toBe('json')
	})

	it('Clear turns the automatic switch to JSON back on', async () => {
		await dialog()
		host.querySelectorAll('.tab-btn')[1].click()
		await flush()
		host.querySelectorAll('.tab-btn')[0].click()
		await flush()
		vm.clearFile()
		await flush()
		await paste('[{"text":"Q?","answers":["a","b"],"answer":0}]')
		expect(vm.format).toBe('json')
	})

	it('a 429 says "wait a minute", not "check your data format"', async () => {
		await dialog()
		await paste('[{"text":"Q?","answers":["a","b"],"answer":0}]')
		axios.post.mockRejectedValueOnce({ response: { status: 429, data: {} } })
		await vm.doImport()
		await flush()
		expect(host.textContent).toContain('Too many imports in a short time')
		expect(host.textContent).not.toContain('Check your data format')
	})

	it('a 400 with only "error" shows that message', async () => {
		await dialog()
		await paste('{"pools":[]}')
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { error: 'The file contains no pools — expected a list of questions' } } })
		await vm.doImport()
		await flush()
		expect(host.textContent).toContain('The file contains no pools')
	})

	it('a file with several pools points to "Import pools" in the Pools tab, before and after the import attempt', async () => {
		await dialog()
		const q = { text: 'Q?', answers: [{ text: 'a', is_correct: true }, { text: 'b' }] }
		await paste(JSON.stringify({ pools: [{ name: 'One', questions: [q] }, { name: 'Two', questions: [q] }, { name: 'Three', questions: [q] }] }))
		expect(vm.poolFileCount).toBe(3)
		expect(host.querySelector('.preview-error').textContent).toContain('This file contains 3 pools')
		expect(host.querySelector('.preview-error').textContent).toContain('"Import pools"')

		// The server only says this dialog takes one pool; the dialog names where all of them go.
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { error: 'The file contains 3 pools, but this dialog imports into one pool. Import them one at a time, or create all pools at once with "occ learning:import-pool-json"' } } })
		await vm.doImport()
		await flush()
		expect(vm.result.errors).toHaveLength(1)
		expect(vm.result.errors[0]).toContain('in the Pools tab with "Import pools"')
	})

	it('a single pool in the pools wrapper gets no several-pools hint', async () => {
		await dialog()
		const q = { text: 'Q?', answers: [{ text: 'a', is_correct: true }, { text: 'b' }] }
		await paste(JSON.stringify({ pools: [{ name: 'One', questions: [q] }] }))
		expect(vm.poolFileCount).toBe(0)
		expect(host.querySelector('.preview-error')).toBeNull()
		expect(host.textContent).not.toContain('This file contains')
	})

	it('a 400 with an errors list shows the list', async () => {
		await dialog()
		await paste('[{"text":"Q?","answers":["a"]}]')
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { imported: 0, errors: ['Item 1: Need at least 2 answers'], total_items: 1 } } })
		await vm.doImport()
		await flush()
		expect(vm.result.errors).toEqual(['Item 1: Need at least 2 answers'])
		expect(host.textContent).toContain('Item 1: Need at least 2 answers')
	})

	it('server messages arrive translated (IL10N) and are shown as they are', async () => {
		await dialog()
		await paste('[{"text":"Q?","answers":["a"]}]')
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { imported: 0, errors: ['Element 1: Mindestens 2 Antworten nötig'] } } })
		await vm.doImport()
		await flush()
		expect(vm.result.errors).toEqual(['Element 1: Mindestens 2 Antworten nötig'])
	})

	it('a CSV question in quotes with line breaks and commas previews as one question', async () => {
		await dialog()
		await paste('"Look at this:\nPING 10.0.0.1, 100% loss\nWhat is wrong?",Cable,"DNS, probably",Nothing,1,Because.\n')
		expect(vm.format).toBe('csv')
		const items = host.querySelectorAll('.preview-item')
		expect(items).toHaveLength(1)
		expect(items[0].textContent).toContain('3 answers')
		expect(host.querySelector('.preview-summary').textContent).toContain('1 of 1 items can be imported')
	})

	it('a CSV line the server refuses shows the reason in the preview', async () => {
		await dialog()
		await paste('Only a question,a\n')
		expect(host.querySelector('.preview-item').textContent).toContain('Line 1: Not enough fields')
		expect(host.querySelector('.preview-summary').textContent).toContain('0 of 1 items can be imported')
	})
})

describe('TrainingMode: empty pool (GitHub #34)', () => {
	async function training(canManage) {
		const { default: TrainingMode } = await import('../../src/components/TrainingMode.vue')
		const events = []
		await mount(TrainingMode, { poolId: 1, totalQuestions: 0, canManage }, { onManage: () => events.push('manage') })
		return events
	}

	it('offers "Add questions" to whoever can manage the pool, and asks App to switch', async () => {
		const events = await training(true)
		const button = host.querySelector('.training-add-questions')
		expect(button).not.toBeNull()
		button.click()
		expect(events).toEqual(['manage'])
	})

	it('learners and read-only shares get no such button', async () => {
		await training(false)
		expect(host.querySelector('.training-add-questions')).toBeNull()
	})
})
