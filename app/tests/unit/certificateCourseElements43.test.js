/**
 * GitHub #43 (minimum version): the certificate names the course's required pools as "Course
 * elements". Issuance signs them into credentialSubject.achievement.alignment (OB3 Alignment,
 * targetType ext:LearningRequiredPool). Certificates issued before, and courses without required
 * pools ("any exam"), carry no such field: the section is left out, nothing is filled in from
 * live course data.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import {
	POOL_ALIGNMENT_TYPE,
	extractCourseElements,
	extractCertificateFields,
} from '../../src/utils/certificate-credential.js'

const VID = '11111111-1111-4111-8111-111111111111'

function makeJwt(payload) {
	const bytes = new TextEncoder().encode(JSON.stringify(payload))
	let binary = ''
	bytes.forEach((b) => { binary += String.fromCharCode(b) })
	return `aGVhZGVy.${btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}.c2ln`
}

function credential(alignment) {
	const achievement = { name: 'Arbeitsschutz 2026' }
	if (alignment !== undefined) {
		achievement.alignment = alignment
	}
	return {
		issuer: { name: 'Akademie Nord' },
		validFrom: '2026-06-27T10:00:00+00:00',
		credentialSubject: { name: 'Jürgen Müller', achievement, result: [{ resultDescription: 'score:88; threshold:80' }] },
	}
}

const POOLS = [
	{ type: ['Alignment'], targetType: POOL_ALIGNMENT_TYPE, targetName: 'Brandschutz', targetUrl: 'urn:learning:pool:12' },
	{ type: ['Alignment'], targetType: 'CTDL', targetName: 'Fremdes Framework', targetUrl: 'https://example.org/x' },
	{ type: ['Alignment'], targetType: POOL_ALIGNMENT_TYPE, targetName: 'Erste Hilfe', targetUrl: 'urn:learning:pool:7' },
]

const state = vi.hoisted(() => ({ jwt: '' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('../../src/services/CertificateService.js', () => ({
	getCertificate: vi.fn(() => Promise.resolve({ credential_json: state.jwt })),
	downloadUrl: (vid) => `/apps/learning/api/certificates/${vid}/download`,
}))

describe('extractCourseElements (GitHub #43)', () => {
	it('returns the signed required-pool names in order, ignoring other alignments', () => {
		expect(extractCourseElements({ alignment: POOLS })).toEqual(['Brandschutz', 'Erste Hilfe'])
	})

	it('returns [] for a certificate without the field and for malformed values', () => {
		expect(extractCourseElements({})).toEqual([])
		expect(extractCourseElements(undefined)).toEqual([])
		expect(extractCourseElements({ alignment: 'x' })).toEqual([])
		expect(extractCourseElements({ alignment: [null, { targetType: POOL_ALIGNMENT_TYPE, targetName: ' ' }] })).toEqual([])
	})

	it('is part of the display fields', () => {
		expect(extractCertificateFields(credential(POOLS)).courseElements).toEqual(['Brandschutz', 'Erste Hilfe'])
		expect(extractCertificateFields(credential()).courseElements).toEqual([])
	})
})

describe('Certificate.vue course elements (GitHub #43)', () => {
	let host, app

	async function mount(cred) {
		state.jwt = makeJwt(cred)
		globalThis.t = (_app, text) => text
		const { default: Certificate } = await import('../../src/components/Certificate.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(Certificate, { verificationId: VID }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		for (let i = 0; i < 5; i++) {
			await new Promise((r) => setTimeout(r, 0))
			await nextTick()
		}
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('lists the required pools under "Kursbestandteile", inside the printable card', async () => {
		await mount(credential(POOLS))
		const section = host.querySelector('.certificate-printable .certificate-card .certificate-elements')
		expect(section).not.toBeNull()
		expect(section.querySelector('h3').textContent.trim()).toBe('Kursbestandteile')
		expect(Array.from(section.querySelectorAll('li')).map((li) => li.textContent.trim()))
			.toEqual(['Brandschutz', 'Erste Hilfe'])
	})

	it('leaves the section out for an older or "any exam" certificate', async () => {
		await mount(credential())
		expect(host.querySelector('.certificate-card')).not.toBeNull()
		expect(host.querySelector('.certificate-elements')).toBeNull()
		expect(host.textContent).not.toContain('Kursbestandteile')
	})
})
