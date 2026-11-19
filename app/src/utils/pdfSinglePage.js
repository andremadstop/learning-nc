/**
 * GitHub #94: a PDF with one page and one picture on it, written by hand.
 *
 * The certificate PDF is a raster of the card (see certificatePdf.js), so all a PDF library would
 * do here is wrap one JPEG. This writes PDF 1.4 directly: catalog, page tree, one page whose
 * MediaBox is the paper size, the JPEG as an image XObject (DCTDecode, the JPEG bytes unchanged)
 * drawn over the whole page, and an /Info dictionary. Byte offsets are counted on the bytes that are
 * written, so the cross-reference table is exact (pdfinfo and qpdf --check read it without a warning).
 */

/** A4 landscape in PostScript points (297 × 210 mm). */
export const A4_LANDSCAPE_PT = Object.freeze({ width: 841.89, height: 595.28 })

const encoder = new TextEncoder()

/**
 * Width, height and colour components from the JPEG's frame header (SOF0–SOF15 except DHT/JPG/DAC).
 * @param {Uint8Array} jpeg the JPEG file
 * @return {{width: number, height: number, components: number}}
 */
export function jpegInfo(jpeg) {
	if (!(jpeg instanceof Uint8Array) || jpeg.length < 4 || jpeg[0] !== 0xFF || jpeg[1] !== 0xD8) {
		throw new Error('not a JPEG')
	}
	let i = 2
	while (i + 9 < jpeg.length) {
		if (jpeg[i] !== 0xFF) {
			throw new Error('broken JPEG marker')
		}
		const marker = jpeg[i + 1]
		if (marker === 0xFF) {
			i++
			continue
		}
		const length = (jpeg[i + 2] << 8) | jpeg[i + 3]
		const isFrame = marker >= 0xC0 && marker <= 0xCF && marker !== 0xC4 && marker !== 0xC8 && marker !== 0xCC
		if (isFrame) {
			return {
				height: (jpeg[i + 5] << 8) | jpeg[i + 6],
				width: (jpeg[i + 7] << 8) | jpeg[i + 8],
				components: jpeg[i + 9],
			}
		}
		i += 2 + length
	}
	throw new Error('JPEG without frame header')
}

/**
 * A PDF text string in UTF-16BE with byte order mark, as a hex string: any script, no escaping.
 * @param {string} text the text
 * @return {string} `<FEFF…>`
 */
export function pdfTextString(text) {
	let hex = 'FEFF'
	const value = String(text ?? '')
	for (let i = 0; i < value.length; i++) {
		hex += value.charCodeAt(i).toString(16).toUpperCase().padStart(4, '0')
	}
	return '<' + hex + '>'
}

function pdfDate(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return 'D:' + date.getUTCFullYear() + pad(date.getUTCMonth() + 1) + pad(date.getUTCDate())
		+ pad(date.getUTCHours()) + pad(date.getUTCMinutes()) + pad(date.getUTCSeconds()) + 'Z'
}

function num(value) {
	return String(Math.round(value * 100) / 100)
}

/**
 * Build the PDF.
 * @param {object} options the page
 * @param {Uint8Array} options.jpeg the picture, drawn over the whole page
 * @param {number} [options.widthPt] page width in points (default A4 landscape)
 * @param {number} [options.heightPt] page height in points
 * @param {string} [options.title] document title (/Info /Title)
 * @param {string} [options.subject] document subject (/Info /Subject)
 * @param {string} [options.lang] language tag of the document (/Lang in the catalog)
 * @param {string} [options.producer] /Info /Producer
 * @param {Date} [options.date] creation date
 * @return {Uint8Array} the PDF file
 */
export function buildSinglePagePdf({
	jpeg,
	widthPt = A4_LANDSCAPE_PT.width,
	heightPt = A4_LANDSCAPE_PT.height,
	title = '',
	subject = '',
	lang = '',
	producer = 'Nextcloud Learning',
	date = new Date(),
}) {
	const { width, height, components } = jpegInfo(jpeg)
	const colorSpace = components === 1 ? '/DeviceGray' : components === 4 ? '/DeviceCMYK' : '/DeviceRGB'
	// Adobe CMYK JPEGs are stored inverted; canvas never writes them, but read them right anyway.
	const decode = components === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : ''

	const chunks = []
	let length = 0
	const offsets = []
	const push = (part) => {
		const bytes = typeof part === 'string' ? encoder.encode(part) : part
		chunks.push(bytes)
		length += bytes.length
	}
	const object = (number, body) => {
		offsets[number] = length
		push(number + ' 0 obj\n' + body + '\nendobj\n')
	}

	// the comment line with four bytes above 127 marks the file as binary for transfer tools
	push('%PDF-1.4\n')
	push(new Uint8Array([0x25, 0xE2, 0xE3, 0xCF, 0xD3, 0x0A]))

	object(1, '<< /Type /Catalog /Pages 2 0 R' + (lang ? ' /Lang ' + pdfTextString(lang) : '') + ' >>')
	object(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
	object(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + num(widthPt) + ' ' + num(heightPt) + ']'
		+ ' /Resources << /XObject << /Im0 4 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 5 0 R >>')

	offsets[4] = length
	push('4 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + width + ' /Height ' + height
		+ ' /ColorSpace ' + colorSpace + ' /BitsPerComponent 8' + decode + ' /Filter /DCTDecode /Length ' + jpeg.length + ' >>\nstream\n')
	push(jpeg)
	push('\nendstream\nendobj\n')

	const content = 'q\n' + num(widthPt) + ' 0 0 ' + num(heightPt) + ' 0 0 cm\n/Im0 Do\nQ\n'
	object(5, '<< /Length ' + encoder.encode(content).length + ' >>\nstream\n' + content + 'endstream')
	object(6, '<< /Title ' + pdfTextString(title) + ' /Subject ' + pdfTextString(subject)
		+ ' /Producer ' + pdfTextString(producer) + ' /CreationDate (' + pdfDate(date) + ') >>')

	const xref = length
	let table = 'xref\n0 7\n0000000000 65535 f \n'
	for (let n = 1; n <= 6; n++) {
		table += String(offsets[n]).padStart(10, '0') + ' 00000 n \n'
	}
	push(table)
	push('trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\nstartxref\n' + xref + '\n%%EOF\n')

	const out = new Uint8Array(length)
	let at = 0
	for (const chunk of chunks) {
		out.set(chunk, at)
		at += chunk.length
	}
	return out
}
