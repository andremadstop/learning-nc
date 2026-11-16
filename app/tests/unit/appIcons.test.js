/**
 * GitHub #81: the app icons must survive Nextcloud's own handling of them.
 *
 * Theming renders img/app.svg through Imagick for the favicon and the touch icons, and the
 * navigation and the Apps list recolour it. The old icons cut their holes with <mask>; Imagick
 * drew them fully transparent (empty favicon) and the recolouring got the colour wrong.
 * Holes are now fill-rule="evenodd" subpaths of plain paths in one colour.
 *
 * This test reads the files as they ship. It is not a renderer: it bans the SVG features that
 * Imagick and the recolouring do not handle, and pins the monochrome icons to one colour each.
 */
import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const IMG_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'img')
const ICONS = readdirSync(IMG_DIR).filter((f) => /^app.*\.svg$/.test(f)).sort()

const read = (name) => readFileSync(join(IMG_DIR, name), 'utf8')

const FORBIDDEN = [
	[/<mask\b/i, '<mask>'],
	[/<clipPath\b/i, '<clipPath>'],
	[/<filter\b/i, '<filter>'],
	[/<style\b/i, '<style>'],
	[/\sstyle\s*=/i, 'style="…" attribute'],
	[/<text\b/i, '<text>'],
	[/<image\b/i, '<image>'],
	[/<use\b/i, '<use>'],
	[/<foreignObject\b/i, '<foreignObject>'],
	[/<script\b/i, '<script>'],
	[/\s(xlink:)?href\s*=/i, 'href (external reference)'],
	[/url\(\s*(?!#)/i, 'url() that is not a local #id'],
	[/@import/i, '@import'],
]

/** The monochrome icons Nextcloud recolours: file → its single fill colour. */
const MONOCHROME = { 'app.svg': '#fff', 'app-dark.svg': '#000' }

describe('app icons (#81)', () => {
	it('finds the shipped icons', () => {
		expect(ICONS).toEqual(expect.arrayContaining(['app.svg', 'app-dark.svg', 'app-color.svg']))
	})

	for (const name of ICONS) {
		it(`${name} uses no feature that Imagick or the recolouring breaks`, () => {
			const svg = read(name)
			const hits = FORBIDDEN.filter(([re]) => re.test(svg)).map(([, label]) => label)
			expect(hits).toEqual([])
			expect(svg).toMatch(/viewBox="0 0 32 32"/)
		})
	}

	for (const [name, colour] of Object.entries(MONOCHROME)) {
		it(`${name} is plain paths in ${colour} with evenodd holes`, () => {
			const svg = read(name)
			// Only <svg> and <path> elements.
			const elements = [...svg.matchAll(/<([a-zA-Z][\w:-]*)/g)].map((m) => m[1])
			expect(new Set(elements)).toEqual(new Set(['svg', 'path']))
			// Every path is filled in the one colour, with evenodd, and nothing is stroked.
			const paths = [...svg.matchAll(/<path\b[^>]*>/g)].map((m) => m[0])
			expect(paths.length).toBeGreaterThan(0)
			for (const p of paths) {
				expect(p).toContain(`fill="${colour}"`)
				expect(p).toContain('fill-rule="evenodd"')
			}
			expect(svg).not.toMatch(/stroke/i)
			expect(svg).not.toMatch(/opacity/i)
			const colours = new Set([...svg.matchAll(/#[0-9a-fA-F]{3,8}\b/g)].map((m) => m[0].toLowerCase()))
			expect([...colours]).toEqual([colour])
		})
	}
})
