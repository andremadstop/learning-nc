import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	getLanguage: () => 'de',
}))

import AiConsentDialog from '../../src/components/AiConsentDialog.vue'

/**
 * The onboarding wizard is a fixed overlay at z-index 10000-10001 while NcModal's mask sits at
 * 9998: a consent dialog opened from the wizard would render BEHIND it and the user could not
 * accept. The real NcDialog (not the mock the other suites use) has to carry our layer class on
 * the element that is teleported to <body>.
 */
describe('AiConsentDialog layering', () => {
	let app, host
	afterEach(() => { app?.unmount(); host?.remove(); document.body.innerHTML = '' })

	it('puts the layer class on the modal mask that is teleported to body', async () => {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(AiConsentDialog, { recipient: { name: 'X', region: 'us', processing_local: false, intermediary: false, fingerprint: 'f' }, reason: 'never', locale: 'de' }) })
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
		await nextTick()
		// The modal and its focus trap start on their own schedule. Waiting for the trap (instead of
		// a fixed 50 ms) also keeps it from activating after afterEach has emptied the page.
		await vi.waitFor(() => expect((window._nc_focus_trap ?? []).length).toBeGreaterThan(0), { timeout: 5000 })
		expect(document.body.querySelector('.modal-mask.ai-consent-modal')).not.toBeNull()
	})
})
