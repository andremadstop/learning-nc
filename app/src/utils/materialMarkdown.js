/**
 * GitHub #110: Markdown course materials rendered for reading, inside Learning.
 *
 * The server keeps serving the file as text/plain with nosniff (DocumentController::download), so
 * an uploaded file never becomes an HTML page of this origin. This module turns that text into
 * HTML for the reader, in two layers:
 *
 * 1. markdown-it with `html: false`: raw HTML in the file is shown as text, never parsed. Its
 *    default validateLink refuses javascript:, vbscript:, file: and data: links (except raster
 *    data images).
 * 2. DOMPurify on the result, with an allowlist of the tags markdown produces and two hooks:
 *    - links: only http, https, mailto and in-page anchors; external ones open in a new tab with
 *      rel="noopener noreferrer". Anything else (a relative path such as `other.md`, which would
 *      resolve against the app URL) loses its href and stays as text.
 *    - images: only `data:image/(png|jpeg|gif|webp);base64,…` (embedded screenshots). A remote
 *      image would contact a third party when a learner opens a material (privacy, and the
 *      Nextcloud CSP blocks it anyway); a relative path resolves against the app URL. Both are
 *      replaced by their alt text.
 */
import MarkdownIt from 'markdown-it'
import DOMPurify from 'dompurify'

/** Above this size the text is offered as plain text instead of rendered (a frozen tab helps nobody). */
export const MAX_RENDER_BYTES = 8 * 1024 * 1024

const SAFE_IMAGE = /^data:image\/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$/i
const SAFE_LINK = /^(?:https?:|mailto:)/i

const ALLOWED_TAGS = [
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'blockquote',
	'ul', 'ol', 'li', 'strong', 'em', 's', 'code', 'pre', 'a', 'img',
	'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span',
]
const ALLOWED_ATTR = ['href', 'title', 'src', 'alt', 'start', 'style', 'target', 'rel', 'dir']

let md = null
function parser() {
	if (md === null) {
		md = new MarkdownIt({ html: false, linkify: true, typographer: false, breaks: false })
	}
	return md
}

/**
 * markdown-it writes table alignment as style="text-align:…"; nothing else may carry a style.
 *
 * @param {Element} node - element being sanitized
 */
function keepOnlyTextAlign(node) {
	if (!node.hasAttribute('style')) return
	const match = /^\s*text-align\s*:\s*(left|right|center)\s*;?\s*$/i.exec(node.getAttribute('style'))
	if (match && (node.tagName === 'TH' || node.tagName === 'TD')) {
		node.setAttribute('style', 'text-align:' + match[1].toLowerCase())
	} else {
		node.removeAttribute('style')
	}
}

function afterSanitizeAttributes(node) {
	keepOnlyTextAlign(node)
	if (node.tagName === 'A') {
		const href = (node.getAttribute('href') || '').trim()
		if (href.startsWith('#')) {
			node.removeAttribute('target')
			node.removeAttribute('rel')
		} else if (SAFE_LINK.test(href)) {
			node.setAttribute('target', '_blank')
			node.setAttribute('rel', 'noopener noreferrer')
		} else {
			node.removeAttribute('href')
			node.removeAttribute('target')
			node.removeAttribute('rel')
		}
	}
	if (node.tagName === 'IMG') {
		const src = (node.getAttribute('src') || '').trim()
		if (!SAFE_IMAGE.test(src)) {
			const alt = node.getAttribute('alt') || ''
			// plain text: the replacement is walked by DOMPurify too, so it carries no attributes
			node.replaceWith(node.ownerDocument.createTextNode(alt ? '[' + alt + ']' : ''))
		}
	}
}

/**
 * Markdown source → sanitized HTML for v-html.
 *
 * @param {string} source - the material file's text
 * @return {string}
 */
export function renderMaterialMarkdown(source) {
	const html = parser().render(String(source ?? ''))
	DOMPurify.addHook('afterSanitizeAttributes', afterSanitizeAttributes)
	try {
		return DOMPurify.sanitize(html, {
			ALLOWED_TAGS,
			ALLOWED_ATTR,
			ALLOW_DATA_ATTR: false,
			// data: only for images; the hook narrows it to raster base64
			ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|#|data:image\/(?:png|jpe?g|gif|webp);base64,)/i,
		})
	} finally {
		DOMPurify.removeHook('afterSanitizeAttributes')
	}
}

/**
 * Whether the reader should render this text or offer it as plain text.
 *
 * @param {string} source - the material file's text
 * @return {boolean}
 */
export function tooLargeToRender(source) {
	return new Blob([String(source ?? '')]).size > MAX_RENDER_BYTES
}
