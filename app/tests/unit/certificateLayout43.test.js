/**
 * GitHub #43: the course decides how its certificates look. Introduction text with placeholders
 * filled from the signed credential, element switches, stamp, signature and signer. Without a
 * layout (every certificate issued before, every course that never set one) the card is exactly
 * the standard card. Text is drawn as text: markup in the layout or in a name is never HTML.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'

const state = vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => (vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)) : text)
	return { row: null }
})
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, imagePath: (app, file) => `/apps/${app}/img/${file}` }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))
vi.mock('../../src/services/CertificateService.js', async (importOriginal) => ({
	...(await importOriginal()),
	getCertificate: vi.fn(() => Promise.resolve(state.row)),
}))

const {
	normalizePresentation,
	placeholderValues,
	renderIntro,
	splitName,
	PLACEHOLDERS,
} = await import('../../src/utils/certificateLayout.js')

const STAMP = 'data:image/png;base64,iVBORw0KGgo='
const VID = '11111111-1111-4111-8111-111111111111'

function fields(over = {}) {
	return {
		courseTitle: 'Arbeitsschutz 2026',
		recipientName: 'Jürgen Müller-Lüdenscheidt',
		courseElements: ['Brandschutz', 'Erste Hilfe'],
		score: 88,
		threshold: 80,
		issuerName: 'Akademie Nord',
		issuerLogo: 'https://cloud.example/logo.png',
		validFrom: '2026-06-27T10:00:00Z',
		validUntil: '2027-06-27T10:00:00Z',
		...over,
	}
}

describe('splitName', () => {
	it('takes the first word as first name and the rest as last name', () => {
		expect(splitName('Jürgen Müller-Lüdenscheidt')).toEqual({ first: 'Jürgen', last: 'Müller-Lüdenscheidt' })
		expect(splitName('Anna Maria  von Berg')).toEqual({ first: 'Anna', last: 'Maria von Berg' })
	})

	it('a one-word name (or the neutral fallback) is only a first name', () => {
		expect(splitName('Teilnehmer:in')).toEqual({ first: 'Teilnehmer:in', last: '' })
		expect(splitName('')).toEqual({ first: '', last: '' })
	})
})

describe('renderIntro', () => {
	const values = placeholderValues(fields(), { issuedDate: '27. Juni 2026', expiryDate: '27. Juni 2027', noExpiry: 'unbefristet' })

	it('lists every placeholder of the issue', () => {
		expect(PLACEHOLDERS).toEqual(['{student.name}', '{student.first_name}', '{student.last_name}', '{course.title}',
			'{exam.date}', '{course.elements}', '{score}', '{valid_until}', '{issuer}'])
	})

	it('replaces the placeholders with the signed values, the name in bold', () => {
		const blocks = renderIntro('Hiermit wird bestätigt, dass {student.name} den Kurs {course.title} am {exam.date} mit {score} bestanden hat.', values)
		expect(blocks).toHaveLength(1)
		expect(blocks[0].parts.map((p) => p.text).join('')).toBe('Hiermit wird bestätigt, dass Jürgen Müller-Lüdenscheidt den Kurs Arbeitsschutz 2026 am 27. Juni 2026 mit 88% bestanden hat.')
		expect(blocks[0].parts.filter((p) => p.strong).map((p) => p.text)).toEqual(['Jürgen Müller-Lüdenscheidt'])
	})

	it('fills first name, last name, validity and issuer', () => {
		const line = renderIntro('{student.last_name}, {student.first_name} – {issuer} – {valid_until}', values)[0]
		expect(line.parts.map((p) => p.text).join('')).toBe('Müller-Lüdenscheidt, Jürgen – Akademie Nord – 27. Juni 2027')
	})

	it('says "unbefristet" for a certificate without expiry', () => {
		const open = placeholderValues(fields(), { issuedDate: 'x', expiryDate: '', noExpiry: 'unbefristet' })
		expect(renderIntro('gültig: {valid_until}', open)[0].parts.map((p) => p.text).join('')).toBe('gültig: unbefristet')
	})

	it('turns a line holding only {course.elements} into the list, inline it joins with commas', () => {
		const blocks = renderIntro('Kursbestandteile:\n  {course.elements}  \nAuch: {course.elements}', values)
		expect(blocks[1]).toEqual({ type: 'list', items: ['Brandschutz', 'Erste Hilfe'] })
		expect(blocks[2].parts.map((p) => p.text).join('')).toBe('Auch: Brandschutz, Erste Hilfe')
	})

	it('drops the list line when the certificate has no elements', () => {
		const none = placeholderValues(fields({ courseElements: [] }))
		expect(renderIntro('A\n{course.elements}\nB', none).map((b) => b.type)).toEqual(['text', 'text'])
	})

	it('leaves unknown placeholders as typed and keeps empty lines as spacing', () => {
		const blocks = renderIntro('{student.email} und {course.pools[0].title}\n\nEnde', values)
		expect(blocks[0].parts.map((p) => p.text).join('')).toBe('{student.email} und {course.pools[0].title}')
		expect(blocks[1]).toEqual({ type: 'gap' })
	})

	it('an empty text gives no blocks (the standard line is drawn instead)', () => {
		expect(renderIntro('', values)).toEqual([])
		expect(renderIntro('  \n ', values)).toEqual([])
	})
})

describe('normalizePresentation', () => {
	it('null is the standard layout', () => {
		expect(normalizePresentation(null)).toEqual({
			intro: '',
			show: { logo: true, score: true, threshold: true, elements: true, validity: true },
			signerName: '',
			signerRole: '',
			stamp: '',
			signature: '',
		})
	})

	it('only accepts PNG data URLs as images', () => {
		const p = normalizePresentation({ stamp: 'javascript:alert(1)', signature: 'https://evil.example/x.png' })
		expect(p.stamp).toBe('')
		expect(p.signature).toBe('')
		expect(normalizePresentation({ stamp: STAMP }).stamp).toBe(STAMP)
	})
})

describe('CertificateCard (GitHub #43)', () => {
	let host, app

	async function mountCard(props) {
		const { default: CertificateCard } = await import('../../src/components/CertificateCard.vue')
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(CertificateCard, { verifyUrl: 'https://x/verify/1', verificationId: VID, qrDataUrl: STAMP, issuedDate: '27. Juni 2026', expiryDate: '27. Juni 2027', ...props }) })
		app.config.globalProperties.t = globalThis.t
		app.mount(host)
		await nextTick()
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('without a layout draws the standard card with every element', async () => {
		await mountCard({ fields: fields(), presentation: null })
		expect(host.querySelector('.certificate-recipient').textContent.replace(/\s+/g, ' ').trim()).toBe('Verliehen an: Jürgen Müller-Lüdenscheidt')
		expect(host.querySelector('.certificate-logo')).not.toBeNull()
		expect(host.querySelectorAll('.certificate-meta-item')).toHaveLength(4)
		expect(host.querySelector('.certificate-elements')).not.toBeNull()
		expect(host.querySelector('.certificate-signoff')).toBeNull()
		expect(host.querySelector('.certificate-intro')).toBeNull()
		expect(host.querySelector('.certificate-qr')).not.toBeNull()
	})

	it('draws markup in the text and in the name as text, never as HTML', async () => {
		await mountCard({
			fields: fields({ recipientName: '<img src=x onerror=alert(1)>' }),
			presentation: { intro: '<script>alert(1)</script> {student.name} <b>fett</b>' },
		})
		const intro = host.querySelector('.certificate-intro')
		expect(intro.querySelector('script')).toBeNull()
		expect(intro.querySelector('img')).toBeNull()
		expect(intro.querySelector('b')).toBeNull()
		expect(intro.textContent).toContain('<script>alert(1)</script>')
		expect(intro.querySelector('strong').textContent).toBe('<img src=x onerror=alert(1)>')
		expect(host.querySelector('.certificate-recipient')).toBeNull()
	})

	it('hides switched-off elements, but never the QR code and the verification link', async () => {
		await mountCard({
			fields: fields(),
			presentation: { show: { logo: false, score: false, threshold: false, elements: false, validity: false } },
		})
		expect(host.querySelector('.certificate-logo')).toBeNull()
		expect(host.querySelector('.certificate-elements')).toBeNull()
		const labels = Array.from(host.querySelectorAll('.certificate-meta-item .certificate-meta-label')).map((e) => e.textContent.trim())
		expect(labels).toEqual(['Ausgestellt am'])
		expect(host.querySelector('.certificate-qr')).not.toBeNull()
		expect(host.querySelector('.certificate-verify-id').textContent).toBe(VID)
		expect(host.querySelector('.certificate-issuer').textContent).toBe('Akademie Nord')
	})

	it('does not list the elements twice when the text already does', async () => {
		await mountCard({ fields: fields(), presentation: { intro: 'Bestandteile:\n{course.elements}' } })
		expect(host.querySelector('.certificate-elements')).toBeNull()
		expect(Array.from(host.querySelectorAll('.certificate-intro li')).map((li) => li.textContent.trim())).toEqual(['Brandschutz', 'Erste Hilfe'])
	})

	it('draws stamp, signature and signer', async () => {
		await mountCard({
			fields: fields(),
			presentation: { stamp: STAMP, signature: STAMP, signer_name: 'Dr. Maria Muster', signer_role: 'Leitung Weiterbildung' },
		})
		const block = host.querySelector('.certificate-signoff')
		expect(block.querySelector('.certificate-stamp').getAttribute('src')).toBe(STAMP)
		expect(block.querySelector('.certificate-signature').getAttribute('alt')).toBe('Unterschrift')
		expect(block.querySelector('.certificate-signer-name').textContent).toBe('Dr. Maria Muster')
		expect(block.querySelector('.certificate-signer-role').textContent).toBe('Leitung Weiterbildung')
	})
})

describe('Certificate.vue draws the frozen presentation of the show response (GitHub #43)', () => {
	let host, app

	function jwt(payload) {
		const bytes = new TextEncoder().encode(JSON.stringify(payload))
		let binary = ''
		bytes.forEach((b) => { binary += String.fromCharCode(b) })
		return `aGVhZGVy.${btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}.c2ln`
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('uses row.presentation for the card', async () => {
		state.row = {
			credential_json: jwt({
				issuer: { name: 'Akademie Nord' },
				validFrom: '2026-06-27T10:00:00Z',
				credentialSubject: { name: 'Jürgen Müller', achievement: { name: 'Arbeitsschutz 2026' }, result: [{ resultDescription: 'score:88; threshold:80' }] },
			}),
			presentation: { intro: 'Hiermit wird bestätigt, dass {student.name} teilgenommen hat.', show: { score: false }, signer_name: 'Maria Muster' },
		}
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
		const card = host.querySelector('.certificate-printable .certificate-card')
		expect(card.querySelector('.certificate-intro').textContent.replace(/\s+/g, ' ').trim()).toBe('Hiermit wird bestätigt, dass Jürgen Müller teilgenommen hat.')
		expect(card.textContent).not.toContain('88%')
		expect(card.querySelector('.certificate-signer-name').textContent).toBe('Maria Muster')
	})
})

describe('course settings: request body of the layout (GitHub #43)', () => {
	it('saveCertConfig sends certLayout with exactly the keys CertLayout::apply reads', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const { default: CourseTabVerwaltung, certLayoutFromServer } = await import('../../src/components/CourseTabVerwaltung.vue')
		const ctx = {
			courseId: 7,
			certEnabled: true,
			certPassPercent: 80,
			certRequiredPoolIds: [],
			certValidityDays: 0,
			examScopeQuestions: '',
			examScopeMinutes: '',
			certLayout: {
				...certLayoutFromServer({ has_stamp: true }),
				intro: 'Hiermit {student.name}',
				signer_name: 'Maria Muster',
			},
			$emit: vi.fn(),
			parseExamScopeDefault: CourseTabVerwaltung.methods.parseExamScopeDefault,
		}
		axios.patch.mockResolvedValueOnce({ data: { certLayout: { intro: 'Hiermit {student.name}', has_stamp: true } } })

		await CourseTabVerwaltung.methods.saveCertConfig.call(ctx)

		const [url, body] = axios.patch.mock.calls.at(-1)
		expect(url).toBe('/apps/learning/api/courses/7/cert-config')
		expect(Object.keys(body.certLayout).sort()).toEqual(['intro', 'show', 'signer_name', 'signer_role'])
		expect(body.certLayout.intro).toBe('Hiermit {student.name}')
		expect(body.certLayout.show).toEqual({ logo: true, score: true, threshold: true, elements: true, validity: true })
		expect(ctx.certLayout.has_stamp).toBe(true)
	})
})
