import { describe, it, expect, afterEach, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	getLanguage: () => 'de',
}))

import AccessibleDialog from '../../src/components/AccessibleDialog.vue'
import { getFocusableElements } from '../../src/utils/dialogFocus.js'
import AiConsentDialog from '../../src/components/AiConsentDialog.vue'
import consent from '../../data/ai-consent.json'

/**
 * Review finding (Codex): NcDialog's root is NcModal and NcModal's root is a Teleport, so
 * `$refs.dialog.$el` is the teleport anchor, not the dialog. The trap found no focusable element,
 * prevented every Tab and sent focus back to the text body: the buttons were unreachable by
 * keyboard. This mounts the real NcDialog (other suites mock AccessibleDialog).
 */
describe('AccessibleDialog focus trap with the real NcDialog', () => {
	let app, host
	afterEach(() => { app?.unmount(); host?.remove(); document.body.innerHTML = '' })

	async function mountDialog() {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(AccessibleDialog, { name: 'Titel' }, {
				default: () => h('p', 'Text'),
				actions: () => [
					h('button', { id: 'no', type: 'button' }, 'Nein'),
					h('button', { id: 'yes', type: 'button' }, 'Ja'),
				],
			}),
		})
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		await new Promise((r) => setTimeout(r, 50))
		return document.body.querySelector('.accessible-dialog__content')
	}

	function tab(target, shiftKey = false) {
		const event = new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true })
		target.dispatchEvent(event)
		return event
	}

	function focusables() {
		return getFocusableElements(document.body.querySelector('[role="dialog"]'))
	}

	// happy-dom has no default Tab navigation: a Tab the trap does not prevent is one the browser
	// carries out (to the next element of the dialog).
	it('Tab from the text body is left to the browser, which moves on to the buttons', async () => {
		const content = await mountDialog()
		expect(content).not.toBeNull()
		content.focus()
		const event = tab(content)
		expect(event.defaultPrevented).toBe(false)
	})

	it('Tab between the buttons is left to the browser', async () => {
		await mountDialog()
		const no = document.getElementById('no')
		no.focus()
		const event = tab(no)
		expect(event.defaultPrevented).toBe(false)
	})

	it('Tab on the last element wraps to the first focusable element of the dialog', async () => {
		await mountDialog()
		const list = focusables()
		expect(list.map((e) => e.id)).toEqual(expect.arrayContaining(['no', 'yes']))
		const last = list[list.length - 1]
		last.focus()
		const event = tab(last)
		expect(event.defaultPrevented).toBe(true)
		expect(document.activeElement).toBe(list[0])
	})

	it('Shift+Tab on the first element wraps to the last one', async () => {
		await mountDialog()
		const list = focusables()
		list[0].focus()
		const event = tab(list[0], true)
		expect(event.defaultPrevented).toBe(true)
		expect(document.activeElement).toBe(list[list.length - 1])
	})

	it('the consent dialog: Tab from the text reaches "Nein, danke" and "Zustimmen"', async () => {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(AiConsentDialog, { recipient: { name: 'X', region: 'us', processing_local: false, intermediary: false, fingerprint: 'f' }, reason: 'never', locale: 'de' }) })
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		await new Promise((r) => setTimeout(r, 50))
		const labels = focusables().map((e) => e.textContent.trim())
		expect(labels).toEqual(expect.arrayContaining([consent.locales.de.decline_label, consent.locales.de.accept_label]))
		const content = document.body.querySelector('.accessible-dialog__content')
		content.focus()
		expect(tab(content).defaultPrevented).toBe(false)
	})
})
