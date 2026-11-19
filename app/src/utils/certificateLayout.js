/**
 * GitHub #43: drawing a certificate with the course's layout. Pure helpers, no Vue.
 *
 * The layout is presentation only (CertLayout.php). Every placeholder is filled from the signed
 * credential fields (extractCertificateFields), so the card can never claim more than the
 * credential a verifier checks.
 *
 * Escaping: renderIntro() returns plain text pieces. The card puts them into text nodes
 * ({{ }}), never v-html, so markup typed into the layout or present in a name shows as text.
 */

/**
 * Element switches, all on in the standard layout. QR code and verification link are not here: always shown.
 * `issuer` (GitHub #77) only hides the organisation name beside the logo; the signed credential and
 * the public verify page keep it.
 */
export const SHOW_KEYS = ['logo', 'issuer', 'score', 'threshold', 'elements', 'validity']

/** The placeholders the introduction text understands, in the order the settings list them. */
export const PLACEHOLDERS = [
	'{student.name}',
	'{student.first_name}',
	'{student.last_name}',
	'{course.title}',
	'{exam.date}',
	'{course.elements}',
	'{score}',
	'{valid_until}',
	'{issuer}',
]

/** Placeholders shown in bold, like the name in the standard "Awarded to" line. */
const STRONG = new Set(['{student.name}', '{student.first_name}', '{student.last_name}'])

const ELEMENTS = '{course.elements}'

/**
 * GitHub #93: the backgrounds that ship with the app, drawn in CSS (CertLayout::BACKGROUND_BUILTINS).
 * The id becomes part of a CSS class name, so only these ids are ever used.
 */
export const BACKGROUND_BUILTINS = ['frame', 'corners', 'lines']

/** GitHub #93: how strongly a background shows, 10–100 in steps of 10; 100 = as drawn. */
export const BACKGROUND_INTENSITY_DEFAULT = 100
export const BACKGROUND_INTENSITY_MIN = 10
export const BACKGROUND_INTENSITY_STEP = 10

/**
 * @param {*} value - candidate
 * @return {boolean} whether it is a strength the settings offer
 */
export function isValidIntensity(value) {
	return Number.isInteger(value)
		&& value >= BACKGROUND_INTENSITY_MIN
		&& value <= BACKGROUND_INTENSITY_DEFAULT
		&& value % BACKGROUND_INTENSITY_STEP === 0
}

/**
 * The layout a certificate is drawn with. null / missing (every certificate issued before 5.13.0,
 * and courses with the standard layout) give the standard layout.
 *
 * @param {object|null} presentation - from the show response (or the settings preview)
 * @return {{intro: string, show: Object<string, boolean>, signerName: string, signerRole: string, stamp: string, signature: string, logo: string, logoLibrary: boolean, background: string, backgroundBuiltin: string, backgroundIntensity: number}}
 */
export function normalizePresentation(presentation) {
	const p = presentation && typeof presentation === 'object' ? presentation : {}
	const show = {}
	for (const key of SHOW_KEYS) {
		show[key] = p.show?.[key] !== false
	}
	return {
		intro: typeof p.intro === 'string' ? p.intro : '',
		show,
		signerName: typeof p.signer_name === 'string' ? p.signer_name : '',
		signerRole: typeof p.signer_role === 'string' ? p.signer_role : '',
		stamp: isPngDataUrl(p.stamp) ? p.stamp : '',
		signature: isPngDataUrl(p.signature) ? p.signature : '',
		// GitHub #78: the library logo the certificate was issued with, else its copy of the Theming
		// logo of that moment; '' = the live Theming logo (certificates issued before 5.14.0)
		logo: isLogoDataUrl(p.logo) ? p.logo : '',
		// only a library logo gets the white plate on the dark-mode card; the Theming logo keeps its look.
		// Older responses (settings preview) have no flag: a logo there is always a library logo.
		logoLibrary: isLogoDataUrl(p.logo) && p.logo_library !== false,
		// GitHub #93: a library background as a PNG/JPEG/WebP data URL, else a built-in one by its
		// whitelisted id; '' for both = no background (every certificate before 5.17.0)
		...normalizeBackground(p),
	}
}

/**
 * @param {object} p - presentation
 * @return {{background: string, backgroundBuiltin: string, backgroundIntensity: number}}
 */
function normalizeBackground(p) {
	const background = isLogoDataUrl(p.background) ? p.background : ''
	const backgroundBuiltin = !background && BACKGROUND_BUILTINS.includes(p.background_builtin) ? p.background_builtin : ''
	const backgroundIntensity = (background || backgroundBuiltin) && isValidIntensity(p.background_intensity)
		? p.background_intensity
		: BACKGROUND_INTENSITY_DEFAULT
	return { background, backgroundBuiltin, backgroundIntensity }
}

/**
 * GitHub #78: a library logo comes as a PNG, JPEG or WebP data URL (never SVG); anything else
 * is not put into an img src.
 * @param {*} value - candidate
 * @return {boolean}
 */
export function isLogoDataUrl(value) {
	return typeof value === 'string' && /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(value)
}

/**
 * Only PNG data URLs come from the server; anything else is not put into an img src.
 * @param {*} value - candidate
 * @return {boolean}
 */
function isPngDataUrl(value) {
	return typeof value === 'string' && /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(value)
}

/**
 * Split the signed display name into first and last name. The credential carries only the display
 * name (DSGVO), so this is a rule of thumb: the first word is the first name, the rest the last
 * name. A one-word name is the first name and the last name stays empty.
 *
 * @param {string} displayName - signed recipient name
 * @return {{first: string, last: string}}
 */
export function splitName(displayName) {
	const words = String(displayName || '').trim().split(/\s+/).filter(Boolean)
	if (words.length === 0) {
		return { first: '', last: '' }
	}
	return { first: words[0], last: words.slice(1).join(' ') }
}

/**
 * The value of every placeholder for one certificate.
 *
 * @param {object} fields - extractCertificateFields() result
 * @param {object} format - { issuedDate, expiryDate, noExpiry } already formatted for the reader
 * @return {Object<string, string|string[]>}
 */
export function placeholderValues(fields, format = {}) {
	const f = fields || {}
	const { first, last } = splitName(f.recipientName)
	return {
		'{student.name}': f.recipientName || '',
		'{student.first_name}': first,
		'{student.last_name}': last,
		'{course.title}': f.courseTitle || '',
		'{exam.date}': format.issuedDate || '',
		'{course.elements}': Array.isArray(f.courseElements) ? f.courseElements : [],
		'{score}': f.score !== null && f.score !== undefined ? `${f.score}%` : '',
		'{valid_until}': format.expiryDate || format.noExpiry || '',
		'{issuer}': f.issuerName || '',
	}
}

/**
 * Turn the introduction text into blocks to draw.
 *
 * - Each line is a paragraph of text pieces; placeholders are replaced, unknown ones stay as typed.
 * - A line that holds nothing but {course.elements} becomes a list (one pool per line, as in the
 *   reporter's example); without elements the line is dropped. Inside a sentence the elements are
 *   joined with ", ".
 * - Empty lines are kept as spacing.
 *
 * @param {string} template - the layout's intro text
 * @param {Object<string, string|string[]>} values - placeholderValues()
 * @return {Array<{type: 'text', parts: Array<{text: string, strong: boolean}>}|{type: 'list', items: string[]}|{type: 'gap'}>}
 */
export function renderIntro(template, values) {
	const text = String(template || '').replace(/\r\n?/g, '\n')
	if (text.trim() === '') {
		return []
	}
	const blocks = []
	for (const line of text.split('\n')) {
		if (line.trim() === ELEMENTS) {
			const items = Array.isArray(values[ELEMENTS]) ? values[ELEMENTS] : []
			if (items.length) {
				blocks.push({ type: 'list', items: [...items] })
			}
			continue
		}
		if (line.trim() === '') {
			blocks.push({ type: 'gap' })
			continue
		}
		blocks.push({ type: 'text', parts: substitute(line, values) })
	}
	return blocks
}

/**
 * @param {string} line - one line of the template
 * @param {Object<string, string|string[]>} values - placeholder values
 * @return {Array<{text: string, strong: boolean}>}
 */
function substitute(line, values) {
	const parts = []
	const pattern = /\{[a-z_.]+\}/g
	let last = 0
	let match
	while ((match = pattern.exec(line)) !== null) {
		const token = match[0]
		if (!Object.prototype.hasOwnProperty.call(values, token)) {
			continue
		}
		if (match.index > last) {
			parts.push({ text: line.slice(last, match.index), strong: false })
		}
		const value = values[token]
		const textValue = Array.isArray(value) ? value.join(', ') : String(value ?? '')
		if (textValue !== '') {
			parts.push({ text: textValue, strong: STRONG.has(token) })
		}
		last = match.index + token.length
	}
	if (last < line.length) {
		parts.push({ text: line.slice(last), strong: false })
	}
	return parts
}
