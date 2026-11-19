/**
 * Printing a certificate (GitHub #41).
 *
 * The certificate is only ever shown inside an NcModal. The modal container is
 * `position: relative; overflow: auto` with a max-height inside a `position: fixed` mask, so
 * printing the card where it sits clips it to the modal box: the sheet came out almost empty,
 * with a few pixels of the logo. Hiding the rest with `visibility` cannot undo an ancestor's
 * clipping.
 *
 * So the card is copied to a node directly under <body>, the document is flagged with a class,
 * and the print stylesheet in Certificate.vue shows only that node while the flag is set. The
 * copy keeps the scoped-style attributes, so the card looks the same. It is removed on
 * `afterprint`, on the next print and when the component goes away.
 *
 * Ctrl+P and the browser's print menu never go through the Print button, so while the
 * certificate is open a `beforeprint` listener builds the same copy synchronously (there is no
 * time to wait for images there; they are data URLs and already decoded on screen).
 */

export const PRINT_ROOT_ID = 'learning-certificate-print-root'
export const PRINTING_CLASS = 'learning-certificate-printing'

/** How long to wait for the copied images (logo, QR) before printing anyway. */
const IMAGE_WAIT_MS = 1500

/**
 * Remove the print copy and the document flag. Safe to call any number of times.
 * @param {Document} doc the document
 */
export function removeCertificatePrintCopy(doc = document) {
	const root = doc.getElementById(PRINT_ROOT_ID)
	if (root) {
		root.remove()
	}
	doc.documentElement.classList.remove(PRINTING_CLASS)
}

/**
 * Copy the card under <body> and flag the document, replacing any earlier copy.
 * @param {HTMLElement} source the printable certificate element
 * @param {Document} doc the document
 * @return {HTMLElement} the print root
 */
function buildCertificatePrintCopy(source, doc) {
	removeCertificatePrintCopy(doc)
	const root = doc.createElement('div')
	root.id = PRINT_ROOT_ID
	// GitHub #94: the sheet rules (A4 grid, paper colours) are shared with the PDF copy
	root.className = 'certificate-sheet'
	root.appendChild(source.cloneNode(true))
	doc.body.appendChild(root)
	doc.documentElement.classList.add(PRINTING_CLASS)
	return root
}

function waitForImages(root) {
	const images = Array.from(root.querySelectorAll('img'))
	const decoded = images.map((img) => (typeof img.decode === 'function'
		? img.decode().catch(() => {})
		: Promise.resolve()))
	return Promise.race([
		Promise.all(decoded),
		new Promise((resolve) => setTimeout(resolve, IMAGE_WAIT_MS)),
	])
}

/**
 * Print the given certificate element on its own.
 * @param {HTMLElement|null} source the printable certificate element (`.certificate-printable`)
 * @param {Window} win the window to print
 * @return {Promise<boolean>} false when there was nothing to print
 */
export async function printCertificate(source, win = window) {
	const doc = win.document
	removeCertificatePrintCopy(doc)
	if (!source) {
		return false
	}

	const root = buildCertificatePrintCopy(source, doc)

	await waitForImages(root)

	const cleanup = () => {
		win.removeEventListener('afterprint', cleanup)
		removeCertificatePrintCopy(doc)
	}
	win.addEventListener('afterprint', cleanup)
	win.print()
	return true
}

/**
 * Make printing from the browser itself (Ctrl+P, print menu) print the certificate too.
 * When the Print button already built the copy, `beforeprint` leaves it alone.
 * @param {function(): (HTMLElement|null)} getSource returns the printable element, or null
 *   while the certificate is not shown (then the page prints as usual)
 * @param {Window} win the window
 * @return {function(): void} removes the listeners
 */
export function installCertificatePrintHook(getSource, win = window) {
	const doc = win.document
	const before = () => {
		if (doc.getElementById(PRINT_ROOT_ID)) {
			return
		}
		const source = getSource()
		if (source) {
			buildCertificatePrintCopy(source, doc)
		}
	}
	const after = () => removeCertificatePrintCopy(doc)
	win.addEventListener('beforeprint', before)
	win.addEventListener('afterprint', after)
	return () => {
		win.removeEventListener('beforeprint', before)
		win.removeEventListener('afterprint', after)
	}
}
