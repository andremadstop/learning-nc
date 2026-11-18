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

/**
 * GitHub #86 (review): the notice remembered "seen" under one key for every content. A learner
 * who had seen the shorter notice (5.14/5.15) got the one naming the opens folded to its heading:
 * the new kind of data was never shown to them. The longer notice has its own key now.
 */
describe('GitHub #86: a notice with the opens is shown expanded once to whoever saw the shorter one', () => {
	const OLD_KEY = 'learning:training-privacy-seen:anna'

	function details() {
		return host.querySelector('#training-privacy-details').style.display
	}

	function stubObserver() {
		const observers = []
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback) {
				this.callback = callback
				observers.push(this)
			}

			observe(el) { this.el = el }
			disconnect() {}
		})
		return observers
	}

	afterEach(() => {
		window.localStorage.clear()
		vi.unstubAllGlobals()
	})

	it('expands for a learner who only saw the notice without the opens', async () => {
		window.localStorage.setItem(OLD_KEY, '1')
		await render({ collapsible: true, documentOpens: true })
		expect(details()).not.toBe('none')
		expect(host.querySelector('[data-test="training-privacy-toggle"]').getAttribute('aria-expanded')).toBe('true')
	})

	it('a course without the option folds as before', async () => {
		window.localStorage.setItem(OLD_KEY, '1')
		await render({ collapsible: true })
		expect(details()).toBe('none')
	})

	it('folds once the notice with the opens was on screen, and that covers the shorter one', async () => {
		const observers = stubObserver()
		await render({ collapsible: true, documentOpens: true })
		observers[0].callback([{ isIntersecting: true, intersectionRatio: 0.6 }])
		app.unmount()
		host.remove()

		await render({ collapsible: true, documentOpens: true })
		expect(details()).toBe('none')
		app.unmount()
		host.remove()

		await render({ collapsible: true })
		expect(details()).toBe('none')
	})

	it('expands when the course data switches the option on after the notice was folded', async () => {
		window.localStorage.setItem(OLD_KEY, '1')
		const observers = stubObserver()
		const { ref } = await import('vue')
		const opens = ref(false)
		setTestLanguage('en')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(TrainingPrivacyNotice, { collapsible: true, documentOpens: opens.value }) })
		app.config.globalProperties.t = translate
		app.mount(host)
		await nextTick()
		expect(details()).toBe('none')
		expect(observers).toHaveLength(0)

		opens.value = true
		await nextTick()
		await nextTick()
		expect(details()).not.toBe('none')
		expect(observers).toHaveLength(1)
	})
})
