/**
 * Persona sweep C9b (#99): in the archive confirmation, Escape did nothing while "Nicht mehr
 * anzeigen" had the focus. NcModal's Escape hot key ignores every focused <input> (so that typing
 * is not cut off), a checkbox included. AccessibleDialog now closes on Escape from a checkbox or
 * radio button through the dialog's own close button; a text field keeps NcModal's behaviour.
 * The real NcDialog is mounted, as in accessibleDialogDescribedBy99.test.js.
 */
import { describe, it, expect, afterEach, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})

import AccessibleDialog from '../../src/components/AccessibleDialog.vue'

async function waitForTrap() {
	await nextTick()
	const until = Date.now() + 2000
	while (Date.now() < until) {
		if ((window._nc_focus_trap ?? []).length > 0) return
		await new Promise((r) => setTimeout(r, 10))
	}
	throw new Error('NcModal focus trap did not activate within 2 s')
}

function mount(onClosing) {
	const host = document.createElement('div')
	document.body.appendChild(host)
	const app = createApp({
		render: () => h(AccessibleDialog, { name: 'Kurs archivieren?', onClosing }, {
			default: () => h('p', 'Archivieren kennzeichnet den Kurs als abgeschlossen.'),
			actions: () => [
				h('label', [h('input', { type: 'checkbox', id: 'skip' }), 'Nicht mehr anzeigen']),
				h('input', { type: 'text', id: 'note' }),
				h('button', { type: 'button' }, 'Archivieren'),
			],
		}),
	})
	app.config.warnHandler = () => {}
	app.mount(host)
	return { app, host }
}

function escapeOn(el) {
	el.focus()
	const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
	el.dispatchEvent(event)
	return event
}

describe('AccessibleDialog: Escape from a checkbox closes the dialog (#99)', () => {
	let mounted
	afterEach(() => { mounted?.app.unmount(); mounted?.host.remove(); document.body.innerHTML = '' })

	it('Escape on a focused checkbox emits closing', async () => {
		const onClosing = vi.fn()
		mounted = mount(onClosing)
		await waitForTrap()
		const event = escapeOn(document.getElementById('skip'))
		await nextTick()
		expect(event.defaultPrevented).toBe(true)
		expect(onClosing).toHaveBeenCalled()
	})

	it('Escape in a text field is left to NcModal (no forced close)', async () => {
		const onClosing = vi.fn()
		mounted = mount(onClosing)
		await waitForTrap()
		const event = escapeOn(document.getElementById('note'))
		await nextTick()
		expect(event.defaultPrevented).toBe(false)
		expect(onClosing).not.toHaveBeenCalled()
	})
})
