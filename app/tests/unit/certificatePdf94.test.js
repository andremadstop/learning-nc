/**
 * GitHub #94: "Als PDF herunterladen" on a certificate. The PDF is a picture of the printed sheet,
 * made in the browser, with a line saying it is a presentation copy and how to check it.
 *
 * Here: the one-page PDF writer (structure and byte offsets), the file name, the sheet copy, the
 * retry and the error path of the rendering (with a stand-in rasteriser), and Certificate.vue's
 * button, status and error. The real rendering in Chromium and WebKit is tests/print/certificate-pdf.spec.cjs.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import axios from '@nextcloud/axios'
import { A4_LANDSCAPE_PT, buildSinglePagePdf, jpegInfo, pdfTextString } from '../../src/utils/pdfSinglePage.js'
import {
	PDF_ROOT_ID,
	PDF_SCALES,
	SHEET_PX,
	buildCertificateSheet,
	canvasLooksDrawn,
	certificatePdfFileName,
	noteParts,
	removeCertificatePdfCopy,
	renderCertificatePdf,
	sameOriginFontCss,
	savePdf,
	slugPart,
} from '../../src/utils/certificatePdf.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text, getCanonicalLocale: () => 'de-DE', getLanguage: () => 'de' }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', inheritAttrs: true, template: '<button><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const pdfMock = vi.hoisted(() => ({ render: null, save: null, canLink: true }))
vi.mock('../../src/utils/certificatePdf.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		renderCertificatePdf: (...args) => (pdfMock.render ? pdfMock.render(...args) : real.renderCertificatePdf(...args)),
		savePdf: (...args) => (pdfMock.save ? pdfMock.save(...args) : real.savePdf(...args)),
		canDownloadByLink: () => pdfMock.canLink,
	}
})

globalThis.t = (_app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

// The Certificate.vue tests replace render and save; the savePdf and renderCertificatePdf tests need
// the real functions whatever ran before them.
afterEach(() => {
	pdfMock.render = null
	pdfMock.save = null
	pdfMock.canLink = true
})

const VID = '11111111-1111-4111-8111-111111111111'

/** The smallest JPEG this reader accepts: SOI, an APP0 segment, SOF0 (w × h, n components), EOI. */
function fakeJpeg(width, height, components = 3) {
	return new Uint8Array([
		0xFF, 0xD8,
		0xFF, 0xE0, 0x00, 0x04, 0x4A, 0x46,
		0xFF, 0xC0, 0x00, 0x0B, 0x08, height >> 8, height & 255, width >> 8, width & 255, components, 0x01, 0x11, 0x00,
		0xFF, 0xD9,
	])
}

const latin1 = (bytes) => Array.from(bytes, (b) => String.fromCharCode(b)).join('')

describe('pdfSinglePage: one page, one JPEG, exact cross-reference table', () => {
	const jpeg = fakeJpeg(2339, 1653)
	const pdf = buildSinglePagePdf({ jpeg, title: 'Datenschutz – شهادة', subject: 'https://cloud.example/verify/x', lang: 'ar', date: new Date('2026-11-19T10:00:00Z') })
	const text = latin1(pdf)

	it('starts as PDF 1.4 with a binary marker and ends with %%EOF', () => {
		expect(text.startsWith('%PDF-1.4\n%')).toBe(true)
		expect(pdf[10]).toBeGreaterThan(127)
		expect(text.trimEnd().endsWith('%%EOF')).toBe(true)
	})

	it('every xref offset points at its object, startxref at the table', () => {
		const startxref = Number(text.match(/startxref\n(\d+)\n%%EOF/)[1])
		expect(text.slice(startxref, startxref + 4)).toBe('xref')
		const entries = text.slice(startxref).split('\n').slice(2, 9)
		expect(entries[0]).toBe('0000000000 65535 f ')
		for (let n = 1; n <= 6; n++) {
			// 20 bytes per entry including the line end
			expect(entries[n].length + 1).toBe(20)
			const offset = Number(entries[n].slice(0, 10))
			expect(text.slice(offset, offset + (n + ' 0 obj').length)).toBe(n + ' 0 obj')
		}
		expect(text).toContain('trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>')
	})

	it('one A4 landscape page with the picture drawn over all of it', () => {
		expect(text).toContain('/MediaBox [0 0 841.89 595.28]')
		expect(text).toContain('/Count 1')
		expect(text).toContain('841.89 0 0 595.28 0 0 cm\n/Im0 Do')
		expect(A4_LANDSCAPE_PT).toEqual({ width: 841.89, height: 595.28 })
	})

	it('the image object carries the JPEG unchanged with its own size', () => {
		expect(text).toContain('/Width 2339 /Height 1653 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length + ' >>')
		const start = text.indexOf('stream\n', text.indexOf('/DCTDecode')) + 'stream\n'.length
		expect(Array.from(pdf.slice(start, start + jpeg.length))).toEqual(Array.from(jpeg))
		// the content stream's /Length is its byte count
		const content = text.match(/5 0 obj\n<< \/Length (\d+) >>\nstream\n([\s\S]*?)endstream/)
		expect(content[2].length).toBe(Number(content[1]))
	})

	it('title, subject and language as UTF-16 text strings (any script)', () => {
		expect(text).toContain('/Title ' + pdfTextString('Datenschutz – شهادة'))
		expect(pdfTextString('ش')).toBe('<FEFF0634>')
		expect(pdfTextString('😀')).toBe('<FEFFD83DDE00>')
		expect(text).toContain('/Subject ' + pdfTextString('https://cloud.example/verify/x'))
		expect(text).toContain('/Producer ' + pdfTextString('Nextcloud Learning'))
		expect(text).toContain('/Lang ' + pdfTextString('ar'))
		expect(text).toContain('/CreationDate (D:20261119100000Z)')
	})

	it('reads size and colour components from the frame header, grey and CMYK included', () => {
		expect(jpegInfo(fakeJpeg(10, 20, 1))).toEqual({ width: 10, height: 20, components: 1 })
		expect(latin1(buildSinglePagePdf({ jpeg: fakeJpeg(4, 4, 1) }))).toContain('/ColorSpace /DeviceGray')
		expect(latin1(buildSinglePagePdf({ jpeg: fakeJpeg(4, 4, 4) }))).toContain('/ColorSpace /DeviceCMYK /BitsPerComponent 8 /Decode [1 0 1 0 1 0 1 0]')
	})

	it('refuses what is not a JPEG instead of writing a broken PDF', () => {
		expect(() => buildSinglePagePdf({ jpeg: new Uint8Array([0x89, 0x50, 0x4E, 0x47]) })).toThrow('not a JPEG')
		expect(() => jpegInfo(new Uint8Array([0xFF, 0xD8, 0xFF, 0xD9, 0, 0, 0, 0, 0, 0, 0, 0]))).toThrow()
	})
})

describe('file name: {Certificate}-{course}-{issue date}.pdf', () => {
	it('course title as letters and digits of every script, the issue date', () => {
		expect(certificatePdfFileName({ prefix: 'Zertifikat', courseTitle: 'Datenschutz & IT-Sicherheit 2026', validFrom: '2026-06-27T10:00:00' }))
			.toBe('Zertifikat-Datenschutz-IT-Sicherheit-2026-2026-06-27.pdf')
		expect(certificatePdfFileName({ prefix: 'Сертификат', courseTitle: 'Защита данных', validFrom: '2026-06-27T10:00:00' }))
			.toBe('Сертификат-Защита-данных-2026-06-27.pdf')
		expect(certificatePdfFileName({ prefix: 'شهادة', courseTitle: 'حماية البيانات', validFrom: '2026-06-27T10:00:00' }))
			.toBe('شهادة-حماية-البيانات-2026-06-27.pdf')
	})

	it('keeps combining marks (vowel signs, diacritics), unifies compatibility forms', () => {
		expect(slugPart('हिन्दी पाठ्यक्रम')).toBe('हिन्दी-पाठ्यक्रम')
		expect(slugPart('ｆｕｌｌ　ｗｉｄｔｈ')).toBe('full-width')
		expect(slugPart('Café')).toBe('Café')
	})

	it('empty or symbols only: just the prefix; no date: no date', () => {
		expect(certificatePdfFileName({ prefix: 'Zertifikat', courseTitle: '***', validFrom: '2026-06-27T10:00:00' })).toBe('Zertifikat-2026-06-27.pdf')
		expect(certificatePdfFileName({ prefix: 'Zertifikat', courseTitle: '', validFrom: '' })).toBe('Zertifikat.pdf')
		expect(certificatePdfFileName({ prefix: '', courseTitle: 'A', validFrom: 'not a date' })).toBe('certificate-A.pdf')
	})

	it('at most 60 characters of course, never cut into a surrogate pair or ending on a dash', () => {
		const long = slugPart('x'.repeat(59) + ' yyyy')
		expect(long).toBe('x'.repeat(59))
		const emoji = slugPart('a'.repeat(59) + '😀😀')
		expect(Array.from(emoji)).toHaveLength(59)
	})
})

describe('the presentation-copy line', () => {
	const template = 'Präsentationskopie, kein eigenständiger Nachweis. Echtheit prüfen: QR-Code scannen oder {url} öffnen (Zertifikat-ID {id}).'

	it('puts link and id into left-to-right parts in the order of the translation', () => {
		const parts = noteParts(template, { id: VID, url: 'https://cloud.example/verify/' + VID })
		expect(parts.filter((p) => p.ltr).map((p) => p.text)).toEqual(['https://cloud.example/verify/' + VID, VID])
		expect(parts.map((p) => p.text).join('')).toBe(template.replace('{url}', 'https://cloud.example/verify/' + VID).replace('{id}', VID))
		// a translation that swaps the order keeps it
		expect(noteParts('{id} — {url}', { id: 'I', url: 'U' }).map((p) => p.text)).toEqual(['I', ' — ', 'U'])
	})
})

describe('the sheet copy the PDF is drawn from', () => {
	afterEach(() => {
		removeCertificatePdfCopy()
		document.body.innerHTML = ''
	})

	function source() {
		const el = document.createElement('div')
		el.className = 'certificate-printable'
		el.innerHTML = '<div class="certificate-card"><img class="certificate-qr" src="data:image/png;base64,AA=="></div>'
		document.body.appendChild(el)
		return el
	}

	it('is a hidden, inert copy in an A4 sheet with the line, ids and links isolated left to right', () => {
		const src = source()
		const root = buildCertificateSheet(src, { note: noteParts('Kopie {url} ({id})', { id: VID, url: 'https://x/v' }), dir: 'rtl', lang: 'ar' })
		expect(root.id).toBe(PDF_ROOT_ID)
		expect(root.classList.contains('certificate-sheet')).toBe(true)
		expect(root.classList.contains('certificate-sheet--pdf')).toBe(true)
		expect(root.getAttribute('aria-hidden')).toBe('true')
		expect(root.hasAttribute('inert')).toBe(true)
		expect(root.getAttribute('dir')).toBe('rtl')
		expect(root.getAttribute('lang')).toBe('ar')
		// a copy: the card on screen stays where it is
		expect(root.querySelector('.certificate-printable')).not.toBe(src)
		expect(src.isConnected).toBe(true)
		const line = root.querySelector('[data-test="certificate-pdf-note"]')
		expect(line.textContent).toBe('Kopie https://x/v (' + VID + ')')
		expect(Array.from(line.querySelectorAll('bdi')).map((b) => [b.getAttribute('dir'), b.textContent])).toEqual([['ltr', 'https://x/v'], ['ltr', VID]])
		// the line is text, never markup
		const evil = buildCertificateSheet(src, { note: [{ text: '<img src=x onerror=alert(1)>', ltr: false }] })
		expect(evil.querySelector('[data-test="certificate-pdf-note"] img')).toBeNull()
	})

	it('a second copy replaces the first; remove is safe any number of times', () => {
		const src = source()
		buildCertificateSheet(src)
		buildCertificateSheet(src)
		expect(document.querySelectorAll('#' + PDF_ROOT_ID)).toHaveLength(1)
		removeCertificatePdfCopy()
		removeCertificatePdfCopy()
		expect(document.getElementById(PDF_ROOT_ID)).toBeNull()
	})
})

/** A stand-in canvas: `dark` decides whether getImageData finds ink. */
function fakeCanvas({ dark = true, width = 2339, height = 1653, jpeg = fakeJpeg(2339, 1653) } = {}) {
	return {
		width,
		height,
		getContext: () => ({
			getImageData: (x, y, w, h) => ({ data: new Uint8ClampedArray(w * h * 4).fill(dark ? 0 : 255) }),
		}),
		toBlob: (cb, type, quality) => {
			fakeCanvas.lastType = [type, quality]
			cb(new Blob([jpeg], { type: 'image/jpeg' }))
		},
	}
}

describe('renderCertificatePdf: never a blank PDF, never a stray copy', () => {
	let src

	beforeEach(() => {
		src = document.createElement('div')
		src.className = 'certificate-printable'
		src.innerHTML = '<div class="certificate-card"><img class="certificate-qr" src="data:image/png;base64,AA=="></div>'
		document.body.appendChild(src)
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('draws the sheet at about 200 dpi in fixed sheet size, white behind, as a JPEG in a PDF', async () => {
		const rasterize = vi.fn(async (root) => {
			expect(root.id).toBe(PDF_ROOT_ID)
			expect(root.isConnected).toBe(true)
			return fakeCanvas()
		})
		const blob = await renderCertificatePdf(src, { rasterize, title: 'Kurs', subject: 'https://x/v', lang: 'de' })
		expect(blob.type).toBe('application/pdf')
		const text = latin1(new Uint8Array(await blob.arrayBuffer()))
		expect(text).toContain('/Width 2339 /Height 1653')
		expect(text).toContain('/Title ' + pdfTextString('Kurs'))
		const options = rasterize.mock.calls[0][1]
		expect(options).toMatchObject({ width: SHEET_PX.width, height: SHEET_PX.height, backgroundColor: '#ffffff' })
		expect(options.scale).toBeCloseTo(2339 / 1123, 5)
		expect(options.style).toMatchObject({ position: 'relative', left: '0', top: '0', transform: 'none' })
		expect(fakeCanvas.lastType).toEqual(['image/jpeg', 0.9])
		expect(document.getElementById(PDF_ROOT_ID)).toBeNull()
	})

	it('loads nothing from another host: foreign images become empty, no foreign fonts', async () => {
		const rasterize = vi.fn(async () => fakeCanvas())
		await renderCertificatePdf(src, { rasterize })
		const options = rasterize.mock.calls[0][1]
		expect(await options.fetchFn('https://fonts.example.net/a.png')).toBe('data:,')
		expect(await options.fetchFn(window.location.origin + '/apps/learning/img/a.png')).toBe(false)
		// the test page has no @font-face of its own: no font embedding at all
		expect(options.font).toBe(false)
	})

	it('an empty canvas is tried once more at 144 dpi, then succeeds', async () => {
		const rasterize = vi.fn()
			.mockResolvedValueOnce(fakeCanvas({ dark: false }))
			.mockResolvedValueOnce(fakeCanvas({ width: 1685, height: 1191, jpeg: fakeJpeg(1685, 1191) }))
		const blob = await renderCertificatePdf(src, { rasterize })
		expect(rasterize.mock.calls.map((c) => c[1].scale)).toEqual([...PDF_SCALES])
		expect(PDF_SCALES[1]).toBe(1.5)
		expect(latin1(new Uint8Array(await blob.arrayBuffer()))).toContain('/Width 1685 /Height 1191')
	})

	it('empty twice, or the canvas throws: an error, not a PDF, and the copy is gone', async () => {
		await expect(renderCertificatePdf(src, { rasterize: async () => fakeCanvas({ dark: false }) })).rejects.toThrow('empty')
		expect(document.getElementById(PDF_ROOT_ID)).toBeNull()
		await expect(renderCertificatePdf(src, { rasterize: async () => { throw new Error('canvas too large') } })).rejects.toThrow('canvas too large')
		expect(document.getElementById(PDF_ROOT_ID)).toBeNull()
		const noBlob = { ...fakeCanvas(), toBlob: (cb) => cb(null) }
		await expect(renderCertificatePdf(src, { rasterize: async () => noBlob })).rejects.toThrow('empty JPEG')
		await expect(renderCertificatePdf(null, { rasterize: async () => fakeCanvas() })).rejects.toThrow()
	})

	it('canvasLooksDrawn looks at the QR code when there is one', () => {
		expect(canvasLooksDrawn(fakeCanvas(), { x: 10, y: 10, width: 50, height: 50 })).toBe(true)
		expect(canvasLooksDrawn(fakeCanvas({ dark: false }), { x: 10, y: 10, width: 50, height: 50 })).toBe(false)
		expect(canvasLooksDrawn({ width: 0, height: 0, getContext: () => null }, null)).toBe(false)
	})
})

describe('sameOriginFontCss', () => {
	const doc = (sheets) => ({ defaultView: { location: { origin: 'https://cloud.example' } }, baseURI: 'https://cloud.example/apps/learning/', styleSheets: sheets })

	it('keeps @font-face rules of this origin (made absolute) and drops foreign ones', () => {
		const css = sameOriginFontCss(doc([
			{ href: 'https://cloud.example/core/css/fonts.css', cssRules: [{ type: 5, cssText: '@font-face { font-family: "A"; src: url("../fonts/a.woff2") format("woff2"); }' }] },
			{ href: null, cssRules: [{ type: 5, cssText: '@font-face { font-family: "B"; src: url(https://fonts.example.net/b.woff2); }' }] },
			{ href: null, cssRules: [{ type: 5, cssText: '@font-face { font-family: "C"; src: url(data:font/woff2;base64,AA==); }' }] },
			{ href: null, cssRules: [{ type: 1, cssText: 'body { font-family: A; }' }] },
			{ get cssRules() { throw new Error('SecurityError') } },
		]))
		expect(css).toContain('url("https://cloud.example/core/fonts/a.woff2")')
		expect(css).not.toContain('fonts.example.net')
		expect(css).toContain('data:font/woff2')
		expect(css).not.toContain('body')
	})
})

describe('savePdf', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('saves under the file name through a download link and frees the blob later', () => {
		vi.useFakeTimers()
		const created = []
		const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
		vi.spyOn(URL, 'createObjectURL').mockImplementation(() => 'blob:pdf')
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() { created.push([this.download, this.href]) })
		expect(savePdf(new Blob(['x']), 'Zertifikat-Kurs-2026-06-27.pdf')).toBe('download')
		expect(created).toEqual([['Zertifikat-Kurs-2026-06-27.pdf', 'blob:pdf']])
		expect(document.querySelector('a[download]')).toBeNull()
		expect(revoke).not.toHaveBeenCalled()
		vi.advanceTimersByTime(60_000)
		expect(revoke).toHaveBeenCalledWith('blob:pdf')
		click.mockRestore()
		vi.restoreAllMocks()
	})

	it('where links cannot download, the PDF goes into the tab opened at the click', () => {
		vi.spyOn(URL, 'createObjectURL').mockImplementation(() => 'blob:pdf')
		vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
		const tab = { location: { href: '' } }
		expect(savePdf(new Blob(['x']), 'a.pdf', { pendingWindow: tab })).toBe('tab')
		expect(tab.location.href).toBe('blob:pdf')
		vi.restoreAllMocks()
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

describe('Certificate.vue: "Als PDF herunterladen"', () => {
	let Certificate
	let view

	function mount(props) {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({ render: () => h(Certificate, { verificationId: VID, ...props }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		return { host, unmount: () => { app.unmount(); host.remove() } }
	}

	async function open(row = {}, props = {}, credential = CREDENTIAL) {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(credential), ...row } })
		view = mount(props)
		await flush()
		return view.host
	}

	const button = (host) => host.querySelector('[data-test="certificate-pdf-button"]')
	const status = (host) => host.querySelector('[data-test="certificate-pdf-status"]')

	beforeEach(async () => {
		Certificate = (await import('../../src/components/Certificate.vue')).default
		axios.get.mockReset()
		pdfMock.render = null
		pdfMock.save = vi.fn()
		pdfMock.canLink = true
	})

	afterEach(() => {
		view?.unmount()
		view = null
		document.body.innerHTML = ''
	})

	it('is next to Print for learners and on the instructor copy, also with the JSON section switched off', async () => {
		for (const [row, props] of [[{}, {}], [{ credential_download_allowed: false }, {}], [{}, { courseId: 5 }]]) {
			const host = await open(row, props)
			const actions = host.querySelector('.certificate-actions')
			expect(Array.from(actions.querySelectorAll('button')).map((b) => b.textContent.trim()).slice(0, 2)).toEqual(['Drucken', 'Als PDF herunterladen'])
			// the live region is there before anything happens, empty
			expect(status(host).getAttribute('role')).toBe('status')
			expect(status(host).textContent).toBe('')
			view.unmount()
			view = null
		}
	})

	it('busy while the PDF is made, then saved under the course name with the presentation-copy line', async () => {
		let finish
		pdfMock.render = vi.fn(() => new Promise((resolve) => { finish = resolve }))
		const host = await open()
		button(host).click()
		await nextTick()
		expect(button(host).getAttribute('aria-busy')).toBe('true')
		expect(button(host).disabled).toBe(true)
		expect(status(host).textContent).toBe('PDF wird erstellt …')
		// a second click while busy starts nothing (#103: the credential is read before rendering)
		button(host).click()
		await flush()
		expect(pdfMock.render).toHaveBeenCalledTimes(1)

		const [source, options] = pdfMock.render.mock.calls[0]
		expect(source.classList.contains('certificate-printable')).toBe(true)
		const verify = window.location.origin + '/apps/learning/verify/' + VID
		expect(options.title).toBe('Datenschutz Grundlagen')
		expect(options.subject).toBe(verify)
		expect(options.note.map((p) => p.text).join('')).toBe(
			'Präsentationskopie, kein eigenständiger Nachweis. Echtheit prüfen: QR-Code scannen oder ' + verify + ' öffnen (Zertifikat-ID ' + VID + ').')
		expect(options.note.filter((p) => p.ltr).map((p) => p.text)).toEqual([verify, VID])

		const blob = new Blob(['%PDF'], { type: 'application/pdf' })
		finish(blob)
		await flush()
		expect(pdfMock.save).toHaveBeenCalledWith(blob, 'Zertifikat-Datenschutz-Grundlagen-2026-06-27.pdf', { pendingWindow: null })
		expect(status(host).textContent).toBe('PDF heruntergeladen')
		expect(button(host).getAttribute('aria-busy')).toBe('false')
		expect(button(host).disabled).toBe(false)
		expect(host.querySelector('[data-test="certificate-pdf-error"]')).toBeNull()
	})

	it('a failure says so and points to Print; nothing is saved', async () => {
		pdfMock.render = vi.fn(async () => { throw new Error('the canvas came back empty') })
		const host = await open()
		button(host).click()
		await flush()
		expect(pdfMock.save).not.toHaveBeenCalled()
		expect(host.querySelector('[data-test="certificate-pdf-error"]').textContent.trim())
			.toBe('PDF konnte nicht erstellt werden. Bitte „Drucken“ verwenden.')
		expect(status(host).textContent).toBe('')
		expect(button(host).disabled).toBe(false)
		// the next try clears the message
		pdfMock.render = vi.fn(async () => new Blob(['%PDF']))
		button(host).click()
		await flush()
		expect(host.querySelector('[data-test="certificate-pdf-error"]')).toBeNull()
	})

	it('where links cannot download, the tab is opened inside the click and closed again on failure', async () => {
		pdfMock.canLink = false
		const tab = { close: vi.fn(), location: { href: '' } }
		const openSpy = vi.spyOn(window, 'open').mockReturnValue(tab)
		let fail
		pdfMock.render = vi.fn(() => new Promise((resolve, reject) => { fail = reject }))
		const host = await open()
		button(host).click()
		// synchronously, before the PDF exists (afterwards a pop-up blocker would stop it)
		expect(openSpy).toHaveBeenCalledWith('', '_blank')
		await flush()
		fail(new Error('x'))
		await flush()
		expect(tab.close).toHaveBeenCalled()
		openSpy.mockRestore()
	})

	it('the real sheet copy is removed when the certificate closes during rendering', async () => {
		const host = await open()
		buildCertificateSheet(host.querySelector('.certificate-printable'))
		expect(document.getElementById(PDF_ROOT_ID)).not.toBeNull()
		view.unmount()
		view = null
		expect(document.getElementById(PDF_ROOT_ID)).toBeNull()
	})
})

describe('Certificate.vue: the status note on the learner\'s own card (GitHub #94, ZB5)', () => {
	let Certificate

	beforeEach(async () => {
		Certificate = (await import('../../src/components/Certificate.vue')).default
		axios.get.mockReset()
	})

	async function note(row, credential = CREDENTIAL) {
		axios.get.mockResolvedValue({ data: { verification_id: VID, credential_json: makeJwt(credential), ...row } })
		const host = document.createElement('div')
		document.body.appendChild(host)
		const app = createApp({ render: () => h(Certificate, { verificationId: VID }) })
		app.config.globalProperties.t = globalThis.t
		app.config.warnHandler = () => {}
		app.mount(host)
		await flush()
		try {
			// inside the printable part: it goes onto paper and into the PDF
			return host.querySelector('.certificate-printable .certificate-status-note')?.textContent.trim() ?? null
		} finally {
			app.unmount()
			host.remove()
		}
	}

	const now = Math.floor(Date.now() / 1000)

	it('expired by the stored expiry date', async () => {
		expect(await note({ expires_at: now - 60 })).toBe('Dieses Zertifikat ist abgelaufen.')
	})

	it('expired by the signed validUntil when the response has no expiry date', async () => {
		expect(await note({ expires_at: null }, { ...CREDENTIAL, validUntil: '2020-01-01T00:00:00Z' })).toBe('Dieses Zertifikat ist abgelaufen.')
	})

	it('valid, or without expiry: no note', async () => {
		expect(await note({ expires_at: now + 3600 })).toBeNull()
		expect(await note({ expires_at: null }, { ...CREDENTIAL, validUntil: undefined })).toBeNull()
	})

	it('the stored expiry date decides over the signed one, as in the instructor report', async () => {
		expect(await note({ expires_at: now + 3600 }, { ...CREDENTIAL, validUntil: '2020-01-01T00:00:00Z' })).toBeNull()
	})

	it('revoked says so as well', async () => {
		expect(await note({ revoked: true })).toBe('Dieses Zertifikat wurde widerrufen und ist nicht mehr gültig.')
	})
})
