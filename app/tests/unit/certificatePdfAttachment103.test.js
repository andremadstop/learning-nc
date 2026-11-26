/**
 * GitHub #103: the certificate PDF carries the signed credential as a file attachment, so one file
 * holds the readable certificate and the machine-checkable proof.
 *
 * Here: the writer's embedded file (exact bytes, name, type, cross-reference table), that a PDF
 * without attachments stays as it was, the file name from Content-Disposition, and when
 * Certificate.vue attaches the file. qpdf --check and pdfdetach on a real file were run by hand
 * for this change (see the pull request).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import axios from '@nextcloud/axios'
import { buildSinglePagePdf, pdfAsciiFileName, pdfNameToken } from '../../src/utils/pdfSinglePage.js'
import { fileNameFromDisposition } from '../../src/services/CertificateService.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', inheritAttrs: true, template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const pdfMock = vi.hoisted(() => ({ render: null, save: null }))
vi.mock('../../src/utils/certificatePdf.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		renderCertificatePdf: (...args) => pdfMock.render(...args),
		savePdf: (...args) => pdfMock.save(...args),
		canDownloadByLink: () => true,
	}
})

globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

const VID = '11111111-1111-4111-8111-111111111111'
const ATTACHED = 'Diese PDF-Datei enthält den signierten Nachweis (JSON) als Anhang.'

function fakeJpeg(width, height) {
	return new Uint8Array([
		0xFF, 0xD8,
		0xFF, 0xE0, 0x00, 0x04, 0x4A, 0x46,
		0xFF, 0xC0, 0x00, 0x0B, 0x08, height >> 8, height & 255, width >> 8, width & 255, 3, 0x01, 0x11, 0x00,
		0xFF, 0xD9,
	])
}

const latin1 = (bytes) => Array.from(bytes, (b) => String.fromCharCode(b)).join('')

/** Every xref entry points at "n 0 obj"; returns the trailer /Size. */
function checkXref(text) {
	const startxref = Number(text.match(/startxref\n(\d+)\n%%EOF/)[1])
	expect(text.slice(startxref, startxref + 4)).toBe('xref')
	const size = Number(text.slice(startxref).split('\n')[1].split(' ')[1])
	const entries = text.slice(startxref).split('\n').slice(2, 2 + size)
	for (let n = 1; n < size; n++) {
		expect(entries[n].length + 1).toBe(20)
		const offset = Number(entries[n].slice(0, 10))
		expect(text.slice(offset, offset + (n + ' 0 obj').length)).toBe(n + ' 0 obj')
	}
	return size
}

describe('pdfSinglePage with an attachment (GitHub #103)', () => {
	const jpeg = fakeJpeg(40, 30)
	const date = new Date('2026-11-26T10:00:00Z')
	// bytes a text conversion would break: a NUL, a high byte, "endstream" inside the file
	const credential = new Uint8Array([...new TextEncoder().encode('{"id":"data:application/vc+jwt,a.b.c","x":"endstream"}'), 0x00, 0xFF])

	it('without attachments the file is exactly what it was: PDF 1.4, seven objects, no name tree', () => {
		const text = latin1(buildSinglePagePdf({ jpeg, title: 'T', date }))
		expect(text.startsWith('%PDF-1.4\n')).toBe(true)
		expect(checkXref(text)).toBe(7)
		expect(text).not.toContain('/EmbeddedFiles')
		expect(text).not.toContain('/AF')
		expect(latin1(buildSinglePagePdf({ jpeg, title: 'T', date, attachments: [] }))).toBe(text)
	})

	it('embeds the bytes unchanged in a file stream with type, size and an exact length', () => {
		const pdf = buildSinglePagePdf({ jpeg, title: 'T', date, attachments: [{ name: 'certificate-x.json', bytes: credential, mimeType: 'application/ld+json', description: 'Nachweis' }] })
		const text = latin1(pdf)
		expect(text.startsWith('%PDF-1.7\n')).toBe(true)
		expect(checkXref(text)).toBe(9)
		const head = '7 0 obj\n<< /Type /EmbeddedFile /Subtype /application#2Fld#2Bjson /Params << /Size ' + credential.length + ' /ModDate (D:20261126100000Z) >> /Length ' + credential.length + ' >>\nstream\n'
		const at = text.indexOf(head)
		expect(at).toBeGreaterThan(0)
		const start = at + head.length
		expect(Array.from(pdf.slice(start, start + credential.length))).toEqual(Array.from(credential))
		expect(text.slice(start + credential.length, start + credential.length + 10)).toBe('\nendstream')
	})

	it('names the file in the catalog\'s EmbeddedFiles tree and in /AF, with /F, /UF and a description', () => {
		const text = latin1(buildSinglePagePdf({ jpeg, date, attachments: [{ name: 'Zertifikat (ä).json', bytes: credential, description: 'Nachweis' }] }))
		expect(text).toContain('/Names << /EmbeddedFiles << /Names [(Zertifikat \\(_\\).json) 8 0 R] >> >> /AF [8 0 R]')
		expect(text).toMatch(/8 0 obj\n<< \/Type \/Filespec \/F \(Zertifikat \\\(_\\\)\.json\) \/UF <FEFF[0-9A-F]+> \/Desc <FEFF[0-9A-F]+> \/AFRelationship \/Data \/EF << \/F 7 0 R \/UF 7 0 R >> >>/)
		// no type given: no /Subtype
		expect(text).toContain('<< /Type /EmbeddedFile /Params')
	})

	it('two files: sorted by name in the tree, each with its own pair of objects', () => {
		const text = latin1(buildSinglePagePdf({ jpeg, date, attachments: [{ name: 'b.json', bytes: credential }, { name: 'a.json', bytes: credential }] }))
		expect(checkXref(text)).toBe(11)
		expect(text).toContain('/EmbeddedFiles << /Names [(a.json) 8 0 R (b.json) 10 0 R] >>')
		expect(text).toContain('/AF [8 0 R 10 0 R]')
	})

	it('refuses an attachment without a name or bytes instead of writing a broken PDF', () => {
		expect(() => buildSinglePagePdf({ jpeg, attachments: [{ name: 'x.json', bytes: 'text' }] })).toThrow('attachment needs a name and bytes')
		expect(() => buildSinglePagePdf({ jpeg, attachments: [{ name: '', bytes: credential }] })).toThrow('attachment needs a name and bytes')
		expect(() => buildSinglePagePdf({ jpeg, attachments: [null, { name: 'a.json', bytes: credential }] })).toThrow('attachment needs a name and bytes')
	})

	it('name tree keys are the written ASCII names, sorted by those bytes and never twice', () => {
		const text = latin1(buildSinglePagePdf({ jpeg, date, attachments: [{ name: 'b.json', bytes: credential }, { name: 'ä.json', bytes: credential }, { name: 'ö.json', bytes: credential }] }))
		const keys = [...text.match(/\/EmbeddedFiles << \/Names \[(.*?)\] >>/)[1].matchAll(/\(((?:\\.|[^)])*)\)/g)].map((m) => m[1].replace(/\\(.)/g, '$1'))
		expect(keys).toEqual(['_ (2).json', '_.json', 'b.json'])
		expect([...keys].sort()).toEqual(keys)
	})

	it('PDF names and literal strings are escaped', () => {
		expect(pdfNameToken('application/ld+json')).toBe('application#2Fld#2Bjson')
		expect(pdfNameToken('a b#c')).toBe('a#20b#23c')
		expect(pdfAsciiFileName('a(b)\\c.json')).toBe('(a\\(b\\)\\\\c.json)')
		expect(pdfAsciiFileName('Zertifikat-é.json')).toBe('(Zertifikat-_.json)')
	})
})

describe('fileNameFromDisposition', () => {
	it('reads quoted, plain and UTF-8 names, never a path', () => {
		expect(fileNameFromDisposition('attachment; filename="certificate-1.json"')).toBe('certificate-1.json')
		expect(fileNameFromDisposition('attachment; filename=certificate-1.json')).toBe('certificate-1.json')
		expect(fileNameFromDisposition("attachment; filename=\"x.json\"; filename*=UTF-8''Zertifikat%20%C3%A4.json")).toBe('Zertifikat ä.json')
		expect(fileNameFromDisposition('attachment; filename="../../etc/passwd"')).toBe('passwd')
		expect(fileNameFromDisposition("attachment; filename*=UTF-8''..%2F..%2Fevil.json")).toBe('evil.json')
		expect(fileNameFromDisposition("attachment; filename*=Utf-8''a%20b.json")).toBe('a b.json')
		expect(fileNameFromDisposition(undefined)).toBe('')
		expect(fileNameFromDisposition('attachment')).toBe('')
	})
})

function makeJwt(payload) {
	const bytes = new TextEncoder().encode(JSON.stringify(payload))
	let binary = ''
	bytes.forEach((b) => { binary += String.fromCharCode(b) })
	return `aGVhZGVy.${btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}.c2ln`
}

const CREDENTIAL = {
	issuer: { name: 'Northwind Academy' },
	validFrom: '2026-06-27T10:00:00',
	validUntil: '2099-06-27T10:00:00',
	credentialSubject: { name: 'Alex Example', achievement: { name: 'Datenschutz Grundlagen' }, result: [{ resultDescription: 'score:90; threshold:80' }] },
}

async function flush() {
	for (let i = 0; i < 6; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

describe('Certificate.vue: the PDF carries the credential (GitHub #103)', () => {
	let Certificate
	let view
	const FILE = new TextEncoder().encode('{"@context":["https://www.w3.org/ns/credentials/v2"],"type":"EnvelopedVerifiableCredential"}')

	function serve({ row = {}, download = 'ok' } = {}) {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/download')) {
				if (download === 'fail') return Promise.reject(new Error('network'))
				if (download === 'html') {
					return Promise.resolve({ data: new TextEncoder().encode('<!doctype html><title>Login</title>').buffer, headers: { 'content-type': 'text/html; charset=utf-8' } })
				}
				if (download === 'broken') {
					return Promise.resolve({ data: new TextEncoder().encode('{"type":').buffer, headers: { 'content-type': 'application/ld+json' } })
				}
				return Promise.resolve({
					data: FILE.buffer.slice(0),
					headers: { 'content-disposition': `attachment; filename="certificate-${VID}.json"`, 'content-type': 'application/ld+json; charset=utf-8' },
				})
			}
			return Promise.resolve({ data: { verification_id: VID, credential_json: makeJwt(CREDENTIAL), ...row } })
		})
	}

	async function makePdf(props = {}) {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({ render: () => h(Certificate, { verificationId: VID, ...props }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		view = { unmount: () => { app.unmount(); host.remove() } }
		await flush()
		host.querySelector('[data-test="certificate-pdf-button"]').click()
		await flush()
		expect(pdfMock.render).toHaveBeenCalledTimes(1)
		return pdfMock.render.mock.calls[0][1]
	}

	beforeEach(async () => {
		Certificate = (await import('../../src/components/Certificate.vue')).default
		axios.get.mockReset()
		pdfMock.render = vi.fn(async () => new Blob(['%PDF']))
		pdfMock.save = vi.fn()
	})

	afterEach(() => {
		view?.unmount()
		view = null
		document.body.innerHTML = ''
	})

	it('attaches the file the JSON download serves, byte for byte, and says so on the sheet', async () => {
		serve()
		const options = await makePdf()
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/certificates/{verificationId}/download', { responseType: 'arraybuffer', timeout: 15000 })
		expect(options.attachments).toHaveLength(1)
		const [file] = options.attachments
		expect(file.name).toBe(`certificate-${VID}.json`)
		expect(file.mimeType).toBe('application/ld+json')
		expect(Array.from(file.bytes)).toEqual(Array.from(FILE))
		expect(file.description).toBe('Digitaler Nachweis für IT-Systeme (JSON)')
		expect(options.note.map((p) => p.text).join('')).toContain(' ' + ATTACHED)
		expect(pdfMock.save).toHaveBeenCalled()
	})

	it('the instructor copy attaches the course-scoped file', async () => {
		serve()
		const options = await makePdf({ courseId: 5 })
		expect(axios.get).toHaveBeenCalledWith(`/apps/learning/api/courses/{courseId}/certificates/{verificationId}/download`, { responseType: 'arraybuffer', timeout: 15000 })
		expect(options.attachments).toHaveLength(1)
	})

	it('a course that hides "Technical details" from learners gets no attachment and no sentence', async () => {
		serve({ row: { credential_download_allowed: false } })
		const options = await makePdf()
		expect(options.attachments).toEqual([])
		expect(options.note.map((p) => p.text).join('')).not.toContain(ATTACHED)
		expect(axios.get.mock.calls.some(([url]) => url.endsWith('/download'))).toBe(false)
	})

	for (const download of ['html', 'broken']) {
		it(`a 200 that is not a credential (${download === 'html' ? 'a login page' : 'cut-off JSON'}) is never attached as one`, async () => {
			serve({ download })
			const options = await makePdf()
			expect(options.attachments).toEqual([])
			expect(options.note.map((p) => p.text).join('')).not.toContain(ATTACHED)
			expect(pdfMock.save).toHaveBeenCalled()
		})
	}

	it('when the file cannot be read, the PDF is still made, without attachment and without the sentence', async () => {
		serve({ download: 'fail' })
		const options = await makePdf()
		expect(options.attachments).toEqual([])
		expect(options.note.map((p) => p.text).join('')).not.toContain(ATTACHED)
		expect(pdfMock.save).toHaveBeenCalled()
	})
})
