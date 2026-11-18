/**
 * GitHub #86: a course with open_before_confirm stores when a learner opened a required
 * document. The privacy notice of the mandatory training said "only your completion status and
 * its time are stored" — no longer true there. With the option it names the opens, why they are
 * stored, who sees them and when they are deleted; without it the notice stays as it was.
 * Rendered in English from the shipped catalogue, so the translation is checked as well.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'anna' }) }))
vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

const { setTestLanguage, translate } = await import('./support/l10nCatalog.js')
const TrainingPrivacyNotice = (await import('../../src/components/TrainingPrivacyNotice.vue')).default

let app = null
let host = null
afterEach(() => {
	app?.unmount()
	app = null
	host?.remove()
	host = null
})

async function render(props) {
	setTestLanguage('en')
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({ render: () => h(TrainingPrivacyNotice, props) })
	app.config.globalProperties.t = translate
	app.mount(host)
	await nextTick()
	return host.textContent
}

describe('GitHub #86: the privacy notice names the opens of required documents', () => {
	it('names them in a course that records them', async () => {
		const text = await render({ documentOpens: true })
		expect(host.querySelector('[data-test="training-privacy-opens"]')).not.toBeNull()
		expect(text).not.toContain('Only your completion status')
		expect(text).toContain(translate('learning', 'Geöffnete Pflichtdokumente'))
		expect(translate('learning', 'Geöffnete Pflichtdokumente')).not.toBe('Geöffnete Pflichtdokumente')
	})

	it('stays as it was in a course that does not', async () => {
		await render({})
		expect(host.querySelector('[data-test="training-privacy-opens"]')).toBeNull()
		expect(host.querySelector('[data-test="training-privacy-data-opens"]')).toBeNull()
	})
})
