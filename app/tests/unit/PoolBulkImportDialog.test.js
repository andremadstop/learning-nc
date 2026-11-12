/**
 * GitHub #34, the Pools tab: "Import pools" creates every pool of a JSON file in the format of
 * occ learning:import-pool-json (POST /api/pools/import, PoolImportController).
 *
 * The preview is the server's dry run and nothing else; the import sends exactly the request the
 * preview was made for. The request body is asserted field by field: a key the controller does
 * not read would answer 200 and silently do the wrong thing (a preview that imports, a pool
 * named "Imported pool" instead of after the file).
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
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const { withProps } = vi.hoisted(() => ({
	withProps: (name, props, template) => ({ default: { name, props, template } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => withProps('NcButton', ['variant', 'disabled', 'type'], '<button type="button" :disabled="disabled"><slot /></button>'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => withProps('NcNoteCard', ['type'], '<div class="nc-note" :data-type="type"><slot /></div>'))
vi.mock('../../src/components/AccessibleDialog.vue', () => withProps('AccessibleDialog', ['name', 'size', 'noClose'], '<div class="dialog" :data-no-close="String(!!noClose)"><slot /><div class="actions"><slot name="actions" /></div></div>'))

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'

const URL = '/apps/learning/api/pools/import'
const FILE = JSON.stringify({ pools: [{ name: 'Ports', questions: [{}, {}] }, { name: '', questions: [] }] })

const DRY_RUN = {
	dry_run: true,
	pools: [
		{ index: 1, name: 'Ports', questions_total: 3, importable: 2, errors: ['Q3: needs at least 2 answers — skipped'], warnings: [] },
		{ index: 2, name: '', questions_total: 0, importable: 0, errors: ['Pool #2 missing name — skipped'], warnings: [] },
		{ index: 3, name: 'Empty', questions_total: 0, importable: 0, errors: [], warnings: ["Pool 'Empty' contains no questions"] },
	],
	total_pools: 3,
	total_questions: 3,
	total_importable: 2,
}

const IMPORTED = {
	dry_run: false,
	pools: [
		{ index: 1, id: 71, name: 'Ports', questions_total: 3, imported: 2, errors: ['Q3: needs at least 2 answers — skipped'], warnings: [] },
		{ index: 2, id: null, name: '', questions_total: 0, imported: 0, errors: ['Pool #2 missing name — skipped'], warnings: [] },
		{ index: 3, id: 72, name: 'Empty', questions_total: 0, imported: 0, errors: [], warnings: ["Pool 'Empty' contains no questions"] },
	],
	created_pools: 2,
	imported_questions: 2,
}

let host, app, vm, events

async function flush() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mountDialog() {
	const { default: Dialog } = await import('../../src/components/PoolBulkImportDialog.vue')
	events = []
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render: () => h(Dialog, {
			ref: 'c',
			onClose: () => events.push(['close']),
			onImported: (r) => events.push(['imported', r]),
			onMaybeImported: () => events.push(['maybe-imported']),
		}),
	})
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	app.config.warnHandler = () => {}
	vm = app.mount(host).$refs.c
	await flush()
}

async function paste(text) {
	const area = host.querySelector('textarea')
	area.value = text
	area.dispatchEvent(new Event('input'))
	await flush()
}

function button(selector) {
	return host.querySelector(selector)
}

function buttonByText(text) {
	return [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === text)
}

async function click(el) {
	el.click()
	await flush()
}

function httpError(status, data) {
	return Object.assign(new Error('HTTP ' + status), { response: { status, data } })
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	axios.post.mockReset()
	showSuccess.mockClear()
})

describe('PoolBulkImportDialog: dry run → preview → import (GitHub #34)', () => {
	it('sends nothing before "Check file", then exactly one dry run with the backend field names', async () => {
		await mountDialog()
		expect(button('.pool-import__check').disabled).toBe(true)
		await paste(FILE)
		expect(axios.post).not.toHaveBeenCalled() // no request per keystroke: 5 a minute

		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe(URL)
		expect(body).toEqual({ jsonData: FILE, file_name: '', dry_run: true })
		expect(Object.keys(body).sort()).toEqual(['dry_run', 'file_name', 'jsonData'])
	})

	it('the preview shows only what the dry run answered: names, importable/total, errors and warnings collapsed', async () => {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))

		expect(vm.step).toBe('preview')
		expect(host.querySelector('.pool-import__summary').textContent).toContain('2 of 3 questions can be imported, in 3 pools')
		expect(document.activeElement).toBe(host.querySelector('.pool-import__summary')) // focus follows the step
		const rows = [...host.querySelectorAll('.pool-import__row')]
		expect(rows).toHaveLength(3)
		expect(rows[0].querySelector('.pool-import__name').textContent.trim()).toBe('Ports')
		expect(rows[0].querySelector('.pool-import__count').textContent.replace(/\s+/g, ' ').trim()).toBe('2 / 3')
		// A pool without a name: labelled by its number, never an empty cell.
		expect(rows[1].querySelector('.pool-import__name').textContent.trim()).toBe('Pool #2')

		const errors = rows[0].querySelector('details.pool-import-messages__errors')
		expect(errors).not.toBeNull()
		expect(errors.open).toBe(false) // collapsed until opened
		expect(errors.querySelector('summary').textContent).toBe('Errors: 1')
		expect(errors.querySelector('li').textContent).toBe('Q3: needs at least 2 answers — skipped')
		const warnings = rows[2].querySelector('details.pool-import-messages__warnings')
		expect(warnings.querySelector('summary').textContent).toBe('Warnings: 1')
		expect(warnings.querySelector('li').textContent).toBe("Pool 'Empty' contains no questions")

		// The count on the button is the server's, not a client rule about which pools pass.
		expect(button('.pool-import__import').textContent.trim()).toBe('Import pools (3)')
	})

	it('the import sends the previewed request with dry_run false, shows the result per pool and reloads the list', async () => {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
		axios.post.mockResolvedValueOnce({ status: 201, data: IMPORTED })
		await click(button('.pool-import__import'))

		expect(axios.post).toHaveBeenCalledTimes(2)
		const [url, body] = axios.post.mock.calls[1]
		expect(url).toBe(URL)
		expect(body).toEqual({ jsonData: FILE, file_name: '', dry_run: false })

		expect(vm.step).toBe('result')
		expect(host.querySelector('.pool-import__result .nc-note').dataset.type).toBe('success')
		expect(document.activeElement).toBe(host.querySelector('.pool-import__result-heading'))
		expect(host.querySelector('.pool-import__result').textContent).toContain('Pools created: 2 · questions imported: 2')
		const status = [...host.querySelectorAll('.pool-import__status')].map((td) => td.textContent.trim())
		expect(status).toEqual(['Created', 'Not created', 'Created'])
		expect(host.querySelector('.pool-import__result').textContent).toContain('Pool #2 missing name — skipped')
		expect(events).toContainEqual(['imported', expect.objectContaining({ created_pools: 2, imported_questions: 2 })])
		expect(showSuccess).toHaveBeenCalled()

		await click(buttonByText('Done'))
		expect(events).toContainEqual(['close'])
	})

	it('a chosen file is sent with its name, so a bare question list is named after the file', async () => {
		await mountDialog()
		const file = new File(['[{"text":"Q?","answers":["a","b"]}]'], 'network-ports.json', { type: 'application/json' })
		vm.loadFile(file)
		await new Promise((r) => setTimeout(r, 20))
		await flush()
		expect(host.querySelector('.pool-import__file strong').textContent).toBe('network-ports.json')
		expect(host.querySelector('textarea')).toBeNull() // file or paste, not both

		axios.post.mockResolvedValueOnce({ status: 200, data: { ...DRY_RUN, pools: [DRY_RUN.pools[0]], total_pools: 1 } })
		await click(button('.pool-import__check'))
		expect(axios.post.mock.calls[0][1]).toEqual({
			jsonData: '[{"text":"Q?","answers":["a","b"]}]',
			file_name: 'network-ports.json',
			dry_run: true,
		})
	})

	it('input changed after the preview is not imported unchecked: the preview is dropped', async () => {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
		await click(buttonByText('Back'))
		expect(vm.step).toBe('input')

		await paste(FILE.replace('Ports', 'Ports v2'))
		expect(vm.preview).toBeNull()
		expect(vm.checked).toBeNull()
		expect(button('.pool-import__import')).toBeNull()

		// The changed text has to be checked again, and the new check sends the new text.
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
		expect(axios.post.mock.calls[1][1].jsonData).toContain('Ports v2')
		expect(axios.post.mock.calls[1][1].dry_run).toBe(true)
	})

	it('a real import answered 400 with the per-pool body shows the per-pool reasons, not a generic error', async () => {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
		axios.post.mockRejectedValueOnce(httpError(400, {
			dry_run: false,
			pools: [{ index: 1, id: null, name: 'Ports', questions_total: 3, imported: 0, errors: ["Pool 'Ports' create failed — skipped"], warnings: [] }],
			created_pools: 0,
			imported_questions: 0,
		}))
		await click(button('.pool-import__import'))

		expect(vm.step).toBe('result')
		expect(host.querySelector('.pool-import__result .nc-note').dataset.type).toBe('error')
		expect(host.querySelector('.pool-import__result').textContent).toContain('No pool was created.')
		expect(host.querySelector('.pool-import__result').textContent).toContain("Pool 'Ports' create failed — skipped")
		expect(events.some(([name]) => name === 'imported')).toBe(false)
	})
})

describe('PoolBulkImportDialog: error states are shown, never swallowed', () => {
	async function checkFails(error) {
		await mountDialog()
		await paste(FILE)
		axios.post.mockRejectedValueOnce(error)
		await click(button('.pool-import__check'))
		expect(vm.step).toBe('input')
		expect(vm.checked).toBeNull()
		return host.querySelector('.pool-import__error')?.textContent.trim()
	}

	it('400: the server\'s own (translated) message', async () => {
		expect(await checkFails(httpError(400, { error: 'Maximum 50 pools per import' }))).toBe('Maximum 50 pools per import')
	})

	it('403: not an instructor', async () => {
		expect(await checkFails(httpError(403, { error: 'Only instructors can import pools' }))).toBe('Only instructors can import pools')
	})

	it('403 without a body still says why', async () => {
		expect(await checkFails(httpError(403, ''))).toBe('Only instructors can import pools')
	})

	it('429: the rate limit, not a format problem', async () => {
		expect(await checkFails(httpError(429, { message: 'Rate limit exceeded' }))).toBe('Too many imports in a short time. Please wait a minute and try again.')
	})

	it('413: a proxy or PHP refused the size (HTML body, no error field)', async () => {
		expect(await checkFails(httpError(413, '<html>Request Entity Too Large</html>'))).toBe('The file is too large for the server.')
	})

	it('network failure: no response at all', async () => {
		expect(await checkFails(new Error('Network Error'))).toBe('The server could not be reached. Check your connection and try again.')
	})

	it('a 500 without a message falls back to the format hint', async () => {
		expect(await checkFails(httpError(500, ''))).toBe('Import failed. Check your data format.')
	})

	it('an import that fails without a per-pool body stays on the preview and says why', async () => {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
		axios.post.mockRejectedValueOnce(httpError(429, {}))
		await click(button('.pool-import__import'))
		expect(vm.step).toBe('preview')
		expect(host.querySelector('.pool-import__error').textContent).toContain('Too many imports')
		expect(events.some(([name]) => name === 'imported' || name === 'maybe-imported')).toBe(false)
		// A 429 wrote nothing: trying again later is fine.
		expect(button('.pool-import__import').disabled).toBe(false)
	})
})

describe('PoolBulkImportDialog: a running or unanswered import is never repeated blindly', () => {
	async function preview() {
		await mountDialog()
		await paste(FILE)
		axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
		await click(button('.pool-import__check'))
	}

	it('cannot be closed while the import runs, so its result and the list reload are not lost', async () => {
		await preview()
		let resolve
		axios.post.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		await click(button('.pool-import__import'))

		expect(vm.busy).toBe(true)
		expect(host.querySelector('.dialog').dataset.noClose).toBe('true') // no X, no Escape
		vm.close() // what @closing would call
		await flush()
		expect(events.some(([name]) => name === 'close')).toBe(false)

		resolve({ status: 201, data: IMPORTED })
		await flush()
		expect(events).toContainEqual(['imported', expect.objectContaining({ created_pools: 2 })])
		expect(host.querySelector('.dialog').dataset.noClose).toBe('false')
		vm.close()
		expect(events).toContainEqual(['close'])
	})

	for (const [label, failure] of [
		['no response at all', new Error('Network Error')],
		['a gateway timeout (504, HTML body)', httpError(504, '<html>Gateway Timeout</html>')],
		['a 500 without a per-pool body', httpError(500, '')],
	]) {
		it(`${label}: the result is unknown, the list is reloaded and the same input cannot be imported again`, async () => {
			await preview()
			axios.post.mockRejectedValueOnce(failure)
			await click(button('.pool-import__import'))

			expect(vm.step).toBe('preview')
			expect(host.querySelector('.pool-import__error').textContent).toContain('Check the pool list before importing again')
			expect(events).toContainEqual(['maybe-imported'])
			expect(button('.pool-import__import').disabled).toBe(true)
			await click(button('.pool-import__import'))
			expect(axios.post).toHaveBeenCalledTimes(2) // preview + the one import, no second one

			// Only after "Back" (and a new check) is an import offered again.
			await click(buttonByText('Back'))
			axios.post.mockResolvedValueOnce({ status: 200, data: DRY_RUN })
			await click(button('.pool-import__check'))
			expect(button('.pool-import__import').disabled).toBe(false)
		})
	}
})
