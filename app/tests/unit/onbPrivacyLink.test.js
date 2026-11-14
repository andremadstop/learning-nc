/**
 * Audit #115: the privacy link in the onboarding was a hard-coded "/settings/user/privacy". On a
 * Nextcloud under a subpath (/nextcloud) or without pretty URLs (/index.php) it opened a 404.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => '/nextcloud/index.php' + url) }))
vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text, translate: (app, text) => text }))

import OnbPrivacy from '../../src/components/onboarding/OnbPrivacy.vue'

let host, app

afterEach(() => {
	app?.unmount()
	host?.remove()
})

describe('OnbPrivacy', () => {
	it('builds the privacy settings link with the instance prefix', () => {
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(OnbPrivacy) })
		app.mount(host)

		const link = host.querySelector('a.onb-privacy__link')
		expect(link.getAttribute('href')).toBe('/nextcloud/index.php/settings/user/privacy')
	})
})
