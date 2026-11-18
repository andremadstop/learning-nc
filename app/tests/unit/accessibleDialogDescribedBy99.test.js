/**
 * GitHub #99 review: the archive confirmation passed aria-describedby="archive-confirm-text" to
 * AccessibleDialog. It fell through $attrs to NcDialog and NcModal, and NcModal merges its own
 * "modal-description-<id>" over $attrs, so the dialog element never pointed at the explanation.
 * The same held for the AI consent dialog (:aria-describedby="bodyId"). The CourseList suite
 * stubs AccessibleDialog and could not see this; these tests mount the real NcDialog.
 */
import { describe, it, expect, afterEach, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	getLanguage: () => 'de',
}))

import AccessibleDialog from '../../src/components/AccessibleDialog.vue'
import AiConsentDialog from '../../src/components/AiConsentDialog.vue'

// see accessibleDialogFocusTrap.test.js: NcModal activates its focus trap asynchronously
async function waitForTrap() {
	await nextTick()
	const until = Date.now() + 2000
	while (Date.now() < until) {
		if ((window._nc_focus_trap ?? []).length > 0) return
		await new Promise((r) => setTimeout(r, 10))
	}
	throw new Error('NcModal focus trap did not activate within 2 s')
}

const dialogElement = () => document.body.querySelector('[role="dialog"]')

describe('AccessibleDialog names its description on the real dialog element (GitHub #99)', () => {
	let app, host
	afterEach(() => { app?.unmount(); host?.remove(); document.body.innerHTML = '' })

	it('the role="dialog" element points at the given text, not at NcModal\'s own description', async () => {
		const name = ref('Kurs archivieren?')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({
			render: () => h(AccessibleDialog, { name: name.value, 'aria-describedby': 'archive-confirm-text' }, {
				default: () => h('div', { id: 'archive-confirm-text' }, 'Lernende bekommen nicht mehr: …'),
				actions: () => [h('button', { type: 'button' }, 'Archivieren')],
			}),
		})
		app.config.warnHandler = () => {}
		app.mount(host)
		await waitForTrap()

		const dialog = dialogElement()
		expect(dialog).not.toBeNull()
		expect(dialog.getAttribute('aria-describedby')).toBe('archive-confirm-text')
		expect(document.getElementById(dialog.getAttribute('aria-describedby'))?.textContent).toContain('Lernende bekommen nicht mehr')

		// a re-render of NcModal (new name) does not write its own value back
		name.value = 'Kurs „B“ archivieren?'
		await nextTick()
		await nextTick()
		expect(dialogElement().getAttribute('aria-describedby')).toBe('archive-confirm-text')
	})

	it('without the attribute, NcModal keeps its own description', async () => {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(AccessibleDialog, { name: 'Titel' }, { default: () => h('p', 'Text') }) })
		app.config.warnHandler = () => {}
		app.mount(host)
		await waitForTrap()
		expect(dialogElement().getAttribute('aria-describedby')).toMatch(/^modal-description-/)
	})

	it('the AI consent dialog is described by its consent text', async () => {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(AiConsentDialog, { recipient: { name: 'X', region: 'us', processing_local: false, intermediary: false, fingerprint: 'f' }, reason: 'never', locale: 'de' }) })
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
		await waitForTrap()
		const described = dialogElement().getAttribute('aria-describedby')
		expect(document.getElementById(described)?.classList.contains('ai-consent')).toBe(true)
	})
})
