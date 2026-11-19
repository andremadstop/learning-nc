// GitHub #94: "Als PDF herunterladen". The real Certificate.vue (harness.js) makes the PDF in the
// browser, the download is caught and the file itself is measured with poppler and zbar:
//  - one page, A4 landscape (842 × 595 pt), exactly one picture over the whole page, ≥ 2000 px wide;
//  - the cross-reference table is read without a warning (pdfinfo stderr empty, qpdf --check);
//  - the QR code in the PDF gives exactly the verification link (zbarimg);
//  - the certificate fills the page width as on paper, the presentation-copy line is in the margin;
//  - with a library background and a built-in one, in the dark theme, expired and revoked;
//  - no request to another host while the PDF is made, even with a foreign web font on the page;
//  - on a phone in Chromium and in WebKit (Safari's engine);
//  - Arabic and Russian: the PDF shows the sheet exactly as the browser draws it (pixel comparison,
//    with a counter-check that a left-to-right sheet would fail it).
// Runs without Nextcloud: `npm run test:pdf` (scripts/pdf-gate.sh, WebKit in Playwright's container).
const { test, expect } = require('@playwright/test')
const { execFileSync, spawnSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { PNG } = require('pngjs')

const ID = '11111111-2222-4333-8444-555555555555'
/** The PDF's raster scale (≈ 200 dpi), see PDF_SCALES in src/utils/certificatePdf.js. */
const PDF_SCALE = 2339 / 1123
const VERIFY_URL = 'http://127.0.0.1:' + (process.env.PRINT_HARNESS_PORT || 5199) + '/index.php/apps/learning/verify/' + ID

function b64url(value) {
	return Buffer.from(JSON.stringify(value)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** An image with a dark frame (stamp / signature / logo stand-in) as a PNG data URL. */
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

/** A dark, busy library background. */
function darkImageDataUrl(width = 600, height = 420) {
	const png = new PNG({ width, height })
	let seed = 94
	const rand = () => {
		seed = (seed * 1103515245 + 12345) & 0x7fffffff
		return seed / 0x7fffffff
	}
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			const i = (width * y + x) << 2
			const v = (x + y) % 90 < 6 ? 220 : Math.floor(rand() * 40)
			png.data[i] = v
			png.data[i + 1] = v
			png.data[i + 2] = Math.floor(v * 1.5)
			png.data[i + 3] = 255
		}
	}
	return 'data:image/png;base64,' + PNG.sync.write(png).toString('base64')
}

const WORDMARK = pngDataUrl(1200, 320)

function credential({ title = 'Annual data protection training', recipient = 'Alex Example', elements = ['Data protection basics', 'Password policy', 'Incident reporting'], validUntil = '2099-11-16T10:00:00Z' } = {}) {
	return {
		issuer: { id: 'did:web:example.org', name: 'Northwind Academy', image: { id: WORDMARK, type: 'Image' } },
		validFrom: '2026-11-16T10:00:00Z',
		validUntil,
		credentialSubject: {
			name: recipient,
			achievement: {
				name: title,
				alignment: elements.map((name, i) => ({ type: ['Alignment'], targetType: 'ext:LearningRequiredPool', targetName: name, targetUrl: 'urn:learning:pool:' + i })),
			},
			result: [{ type: ['Result'], resultDescription: 'score:92; threshold:75' }],
		},
	}
}

/** The fullest layout: text, elements, stamp, signature, signer, library logo. */
const FULL = {
	intro: 'This is to certify that\n{student.name}\nhas successfully completed\n{course.title}\nwith the following course elements:\n{course.elements}',
	show: { logo: true, issuer: true, score: true, threshold: true, elements: true, validity: true },
	signer_name: 'Dr. Maria Example',
	signer_role: 'Head of Training',
	stamp: pngDataUrl(300, 300),
	signature: pngDataUrl(440, 120),
	logo: WORDMARK,
	logo_library: true,
}

const THEMES = {
	light: ':root { --color-main-background: #fff; --color-main-text: #222; --color-text-maxcontrast: #6b6b6b; --color-border: #ededed; --color-primary-element: #00679e; } body { background: #fff; color: #222; }',
	dark: ':root { --color-main-background: #171717; --color-main-text: #ebebeb; --color-text-maxcontrast: #949494; --color-border: #3b3b3b; --color-primary-element: #4f9ad8; } body { background: #171717; color: #ebebeb; }',
}

/**
 * Open the harness with a certificate. Every request to another origin is counted and refused.
 * @return {Promise<{foreign: string[]}>}
 */
async function open(page, { presentation = FULL, cred = credential(), row = {}, query = '', theme = 'light', foreignFont = false } = {}) {
	const foreign = []
	await page.route('**/*', (route) => {
		const url = new URL(route.request().url())
		if (url.origin !== new URL(VERIFY_URL).origin) {
			foreign.push(url.href)
			return route.abort()
		}
		if (url.pathname.includes('/certificates/')) {
			return route.fulfill({
				contentType: 'application/json',
				body: JSON.stringify({
					verification_id: ID,
					credential_json: b64url({ alg: 'EdDSA' }) + '.' + b64url(cred) + '.sig',
					presentation,
					...row,
				}),
			})
		}
		return route.continue()
	})
	await page.goto('/index.html' + query)
	await page.addStyleTag({ content: THEMES[theme] })
	if (foreignFont) {
		// a web font from another host, used by the card: the PDF must not ask for it
		await page.addStyleTag({ content: '@font-face { font-family: "Foreign Sans"; src: url("https://fonts.example.net/foreign.woff2") format("woff2"); } .certificate-card { font-family: "Foreign Sans", sans-serif; }' })
	}
	await page.locator('.certificate-printable .certificate-card').waitFor()
	await page.evaluate(() => Promise.all([document.fonts.ready, ...Array.from(document.images).map((img) => img.decode().catch(() => {}))]))
	return { foreign }
}

/** Click the button, wait for the download, return the file and the suggested name. */
async function downloadPdf(page, testInfo) {
	const button = page.locator('[data-test="certificate-pdf-button"]')
	await expect(button).toHaveText('Als PDF herunterladen')
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		button.click(),
	])
	const file = testInfo.outputPath(download.suggestedFilename())
	await download.saveAs(file)
	await expect(page.locator('[data-test="certificate-pdf-status"]')).toHaveText('PDF heruntergeladen')
	await expect(button).toHaveAttribute('aria-busy', 'false')
	await expect(page.locator('[data-test="certificate-pdf-error"]')).toHaveCount(0)
	// the copy the PDF was drawn from is gone again
	await expect(page.locator('#learning-certificate-pdf-root')).toHaveCount(0)
	return { file, name: download.suggestedFilename() }
}

/** Everything the criteria ask about one PDF file. */
function inspect(file) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'learning-pdf-'))
	try {
		const info = spawnSync('pdfinfo', [file], { encoding: 'utf8' })
		const size = info.stdout.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/)
		const images = execFileSync('pdfimages', ['-list', file], { encoding: 'utf8' }).trim().split('\n').slice(2)
		const qpdf = spawnSync('qpdf', ['--check', file], { encoding: 'utf8' })
		// 200 dpi for the QR code, as in the print gate
		execFileSync('pdftoppm', ['-r', '200', '-png', '-singlefile', file, path.join(dir, 'page')])
		const pagePng = fs.readFileSync(path.join(dir, 'page.png'))
		let qr = []
		try {
			qr = execFileSync('zbarimg', ['-q', '--raw', path.join(dir, 'page.png')], { encoding: 'utf8' }).trim().split('\n')
		} catch (e) {
			if (e.code === 'ENOENT') {
				throw new Error('zbarimg is not installed (zbar-tools): the QR code cannot be checked', { cause: e })
			}
		}
		const png = PNG.sync.read(pagePng)
		return {
			infoStderr: info.stderr.trim(),
			raw: fs.readFileSync(file).toString('latin1'),
			pages: Number((info.stdout.match(/Pages:\s+(\d+)/) || [])[1]),
			widthPt: Number(size[1]),
			heightPt: Number(size[2]),
			title: (info.stdout.match(/Title:\s+(.*)/) || [])[1],
			subject: (info.stdout.match(/Subject:\s+(.*)/) || [])[1],
			images: images.map((line) => line.trim().split(/\s+/)),
			qpdfOk: qpdf.status === 0 && !/warning/i.test(qpdf.stdout + qpdf.stderr),
			qr,
			png,
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

/** Bounding box of non-white pixels inside a region, as fractions of the page. */
function ink(png, { top = 0, bottom = 1 } = {}) {
	let minX = png.width; let maxX = -1; let count = 0
	for (let y = Math.floor(top * png.height); y < Math.floor(bottom * png.height); y++) {
		for (let x = 0; x < png.width; x++) {
			const i = (png.width * y + x) << 2
			if (png.data[i] < 200 || png.data[i + 1] < 200 || png.data[i + 2] < 200) {
				minX = Math.min(minX, x); maxX = Math.max(maxX, x); count++
			}
		}
	}
	return { width: maxX < 0 ? 0 : (maxX - minX + 1) / png.width, count }
}

/** How many pixels are close to the given colour. */
function pixelsNear(png, [r, g, b], tolerance = 40) {
	let count = 0
	for (let i = 0; i < png.data.length; i += 4) {
		if (Math.abs(png.data[i] - r) < tolerance && Math.abs(png.data[i + 1] - g) < tolerance && Math.abs(png.data[i + 2] - b) < tolerance) {
			count++
		}
	}
	return count
}

/** Ink pixels (at 200 dpi) in the strip just inside the card's top frame: ≈ 280 for the frame alone. */
const BAND_WITHOUT_BACKGROUND = 600

/** (a)–(d): one A4 landscape page, one full-page picture, a scannable QR code, the sheet filled. */
function expectCertificatePdf(m, { title = 'Annual data protection training' } = {}) {
	expect(m.infoStderr).toBe('')
	expect(m.qpdfOk).toBe(true)
	expect(m.pages).toBe(1)
	expect(Math.round(m.widthPt)).toBe(842)
	expect(Math.round(m.heightPt)).toBe(595)
	// exactly one picture, at least 2000 px wide, drawn over the whole page (≈ 200 dpi both ways)
	expect(m.images).toHaveLength(1)
	const [, , type, width, height, , , , enc, , , , xppi, yppi] = m.images[0]
	expect(type).toBe('image')
	expect(enc).toBe('jpeg')
	expect(Number(width)).toBeGreaterThanOrEqual(2000)
	expect(Number(width) / Number(height)).toBeCloseTo(297 / 210, 2)
	expect(Math.abs(Number(xppi) - Number(yppi))).toBeLessThanOrEqual(1)
	expect(m.qr).toEqual([VERIFY_URL])
	expect(m.title).toBe(title)
	expect(m.subject).toBe(VERIFY_URL)
	expect(m.raw).toContain('/Producer <FEFF')
	// the certificate fills the width like the print (card inside the 12 mm margins)
	expect(ink(m.png, { top: 0, bottom: 0.9 }).width).toBeGreaterThanOrEqual(0.85)
	// the presentation-copy line sits in the bottom margin, below the card (last 12 mm of 210)
	expect(ink(m.png, { top: 0.955, bottom: 0.995 }).count).toBeGreaterThan(200)
}

test.describe('certificate PDF', () => {
	test('the fullest certificate becomes a one-page A4 PDF with a scannable QR code', async ({ page }, testInfo) => {
		await open(page)
		// the line is only in the PDF: not on screen, not on the card
		await expect(page.getByText('Präsentationskopie', { exact: false })).toHaveCount(0)
		const { file, name } = await downloadPdf(page, testInfo)
		expect(name).toBe('Zertifikat-Annual-data-protection-training-2026-11-16.pdf')
		const m = inspect(file)
		expectCertificatePdf(m)
		expect(m.raw).toContain('/DCTDecode')
		// without a background the strip inside the card's frame holds only the frame's two sides
		expect(ink(m.png, { top: 0.06, bottom: 0.075 }).count).toBeLessThan(BAND_WITHOUT_BACKGROUND)
		// a raster: no text layer to fake, the picture is the certificate
		expect(execFileSync('pdftotext', [file, '-'], { encoding: 'utf8' }).trim()).toBe('')
	})

	test('nothing is asked from another host while the PDF is made, not even a web font', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'pdf-desktop', 'one browser is enough for the request count')
		const { foreign } = await open(page, { foreignFont: true })
		// the page itself tried the foreign font (and was refused): the trap is armed
		expect(foreign.some((url) => url.includes('fonts.example.net'))).toBe(true)
		foreign.length = 0
		const { file } = await downloadPdf(page, testInfo)
		expect(foreign).toEqual([])
		expect(inspect(file).qr).toEqual([VERIFY_URL])
	})

	for (const [label, extra] of [
		['library background', { background: darkImageDataUrl(), background_intensity: 100 }],
		['built-in corners', { background_builtin: 'corners', background_intensity: 100 }],
	]) {
		test(`with a ${label} the PDF keeps one page, the QR code and the background`, async ({ page }, testInfo) => {
			test.skip(testInfo.project.name !== 'pdf-desktop', 'backgrounds: desktop Chromium')
			await open(page, { presentation: { ...FULL, ...extra } })
			const m = inspect((await downloadPdf(page, testInfo)).file)
			expectCertificatePdf(m)
			// the background reaches the edge of the card: ink in the strip between the card's frame
			// and its paper plate (without a background only the frame's two sides are there)
			expect(ink(m.png, { top: 0.06, bottom: 0.075 }).count).toBeGreaterThan(BAND_WITHOUT_BACKGROUND * 3)
		})
	}

	test('in the dark theme the PDF is on white paper with dark text', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'pdf-desktop', 'theme: desktop Chromium')
		await open(page, { theme: 'dark' })
		const m = inspect((await downloadPdf(page, testInfo)).file)
		expectCertificatePdf(m)
		// paper: the corners of the sheet are white; text: the dark-theme text colour (#ebebeb on
		// #171717) must not be what is on the paper
		const corner = (x, y) => Array.from(m.png.data.slice(((m.png.width * y) + x) << 2, (((m.png.width * y) + x) << 2) + 3))
		for (const [x, y] of [[5, 5], [m.png.width - 6, 5], [5, m.png.height - 6], [m.png.width - 6, m.png.height - 6]]) {
			expect(Math.min(...corner(x, y))).toBeGreaterThan(245)
		}
		expect(pixelsNear(m.png, [23, 23, 23], 6)).toBeLessThan(m.png.width * m.png.height * 0.01)
	})

	test('the PDF of an expired learner certificate says it has expired', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name === 'chromium-phone', 'status: desktop Chromium and WebKit')
		await open(page, { cred: credential({ validUntil: '2025-11-16T10:00:00Z' }), row: { expires_at: 1763287200 } })
		await expect(page.locator('.certificate-printable .certificate-status-note')).toHaveText('Dieses Zertifikat ist abgelaufen.')
		const m = inspect((await downloadPdf(page, testInfo)).file)
		expect(m.pages).toBe(1)
		expect(m.qr).toEqual([VERIFY_URL])
		// the note's dark red frame (#8b0000) is on the sheet
		expect(pixelsNear(m.png, [139, 0, 0], 50)).toBeGreaterThan(500)
	})

	test('the instructor\'s PDF of a revoked copy says it was revoked', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'pdf-desktop', 'status: desktop Chromium')
		await open(page, { query: '?course=5', row: { revoked: true } })
		const m = inspect((await downloadPdf(page, testInfo)).file)
		expectCertificatePdf(m)
		expect(pixelsNear(m.png, [139, 0, 0], 50)).toBeGreaterThan(500)
	})

	test('a valid certificate has no status note in the PDF', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'pdf-desktop', 'status: desktop Chromium')
		await open(page)
		const m = inspect((await downloadPdf(page, testInfo)).file)
		expect(pixelsNear(m.png, [139, 0, 0], 50)).toBeLessThan(50)
	})

	/**
	 * The sheet as the browser draws it, next to the PDF's picture of it: modern-screenshot hands
	 * the drawing to the browser, so right-to-left text and Cyrillic must come out the same.
	 */
	async function sheetOnScreen(page, dir) {
		await page.evaluate((direction) => {
			const { buildCertificateSheet, noteParts } = window.__certificatePdf
			const note = noteParts(window.t('learning', 'Präsentationskopie, kein eigenständiger Nachweis. Echtheit prüfen: QR-Code scannen oder {url} öffnen (Zertifikat-ID {id}).'), {
				id: document.querySelector('.certificate-verify-id').textContent,
				url: document.querySelector('.certificate-verify-url').textContent,
			})
			const root = buildCertificateSheet(document.querySelector('.certificate-printable'), { note, dir: direction, lang: document.documentElement.lang })
			root.style.transform = 'none'
			root.style.zIndex = '10000'
		}, dir)
		await page.evaluate(() => Promise.all(Array.from(document.images).map((img) => img.decode().catch(() => {}))))
		const shot = PNG.sync.read(await page.locator('#learning-certificate-pdf-root').screenshot({ animations: 'disabled' }))
		await page.evaluate(() => window.__certificatePdf.removeCertificatePdfCopy())
		return shot
	}

	/** The picture inside the PDF as it is stored (no resampling), cropped to the given size. */
	function pdfPicture(file, width, height) {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'learning-pdf-px-'))
		try {
			execFileSync('pdfimages', ['-png', file, path.join(dir, 'img')])
			return crop(PNG.sync.read(fs.readFileSync(path.join(dir, 'img-000.png'))), width, height)
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	}

	function crop(png, width, height) {
		if (png.width === width && png.height === height) {
			return png
		}
		const out = new PNG({ width, height })
		PNG.bitblt(png, out, 0, 0, width, height, 0, 0)
		return out
	}

	/**
	 * Share of pixels that have no pixel of similar brightness within one pixel in the other image
	 * (both ways). Resampling, JPEG noise and sub-pixel offsets pass; text that moved does not.
	 */
	function differing(a, b, radius = 1, tolerance = 64) {
		const lum = (png) => {
			const out = new Float32Array(png.width * png.height)
			for (let i = 0; i < out.length; i++) {
				out[i] = 0.299 * png.data[i * 4] + 0.587 * png.data[i * 4 + 1] + 0.114 * png.data[i * 4 + 2]
			}
			return out
		}
		const [la, lb] = [lum(a), lum(b)]
		const { width, height } = a
		const unmatched = (from, to) => {
			let count = 0
			for (let y = 0; y < height; y++) {
				for (let x = 0; x < width; x++) {
					const v = from[y * width + x]
					let found = false
					for (let dy = -radius; dy <= radius && !found; dy++) {
						for (let dx = -radius; dx <= radius && !found; dx++) {
							const xx = x + dx; const yy = y + dy
							if (xx >= 0 && yy >= 0 && xx < width && yy < height && Math.abs(to[yy * width + xx] - v) <= tolerance) {
								found = true
							}
						}
					}
					if (!found) {
						count++
					}
				}
			}
			return count
		}
		return Math.max(unmatched(la, lb), unmatched(lb, la)) / (width * height)
	}

	for (const [lang, title, recipient, elements] of [
		['ar', 'التدريب السنوي على حماية البيانات', 'سارة أحمد', ['أساسيات حماية البيانات', 'سياسة كلمات المرور']],
		['ru', 'Ежегодное обучение по защите данных', 'Анна Петрова', ['Основы защиты данных', 'Политика паролей']],
	]) {
		test(`${lang}: the PDF shows the sheet exactly as the browser draws it`, async ({ browser }, testInfo) => {
			test.skip(testInfo.project.name !== 'pdf-desktop', 'pixel comparison: desktop Chromium')
			// the screen at the PDF's own resolution, so both pictures have the same pixels
			const context = await browser.newContext({ viewport: { width: 1200, height: 860 }, deviceScaleFactor: PDF_SCALE })
			const page = await context.newPage()
			try {
				await open(page, { query: '?lang=' + lang, cred: credential({ title, recipient, elements }) })
				const button = page.locator('[data-test="certificate-pdf-button"]')
				await expect(button).toHaveText(lang === 'ar' ? 'تنزيل بصيغة PDF' : 'Скачать в PDF')
				const [download] = await Promise.all([page.waitForEvent('download'), button.click()])
				const file = testInfo.outputPath('sheet.pdf')
				await download.saveAs(file)
				expect(download.suggestedFilename()).toBe(lang === 'ar'
					? 'شهادة-التدريب-السنوي-على-حماية-البيانات-2026-11-16.pdf'
					: 'Сертификат-Ежегодное-обучение-по-защите-данных-2026-11-16.pdf')
				const m = inspect(file)
				expect(m.pages).toBe(1)
				expect(m.qr).toEqual([VERIFY_URL])
				expect(m.title).toBe(title)

				const screen = await sheetOnScreen(page, lang === 'ar' ? 'rtl' : 'ltr')
				const width = Math.min(screen.width, Number(m.images[0][3]))
				const height = Math.min(screen.height, Number(m.images[0][4]))
				const pdf = pdfPicture(file, width, height)
				const same = differing(crop(screen, width, height), pdf)
				expect(same).toBeLessThan(0.005)
				if (lang === 'ar') {
					// counter-check: the same sheet laid out left to right differs clearly, so the
					// comparison above would notice a PDF that lost the direction
					const ltr = crop(await sheetOnScreen(page, 'ltr'), width, height)
					expect(differing(ltr, pdf)).toBeGreaterThan(Math.max(0.01, same * 5))
				}
			} finally {
				await context.close()
			}
		})
	}
})
