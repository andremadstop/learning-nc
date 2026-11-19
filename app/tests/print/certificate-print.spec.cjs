// GitHub #80: "Certificate is sized at about 16×12.5 cm on a printed piece of paper."
// The real Certificate.vue (harness.js) is printed to PDF the way Chrome's print-to-PDF does it, and
// the PDF itself is measured: paper size and orientation, one page, the certificate filling the page
// width, and nothing of the Nextcloud page around it on the paper.
const { test, expect } = require('@playwright/test')
const { execFileSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { PNG } = require('pngjs')

const PT_PER_MM = 72 / 25.4

function b64url(value) {
	return Buffer.from(JSON.stringify(value)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** An image of the given size with a dark frame, as a PNG data URL (stamp / signature stand-in). */
function pngDataUrl(width, height) {
	const png = new PNG({ width, height })
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			const i = (width * y + x) << 2
			const edge = x < 3 || y < 3 || x >= width - 3 || y >= height - 3
			png.data[i] = edge ? 30 : 255
			png.data[i + 1] = edge ? 30 : 255
			png.data[i + 2] = edge ? 120 : 255
			png.data[i + 3] = 255
		}
	}
	return 'data:image/png;base64,' + PNG.sync.write(png).toString('base64')
}

/** A wide wordmark logo (1200 × 320), the shape most likely to push the header wider than the page. */
const WORDMARK = pngDataUrl(1200, 320)

const credential = {
	issuer: { id: 'did:web:example.org', name: 'Training Solutions LTD', image: { id: WORDMARK, type: 'Image' } },
	validFrom: '2026-11-16T10:00:00Z',
	validUntil: '2027-11-16T10:00:00Z',
	credentialSubject: {
		name: 'Alex Example',
		achievement: {
			name: 'Annual data protection and IT security training',
			alignment: ['Data protection basics', 'Phishing awareness', 'Password policy', 'Incident reporting']
				.map((title, i) => ({ type: ['Alignment'], targetType: 'ext:LearningRequiredPool', targetName: title, targetUrl: 'urn:learning:pool:' + i })),
		},
		result: [{ type: ['Result'], resultDescription: 'score:92; threshold:75' }],
	},
}

/**
 * The fullest layout the settings allow in practice: text, elements, stamp, signature, signer, and a
 * library logo with the organisation name beside it (GitHub #77/#78).
 */
const presentation = {
	intro: 'This is to certify that\n{student.name}\nhas successfully completed the annual mandatory training\n{course.title}\nwith the following course elements:\n{course.elements}\nThe training was carried out in accordance with the internal training policy.',
	show: { logo: true, issuer: true, score: true, threshold: true, elements: true, validity: true },
	signer_name: 'Dr. Maria Example',
	signer_role: 'Head of Training',
	stamp: pngDataUrl(300, 300),
	signature: pngDataUrl(440, 120),
	logo: WORDMARK,
	logo_library: true,
}

async function openAndPrint(page, { query = '', revoked = false, extra = {} } = {}) {
	await page.addInitScript(() => {
		window.__printed = 0
		window.print = () => { window.__printed++ }
	})
	await page.route('**/certificates/**', (route) => route.fulfill({
		contentType: 'application/json',
		body: JSON.stringify({
			verification_id: '11111111-2222-4333-8444-555555555555',
			credential_json: b64url({ alg: 'EdDSA' }) + '.' + b64url(credential) + '.sig',
			presentation: { ...presentation, ...extra },
			revoked,
		}),
	}))
	await page.goto('/index.html' + query)
	await page.locator('.certificate-printable .certificate-card').waitFor()
	await page.getByRole('button', { name: 'Drucken' }).click()
	await expect.poll(() => page.evaluate(() => window.__printed)).toBe(1)
	await page.emulateMedia({ media: 'print' })
}

/** Paper size (pt), page count, text and the inked area of page 1 as fractions of the page. */
function measure(pdf) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'learning-print-'))
	try {
		const file = path.join(dir, 'certificate.pdf')
		fs.writeFileSync(file, pdf)
		const info = execFileSync('pdfinfo', [file], { encoding: 'utf8' })
		const size = info.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/)
		const pages = Number(info.match(/Pages:\s+(\d+)/)[1])
		const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8' })
		execFileSync('pdftoppm', ['-r', '60', '-f', '1', '-l', '1', '-png', file, path.join(dir, 'page')])
		const shot = fs.readdirSync(dir).find((name) => name.endsWith('.png'))
		const png = PNG.sync.read(fs.readFileSync(path.join(dir, shot)))
		let minX = png.width; let maxX = -1; let minY = png.height; let maxY = -1
		for (let y = 0; y < png.height; y++) {
			for (let x = 0; x < png.width; x++) {
				const i = (png.width * y + x) << 2
				if (png.data[i] < 245 || png.data[i + 1] < 245 || png.data[i + 2] < 245) {
					minX = Math.min(minX, x); maxX = Math.max(maxX, x)
					minY = Math.min(minY, y); maxY = Math.max(maxY, y)
				}
			}
		}
		return {
			widthPt: Number(size[1]),
			heightPt: Number(size[2]),
			pages,
			text,
			inkWidth: maxX < 0 ? 0 : (maxX - minX + 1) / png.width,
			inkHeight: maxY < 0 ? 0 : (maxY - minY + 1) / png.height,
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

test('the certificate prints on one A4 landscape page and fills its width', async ({ page }) => {
	await openAndPrint(page)
	// the wide wordmark is on the card, so its size is part of what is measured
	await expect(page.locator('#learning-certificate-print-root [data-test="certificate-logo"]')).toHaveAttribute('src', WORDMARK)
	// what Chrome's print dialog uses: the page size the stylesheet asks for
	const m = measure(await page.pdf({ preferCSSPageSize: true, printBackground: true }))
	expect(Math.round(m.widthPt)).toBe(Math.round(297 * PT_PER_MM))
	expect(Math.round(m.heightPt)).toBe(Math.round(210 * PT_PER_MM))
	expect(m.pages).toBe(1)
	expect(m.inkWidth).toBeGreaterThanOrEqual(0.85)
	expect(m.inkHeight).toBeGreaterThanOrEqual(0.6)
	expect(m.text).toContain('Annual data protection and IT security training')
	expect(m.text).toContain('Alex Example')
	expect(m.text).toContain('Training Solutions LTD')
	expect(m.text).not.toContain('Nextcloud header')
	expect(m.text).not.toContain('Navigation')
	expect(m.text).not.toContain('Drucken')
})

test('a printer set to Letter portrait still gets the full width on one page', async ({ page }) => {
	await openAndPrint(page)
	// Chrome keeps the stylesheet's orientation; Firefox lets the print dialog's paper and
	// orientation win. That case: a later rule turns the certificate's page into Letter portrait.
	await page.addStyleTag({ content: '@page learning-cert { size: letter portrait; }' })
	const m = measure(await page.pdf({ preferCSSPageSize: true, printBackground: true }))
	expect(Math.round(m.widthPt)).toBe(612)
	expect(m.pages).toBe(1)
	expect(m.inkWidth).toBeGreaterThanOrEqual(0.85)
})

test('Letter landscape fits one page as well', async ({ page }) => {
	await openAndPrint(page)
	const m = measure(await page.pdf({ format: 'Letter', landscape: true, printBackground: true }))
	expect(Math.round(m.widthPt)).toBe(792)
	expect(m.pages).toBe(1)
	expect(m.inkWidth).toBeGreaterThanOrEqual(0.85)
})

test('the instructor copy of a revoked certificate still prints on one page, note included', async ({ page }) => {
	await openAndPrint(page, { query: '?course=5', revoked: true })
	const m = measure(await page.pdf({ preferCSSPageSize: true, printBackground: true }))
	expect(m.pages).toBe(1)
	expect(m.inkWidth).toBeGreaterThanOrEqual(0.85)
	expect(m.text).toContain('widerrufen')
})

// GitHub #94: the sheet rules moved from the print root's id to the class .certificate-sheet, which the
// PDF copy shares. They must still beat the card's own scoped rules on paper: measured, not assumed.
test('on paper the sheet sizes win over the screen card: QR 22 mm, its plate 2 mm, logo and stamp in mm', async ({ page }) => {
	await openAndPrint(page, { extra: { background_builtin: 'frame', background_intensity: 100 } })
	const px = (mm) => mm * 96 / 25.4
	const sizes = await page.evaluate(() => {
		const root = document.getElementById('learning-certificate-print-root')
		const style = (selector) => getComputedStyle(root.querySelector(selector))
		const qr = root.querySelector('.certificate-qr').getBoundingClientRect()
		return {
			rootIsSheet: root.classList.contains('certificate-sheet'),
			qrWidth: qr.width,
			qrPadding: parseFloat(style('.certificate-qr').paddingTop),
			logoMaxHeight: parseFloat(style('.certificate-logo').maxHeight),
			stampMaxHeight: parseFloat(style('.certificate-stamp').maxHeight),
			cardDisplay: style('.certificate-card').display,
		}
	})
	expect(sizes.rootIsSheet).toBe(true)
	expect(sizes.cardDisplay).toBe('grid')
	// 22 mm of QR code plus 2 mm of white plate on each side (content-box)
	expect(sizes.qrWidth).toBeCloseTo(px(22 + 4), 0)
	expect(sizes.qrPadding).toBeCloseTo(px(2), 1)
	expect(sizes.logoMaxHeight).toBeCloseTo(px(16), 1)
	expect(sizes.stampMaxHeight).toBeCloseTo(px(18), 1)
})
