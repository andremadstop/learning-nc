/**
 * GitHub #41: "Print" gave an almost empty sheet with a few pixels of the logo.
 *
 * The certificate lives in an NcModal whose container clips its content (`overflow: auto` plus
 * a max-height inside a fixed mask). Printing the card in place printed that clipped box. Print
 * now copies the card directly under <body> and flags the document, and the print stylesheet
 * shows only the copy. The component is mounted for real; window.print is stubbed so the test
 * can see what the page looks like at the moment the browser prints.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const VID = '11111111-1111-4111-8111-111111111111'

function makeJwt(payload) {
	const bytes = new TextEncoder().encode(JSON.stringify(payload))
	let binary = ''
	bytes.forEach((b) => { binary += String.fromCharCode(b) })
	const b64url = btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
	return `aGVhZGVy.${b64url}.c2lnbmF0dXJl`
}

const credential = {
	issuer: { name: 'Akademie Nord', image: { id: 'data:image/png;base64,iVBORw0KGgo=', type: 'Image' } },
	validFrom: '2026-06-27T10:00:00+00:00',
	credentialSubject: {
		name: 'Jürgen Müller',
		achievement: { name: 'Sicherheit & Compliance' },
		result: [{ resultDescription: 'score:88; threshold:80' }],
	},
}

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('../../src/services/CertificateService.js', () => ({
	getCertificate: vi.fn(() => Promise.resolve({ credential_json: makeJwt(credential) })),
	downloadUrl: (vid) => `/apps/learning/api/certificates/${vid}/download`,
}))

import Certificate from '../../src/components/Certificate.vue'
import { PRINT_ROOT_ID, PRINTING_CLASS } from '../../src/utils/certificatePrint.js'

async function flush() {
	for (let i = 0; i < 5; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

function printButton(host) {
	return Array.from(host.querySelectorAll('button')).find((b) => b.textContent.trim() === 'Drucken')
}

describe('Certificate print (GitHub #41)', () => {
	let host, app, seen

	beforeEach(async () => {
		globalThis.t = (_app, text) => text
		seen = null
		window.print = vi.fn(() => {
			const root = document.getElementById(PRINT_ROOT_ID)
			seen = {
				rootIsBodyChild: !!root && root.parentElement === document.body,
				cardInRoot: !!root && !!root.querySelector('.certificate-printable .certificate-card'),
				title: root?.querySelector('.certificate-title')?.textContent,
				flagged: document.documentElement.classList.contains(PRINTING_CLASS),
				hasActions: !!root?.querySelector('.certificate-actions'),
			}
		})
		// Stands in for the modal: the card is nested deep inside, not under <body>.
		host = document.createElement('div')
		host.className = 'modal-container'
		document.body.appendChild(host)
		app = createApp({ render: () => h(Certificate, { verificationId: VID }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await flush()
	})

	afterEach(() => {
		app?.unmount()
		host?.remove()
		document.getElementById(PRINT_ROOT_ID)?.remove()
		document.documentElement.classList.remove(PRINTING_CLASS)
	})

	it('prints a copy of the whole card that sits directly under <body>, outside the modal', async () => {
		printButton(host).click()
		await flush()

		expect(window.print).toHaveBeenCalledTimes(1)
		expect(seen.rootIsBodyChild).toBe(true)
		expect(seen.cardInRoot).toBe(true)
		expect(seen.title).toBe('Sicherheit & Compliance')
		expect(seen.flagged).toBe(true)
		expect(seen.hasActions).toBe(false)
		// The card on screen stays where it was.
		expect(host.querySelector('.certificate-card')).not.toBeNull()
	})

	it('removes the copy and the flag after printing', async () => {
		printButton(host).click()
		await flush()
		window.dispatchEvent(new Event('afterprint'))

		expect(document.getElementById(PRINT_ROOT_ID)).toBeNull()
		expect(document.documentElement.classList.contains(PRINTING_CLASS)).toBe(false)
		expect(host.querySelector('.certificate-card')).not.toBeNull()
	})

	it('printing twice leaves one copy, and closing the certificate removes it', async () => {
		printButton(host).click()
		await flush()
		printButton(host).click()
		await flush()
		expect(document.querySelectorAll('#' + PRINT_ROOT_ID)).toHaveLength(1)

		app.unmount()
		app = null
		expect(document.getElementById(PRINT_ROOT_ID)).toBeNull()
		expect(document.documentElement.classList.contains(PRINTING_CLASS)).toBe(false)
	})
})
