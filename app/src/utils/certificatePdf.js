/**
 * GitHub #94: the certificate as a PDF, made in the browser.
 *
 * The PDF is a picture of the printed sheet, not of the card on screen: the card is copied into an
 * A4 landscape sheet (`.certificate-sheet`, the same rules the print uses, see Certificate.vue) that
 * sits outside the viewport, the browser itself draws that sheet into a canvas (modern-screenshot:
 * SVG foreignObject, so Arabic, Cyrillic and right-to-left text look exactly as on screen), the
 * canvas becomes a JPEG and pdfSinglePage.js wraps it in a one-page PDF.
 *
 * Only the PDF carries a line saying that it is a presentation copy and how to check it; the paper
 * print does not. The copy is never shown and never read out (aria-hidden, inert) and is removed
 * when the PDF is done, when it fails and when the certificate closes.
 *
 * Nothing is loaded from another host: the card's images are data URLs, and only fonts served by
 * this Nextcloud are embedded. A canvas that comes back empty (some phones give up silently above
 * their canvas limit) is tried once more at a lower resolution and otherwise reported as an error:
 * a blank PDF must never look like a successful download.
 */
import { A4_LANDSCAPE_PT, buildSinglePagePdf } from './pdfSinglePage.js'

export const PDF_ROOT_ID = 'learning-certificate-pdf-root'
export const SHEET_CLASS = 'certificate-sheet'

/** The sheet in CSS pixels: 297 × 210 mm at 96 px per inch, rounded so the raster has no seam. */
export const SHEET_PX = Object.freeze({ width: 1123, height: 794 })

/** ≈ 200 dpi (2339 × 1654 px, under 4 megapixels), then 144 dpi if the canvas fails. */
export const PDF_SCALES = Object.freeze([2339 / SHEET_PX.width, 1.5])

const JPEG_QUALITY = 0.9

/**
 * Remove the PDF copy. Safe to call any number of times.
 * @param {Document} doc the document
 */
export function removeCertificatePdfCopy(doc = document) {
	doc.getElementById(PDF_ROOT_ID)?.remove()
}

/**
 * Split a translated sentence at {id} and {url}, so the two can be put into left-to-right isolates
 * (a UUID and a link inside Arabic text would otherwise be reordered).
 * @param {string} text the translated sentence with {id} and {url} left in
 * @param {{id: string, url: string}} values what goes in
 * @return {Array<{text: string, ltr: boolean}>}
 */
export function noteParts(text, values) {
	const parts = []
	const pattern = /\{(id|url)\}/g
	let last = 0
	let match
	while ((match = pattern.exec(text)) !== null) {
		if (match.index > last) {
			parts.push({ text: text.slice(last, match.index), ltr: false })
		}
		parts.push({ text: String(values[match[1]] ?? ''), ltr: true })
		last = match.index + match[0].length
	}
	if (last < text.length) {
		parts.push({ text: text.slice(last), ltr: false })
	}
	return parts
}

/**
 * A file name part from free text: letters (with their marks) and digits of every script, anything
 * else becomes "-", at most `max` characters.
 * @param {string} text the text
 * @param {number} max the longest result
 * @return {string}
 */
export function slugPart(text, max = 60) {
	const slug = String(text ?? '')
		.normalize('NFKC')
		.replace(/[^\p{L}\p{M}\p{N}]+/gu, '-')
		.replace(/^-+|-+$/g, '')
	// cut by code points, not UTF-16 units, and never end on a dash
	return Array.from(slug).slice(0, max).join('').replace(/-+$/, '')
}

/**
 * `{prefix}-{course}-{YYYY-MM-DD}.pdf`; an empty course leaves it out, a missing date as well.
 * @param {object} parts the parts
 * @param {string} parts.prefix the translated word "Certificate"
 * @param {string} parts.courseTitle the course title of the certificate
 * @param {string} [parts.validFrom] the issue date (ISO 8601)
 * @return {string}
 */
export function certificatePdfFileName({ prefix, courseTitle, validFrom }) {
	const pieces = [slugPart(prefix, 40) || 'certificate']
	const course = slugPart(courseTitle)
	if (course) {
		pieces.push(course)
	}
	const date = validFrom ? new Date(validFrom) : null
	if (date && !Number.isNaN(date.getTime())) {
		const pad = (n) => String(n).padStart(2, '0')
		pieces.push(date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()))
	}
	return pieces.join('-') + '.pdf'
}

/**
 * Copy the printable certificate into an A4 sheet outside the viewport.
 * @param {HTMLElement} source the printable certificate element (`.certificate-printable`)
 * @param {object} options the sheet
 * @param {Array<{text: string, ltr: boolean}>} options.note the presentation-copy line
 * @param {string} [options.dir] text direction of the interface
 * @param {string} [options.lang] language of the interface
 * @param {Document} [options.doc] the document
 * @return {HTMLElement} the sheet
 */
export function buildCertificateSheet(source, { note = [], dir = '', lang = '', doc = document } = {}) {
	removeCertificatePdfCopy(doc)
	const root = doc.createElement('div')
	root.id = PDF_ROOT_ID
	root.className = SHEET_CLASS + ' ' + SHEET_CLASS + '--pdf'
	root.setAttribute('aria-hidden', 'true')
	root.setAttribute('inert', '')
	if (dir) {
		root.setAttribute('dir', dir)
	}
	if (lang) {
		root.setAttribute('lang', lang)
	}
	root.appendChild(source.cloneNode(true))

	const line = doc.createElement('p')
	line.className = 'certificate-pdf-note'
	line.setAttribute('data-test', 'certificate-pdf-note')
	for (const part of note) {
		if (part.ltr) {
			const isolate = doc.createElement('bdi')
			isolate.setAttribute('dir', 'ltr')
			isolate.textContent = part.text
			line.appendChild(isolate)
		} else {
			line.appendChild(doc.createTextNode(part.text))
		}
	}
	root.appendChild(line)
	doc.body.appendChild(root)
	return root
}

/**
 * The @font-face rules of this page whose files all come from this origin (or are data URLs).
 * Only those are embedded, so making the PDF never asks another host for a font.
 * @param {Document} doc the document
 * @return {string} CSS text, empty when there are none
 */
export function sameOriginFontCss(doc = document) {
	const origin = doc.defaultView?.location?.origin ?? ''
	const rules = []
	for (const sheet of Array.from(doc.styleSheets ?? [])) {
		let list
		try {
			list = sheet.cssRules
		} catch (e) {
			continue // a stylesheet from another origin: its rules are not readable, and not wanted
		}
		for (const rule of Array.from(list ?? [])) {
			if (rule.constructor?.name !== 'CSSFontFaceRule' && rule.type !== 5) {
				continue
			}
			const base = sheet.href || doc.baseURI
			const urls = Array.from(rule.cssText.matchAll(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g), (m) => m[2])
			const local = urls.every((url) => {
				if (url.startsWith('data:')) {
					return true
				}
				try {
					return new URL(url, base).origin === origin
				} catch (e) {
					return false
				}
			})
			if (urls.length > 0 && local) {
				// make relative URLs absolute: the rule is moved out of its stylesheet
				rules.push(rule.cssText.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (m, q, url) => (url.startsWith('data:')
					? m
					: 'url("' + new URL(url, base).href + '")')))
			}
		}
	}
	return rules.join('\n')
}

/**
 * Whether the canvas really holds the sheet: not one flat colour, and dark pixels where the QR code is.
 * @param {HTMLCanvasElement} canvas the raster
 * @param {{x: number, y: number, width: number, height: number}|null} qr the QR code in canvas pixels
 * @return {boolean}
 */
export function canvasLooksDrawn(canvas, qr) {
	const ctx = canvas.getContext('2d', { willReadFrequently: true })
	if (!ctx || canvas.width === 0 || canvas.height === 0) {
		return false
	}
	const dark = (x, y, w, h) => {
		const data = ctx.getImageData(Math.max(0, Math.floor(x)), Math.max(0, Math.floor(y)), Math.max(1, Math.floor(w)), Math.max(1, Math.floor(h))).data
		let count = 0
		for (let i = 0; i < data.length; i += 4) {
			if (data[i] < 96 && data[i + 1] < 96 && data[i + 2] < 96) {
				count++
			}
		}
		return count
	}
	if (qr && qr.width > 0 && qr.height > 0) {
		return dark(qr.x, qr.y, qr.width, qr.height) > 0
	}
	return dark(0, 0, canvas.width, canvas.height) > 0
}

function canvasToJpeg(canvas) {
	return new Promise((resolve, reject) => {
		try {
			canvas.toBlob((blob) => {
				if (!blob || blob.size === 0) {
					reject(new Error('empty JPEG'))
					return
				}
				blob.arrayBuffer().then((buffer) => resolve(new Uint8Array(buffer)), reject)
			}, 'image/jpeg', JPEG_QUALITY)
		} catch (e) {
			reject(e)
		}
	})
}

function qrBox(root, scale) {
	const qr = root.querySelector('.certificate-qr')
	if (!qr) {
		return null
	}
	const a = root.getBoundingClientRect()
	const b = qr.getBoundingClientRect()
	return { x: (b.left - a.left) * scale, y: (b.top - a.top) * scale, width: b.width * scale, height: b.height * scale }
}

/**
 * Make the PDF of a certificate.
 * @param {HTMLElement|null} source the printable certificate element (`.certificate-printable`)
 * @param {object} options the document
 * @param {Array<{text: string, ltr: boolean}>} options.note the presentation-copy line
 * @param {string} [options.title] document title (the course)
 * @param {string} [options.subject] document subject (the verification link)
 * @param {string} [options.dir] text direction of the interface
 * @param {string} [options.lang] language of the interface
 * @param {Document} [options.doc] the document
 * @param {Function} [options.rasterize] (sheet, options) => canvas; modern-screenshot by default
 * @return {Promise<Blob>} the PDF
 */
export async function renderCertificatePdf(source, { note = [], title = '', subject = '', dir = '', lang = '', doc = document, rasterize = null } = {}) {
	if (!source) {
		throw new Error('no certificate to render')
	}
	const root = buildCertificateSheet(source, { note, dir, lang, doc })
	try {
		await Promise.all(Array.from(root.querySelectorAll('img')).map((img) => (typeof img.decode === 'function'
			? img.decode().catch(() => {})
			: Promise.resolve())))
		const draw = rasterize || (await import('modern-screenshot')).domToCanvas
		const origin = doc.defaultView?.location?.origin ?? ''
		const fontCss = sameOriginFontCss(doc)
		let lastError = null
		for (const scale of PDF_SCALES) {
			try {
				const canvas = await draw(root, {
					width: SHEET_PX.width,
					height: SHEET_PX.height,
					scale,
					backgroundColor: '#ffffff',
					// the copy sits outside the viewport; in the picture it starts at 0,0
					style: { position: 'relative', left: '0', top: '0', transform: 'none', margin: '0' },
					font: fontCss ? { cssText: fontCss } : false,
					// images on the card are data URLs; anything else from another host is left out
					fetchFn: async (url) => {
						try {
							return new URL(url, doc.baseURI).origin === origin ? false : 'data:,'
						} catch (e) {
							return 'data:,'
						}
					},
					timeout: 15000,
				})
				if (!canvasLooksDrawn(canvas, qrBox(root, canvas.width / SHEET_PX.width))) {
					throw new Error('the canvas came back empty')
				}
				const jpeg = await canvasToJpeg(canvas)
				const pdf = buildSinglePagePdf({
					jpeg,
					widthPt: A4_LANDSCAPE_PT.width,
					heightPt: A4_LANDSCAPE_PT.height,
					title,
					subject,
					lang,
				})
				return new Blob([pdf], { type: 'application/pdf' })
			} catch (e) {
				lastError = e
			}
		}
		throw lastError || new Error('the PDF could not be made')
	} finally {
		removeCertificatePdfCopy(doc)
	}
}

/** Whether links can save a file under a name (old iOS web views cannot). */
export function canDownloadByLink(doc = document) {
	return 'download' in doc.createElement('a')
}

/**
 * Hand the PDF to the user: a download with the file name, or, where links cannot download, the
 * PDF in the tab that was opened at the click (a tab opened later is blocked as a pop-up).
 * @param {Blob} blob the PDF
 * @param {string} fileName the file name
 * @param {object} [options] where
 * @param {Window|null} [options.pendingWindow] the tab opened at the click, if links cannot download
 * @param {Window} [options.win] the window
 * @return {string} how: 'download' or 'tab'
 */
export function savePdf(blob, fileName, { pendingWindow = null, win = window } = {}) {
	const url = win.URL.createObjectURL(blob)
	// the browser has the file once it starts saving or showing it; give it time before freeing it
	const release = () => win.setTimeout(() => win.URL.revokeObjectURL(url), 60_000)
	if (pendingWindow) {
		pendingWindow.location.href = url
		release()
		return 'tab'
	}
	const doc = win.document
	const link = doc.createElement('a')
	link.href = url
	link.download = fileName
	link.rel = 'noopener'
	link.style.display = 'none'
	doc.body.appendChild(link)
	link.click()
	link.remove()
	release()
	return 'download'
}
