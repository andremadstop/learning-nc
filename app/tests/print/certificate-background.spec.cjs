// GitHub #93: certificate backgrounds on screen and on paper. The real Certificate.vue (harness.js)
// is drawn with each built-in background and with a dark library image at full strength, in the
// light and the dark theme, and measured:
//  - contrast by pixels: the text is made transparent, the card is photographed, and every pixel
//    under every text box is compared with that text's colour (axe cannot judge text on an image
//    and files it as "incomplete", which is never counted as passed here);
//  - the QR code is decoded with zbarimg, from the screen and from the printed PDF, and must give
//    exactly the verification link;
//  - the printed sheet stays one A4 landscape page filled to its width, with the background on it.
const { test, expect } = require('@playwright/test')
const { execFileSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { PNG } = require('pngjs')

const PT_PER_MM = 72 / 25.4
const ID = '11111111-2222-4333-8444-555555555555'

function b64url(value) {
	return Buffer.from(JSON.stringify(value)).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** A dark, busy test image (near-black noise with a few bright stripes) as a PNG data URL. */
function darkImageDataUrl(width = 600, height = 420) {
	const png = new PNG({ width, height })
	let seed = 93
	const rand = () => {
		seed = (seed * 1103515245 + 12345) & 0x7fffffff
		return seed / 0x7fffffff
	}
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			const i = (width * y + x) << 2
			const stripe = (x + y) % 90 < 6
			const v = stripe ? 230 : Math.floor(rand() * 40)
			png.data[i] = v
			png.data[i + 1] = stripe ? 200 : v
			png.data[i + 2] = stripe ? 40 : Math.floor(v * 1.5)
			png.data[i + 3] = 255
		}
	}
	return 'data:image/png;base64,' + PNG.sync.write(png).toString('base64')
}

const DARK_IMAGE = darkImageDataUrl()

const credential = {
	issuer: { id: 'did:web:example.org', name: 'Northwind Academy' },
	validFrom: '2026-11-16T10:00:00Z',
	validUntil: '2027-11-16T10:00:00Z',
	credentialSubject: {
		name: 'Alex Example',
		achievement: {
			name: 'Annual data protection training',
			alignment: ['Data protection basics', 'Password policy']
				.map((title, i) => ({ type: ['Alignment'], targetType: 'ext:LearningRequiredPool', targetName: title, targetUrl: 'urn:learning:pool:' + i })),
		},
		result: [{ type: ['Result'], resultDescription: 'score:92; threshold:75' }],
	},
}

const BASE = {
	intro: 'This is to certify that\n{student.name}\nhas successfully completed\n{course.title}',
	show: { logo: true, issuer: true, score: true, threshold: true, elements: true, validity: true },
	signer_name: 'Dr. Maria Example',
	signer_role: 'Head of Training',
}

const BACKGROUNDS = {
	'built-in frame': { background_builtin: 'frame', background_intensity: 100 },
	'built-in corners': { background_builtin: 'corners', background_intensity: 100 },
	'built-in lines': { background_builtin: 'lines', background_intensity: 100 },
	'dark library image': { background: DARK_IMAGE, background_intensity: 100 },
}

/** Nextcloud's colour tokens of the two themes (the harness has no Nextcloud stylesheet). */
const THEMES = {
	light: ':root { --color-main-background: #fff; --color-main-text: #222; --color-text-maxcontrast: #6b6b6b; --color-border: #ededed; --color-primary-element: #00679e; } body { background: #fff; color: #222; }',
	dark: ':root { --color-main-background: #171717; --color-main-text: #ebebeb; --color-text-maxcontrast: #949494; --color-border: #3b3b3b; --color-primary-element: #4f9ad8; } body { background: #171717; color: #ebebeb; }',
}

async function open(page, presentation, theme = 'light') {
	await page.addInitScript(() => {
		window.__printed = 0
		window.print = () => { window.__printed++ }
	})
	await page.route('**/certificates/**', (route) => route.fulfill({
		contentType: 'application/json',
		body: JSON.stringify({
			verification_id: ID,
			credential_json: b64url({ alg: 'EdDSA' }) + '.' + b64url(credential) + '.sig',
			presentation: { ...BASE, ...presentation },
		}),
	}))
	await page.goto('/index.html')
	await page.addStyleTag({ content: THEMES[theme] })
	await page.locator('.certificate-printable .certificate-card').waitFor()
	await page.evaluate(() => Promise.all(Array.from(document.images).map((img) => img.decode().catch(() => {}))))
}

function luminance([r, g, b]) {
	const c = [r, g, b].map((v) => {
		const s = v / 255
		return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
}

function contrast(a, b) {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
	return (hi + 0.05) / (lo + 0.05)
}

/**
 * The lowest contrast between each text box's colour and any pixel behind it.
 * @return {Promise<Array<{text: string, ratio: number, needed: number}>>}
 */
async function measureContrast(page) {
	const card = page.locator('.certificate-printable .certificate-card')
	const boxes = await card.evaluate((root) => {
		const origin = root.getBoundingClientRect()
		const out = []
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
		while (walker.nextNode()) {
			const node = walker.currentNode
			if (!node.textContent.trim()) {
				continue
			}
			const el = node.parentElement
			const style = getComputedStyle(el)
			const range = document.createRange()
			range.selectNodeContents(node)
			for (const r of range.getClientRects()) {
				if (r.width < 1 || r.height < 1) {
					continue
				}
				const size = parseFloat(style.fontSize)
				const bold = Number(style.fontWeight) >= 700
				out.push({
					text: node.textContent.trim().slice(0, 40),
					color: style.color.match(/[\d.]+/g).slice(0, 3).map(Number),
					x: r.left - origin.left,
					y: r.top - origin.top,
					w: r.width,
					h: r.height,
					large: size >= 24 || (bold && size >= 18.66),
				})
			}
		}
		return out
	})
	expect(boxes.length).toBeGreaterThan(10)
	// the text away, the background stays: what is left behind each box is what the text stands on
	const hide = await page.addStyleTag({ content: '.certificate-card, .certificate-card * { color: transparent !important; text-shadow: none !important; } .certificate-card img:not(.certificate-bg-image) { visibility: hidden !important; }' })
	const png = PNG.sync.read(await card.screenshot({ animations: 'disabled' }))
	await hide.evaluate((el) => el.remove())
	return boxes.map((box) => {
		let worst = Infinity
		const x0 = Math.max(0, Math.floor(box.x))
		const y0 = Math.max(0, Math.floor(box.y))
		const x1 = Math.min(png.width, Math.ceil(box.x + box.w))
		const y1 = Math.min(png.height, Math.ceil(box.y + box.h))
		for (let y = y0; y < y1; y++) {
			for (let x = x0; x < x1; x++) {
				const i = (png.width * y + x) << 2
				worst = Math.min(worst, contrast(box.color, [png.data[i], png.data[i + 1], png.data[i + 2]]))
			}
		}
		return { text: box.text, ratio: Math.round(worst * 100) / 100, needed: box.large ? 3 : 4.5 }
	})
}

/** zbarimg on a PNG buffer: the decoded QR payloads. */
function decodeQr(pngBuffer) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'learning-qr-'))
	try {
		const file = path.join(dir, 'shot.png')
		fs.writeFileSync(file, pngBuffer)
		return execFileSync('zbarimg', ['-q', '--raw', file], { encoding: 'utf8' }).trim().split('\n')
	} catch (e) {
		// no zbarimg (package zbar-tools) is a broken setup, not an unreadable code
		if (e.code === 'ENOENT') {
			throw new Error('zbarimg is not installed (zbar-tools): the QR code cannot be checked', { cause: e })
		}
		return []
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

for (const [name, background] of Object.entries(BACKGROUNDS)) {
	for (const theme of ['light', 'dark']) {
		test(`${name}, ${theme} theme: every text keeps its contrast and the QR code scans`, async ({ page }) => {
			await open(page, background, theme)
			const card = page.locator('.certificate-printable .certificate-card')
			await expect(card).toHaveClass(/certificate-card--background/)
			await expect(card.locator('[data-test="certificate-background"]')).toHaveAttribute('aria-hidden', 'true')

			const results = await measureContrast(page)
			const failing = results.filter((r) => r.ratio < r.needed)
			expect(failing, JSON.stringify(failing)).toEqual([])

			// axe: no violation; text on an image it cannot judge is covered by the pixel check above
			await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') })
			const axe = await page.evaluate(async () => {
				const result = await window.axe.run(document.querySelector('.certificate-printable'), { runOnly: ['color-contrast'] })
				return { violations: result.violations.length }
			})
			expect(axe.violations).toBe(0)

			const verifyUrl = (await card.locator('.certificate-verify-url').textContent()).trim()
			expect(verifyUrl).toContain('/apps/learning/verify/' + ID)
			expect(decodeQr(await card.screenshot())).toEqual([verifyUrl])
		})
	}
}

/** Paper size, pages, text, inked width, and the QR payloads of page 1 at 200 dpi. */
function measurePdf(pdf) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'learning-print-bg-'))
	try {
		const file = path.join(dir, 'certificate.pdf')
		fs.writeFileSync(file, pdf)
		const info = execFileSync('pdfinfo', [file], { encoding: 'utf8' })
		const size = info.match(/Page size:\s+([\d.]+) x ([\d.]+) pts/)
		const pages = Number(info.match(/Pages:\s+(\d+)/)[1])
		const text = execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8' })
		execFileSync('pdftoppm', ['-r', '200', '-f', '1', '-l', '1', '-png', file, path.join(dir, 'page')])
		const shot = path.join(dir, fs.readdirSync(dir).find((n) => n.endsWith('.png')))
		const buffer = fs.readFileSync(shot)
		const png = PNG.sync.read(buffer)
		let minX = png.width; let maxX = -1
		let coloured = 0
		for (let y = 0; y < png.height; y += 2) {
			for (let x = 0; x < png.width; x += 2) {
				const i = (png.width * y + x) << 2
				const [r, g, b] = [png.data[i], png.data[i + 1], png.data[i + 2]]
				if (r < 245 || g < 245 || b < 245) {
					minX = Math.min(minX, x); maxX = Math.max(maxX, x)
				}
				if (Math.max(r, g, b) - Math.min(r, g, b) > 40) {
					coloured++
				}
			}
		}
		return {
			widthPt: Number(size[1]),
			heightPt: Number(size[2]),
			pages,
			text,
			inkWidth: maxX < 0 ? 0 : (maxX - minX + 1) / png.width,
			coloured,
			qr: decodeQr(buffer),
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}
}

for (const name of ['built-in frame', 'dark library image']) {
	test(`${name} prints on one A4 landscape page, with the background and a scannable QR code`, async ({ page }) => {
		await open(page, BACKGROUNDS[name], 'dark')
		const verifyUrl = (await page.locator('.certificate-verify-url').textContent()).trim()
		await page.getByRole('button', { name: 'Drucken' }).click()
		await expect.poll(() => page.evaluate(() => window.__printed)).toBe(1)
		await page.emulateMedia({ media: 'print' })
		await expect(page.locator('#learning-certificate-print-root [data-test="certificate-background"]')).toHaveCount(1)

		const m = measurePdf(await page.pdf({ preferCSSPageSize: true, printBackground: true }))
		expect(Math.round(m.widthPt)).toBe(Math.round(297 * PT_PER_MM))
		expect(Math.round(m.heightPt)).toBe(Math.round(210 * PT_PER_MM))
		expect(m.pages).toBe(1)
		expect(m.inkWidth).toBeGreaterThanOrEqual(0.85)
		// the background reached the paper (print-color-adjust: exact): coloured pixels beyond the text
		expect(m.coloured).toBeGreaterThan(500)
		expect(m.text).toContain('Annual data protection training')
		expect(m.text).toContain('Alex Example')
		expect(m.text).toContain('Northwind Academy')
		expect(m.qr).toEqual([verifyUrl])
	})
}
