/**
 * scripts/screenshots/lib.mjs (GitHub #74): the size budget of the README, guide and App Store
 * pictures. Checked with tiny generated PNGs: a picture in budget passes, one over the budget, of
 * the wrong width, shorter than the window, missing or not a PNG is named, and the picture list
 * has unique ids and destinations inside screenshots/ or docs/guides/img/.
 */
import { describe, expect, it } from 'vitest'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { SHOTS, VIEWPORT, DEVICE_SCALE_FACTOR, BUDGET_BYTES, pngSize, budgetFindings } from '../../../scripts/screenshots/lib.mjs'

const { PNG } = createRequire(resolve(__dirname, '../../package.json'))('pngjs')
const png = (width, height) => PNG.sync.write(new PNG({ width, height }))
const opts = { shots: [{ id: 'a' }], budget: 1000, viewport: { width: 4, height: 3 }, scale: 2 }

describe('screenshots lib (#74)', () => {
	it('reads width and height from the PNG header and refuses other data', () => {
		expect(pngSize(png(7, 5))).toEqual({ width: 7, height: 5 })
		expect(pngSize(Buffer.from('not a png at all, really not'))).toBeNull()
		expect(pngSize(Buffer.alloc(3))).toBeNull()
	})

	it('passes a picture of the window size within the budget', () => {
		const buf = png(8, 6)
		expect(budgetFindings({ a: { bytes: buf.length, buf } }, opts)).toEqual([])
		const tall = png(8, 20) // a full-page picture may be taller than the window
		expect(budgetFindings({ a: { bytes: tall.length, buf: tall } }, opts)).toEqual([])
	})

	it('names a picture over the budget, of the wrong width, too short, missing or broken', () => {
		const buf = png(8, 6)
		expect(budgetFindings({ a: { bytes: 2048, buf } }, opts)).toEqual(['a: 2 KB, over the 1 KB budget'])
		expect(budgetFindings({ a: { bytes: 10, buf: png(9, 6) } }, opts)).toEqual(['a: 9 px wide, expected 8'])
		expect(budgetFindings({ a: { bytes: 10, buf: png(8, 5) } }, opts)).toEqual(['a: 5 px high, less than the window (6)'])
		expect(budgetFindings({}, opts)).toEqual(['a: missing'])
		expect(budgetFindings({ a: { bytes: 10, buf: Buffer.from('xxxxxxxxxxxxxxxxxxxxxxxxxxxx') } }, opts)).toEqual(['a: not a PNG'])
	})

	it('has 6 to 8 gallery pictures, unique ids and destinations, budget 400 KB, light window 2x', () => {
		const gallery = SHOTS.filter((s) => s.dest.startsWith('screenshots/'))
		expect(gallery.length).toBeGreaterThanOrEqual(6)
		expect(gallery.length).toBeLessThanOrEqual(8)
		expect(new Set(SHOTS.map((s) => s.id)).size).toBe(SHOTS.length)
		expect(new Set(SHOTS.map((s) => s.dest)).size).toBe(SHOTS.length)
		for (const s of SHOTS) expect(s.dest).toMatch(/^(screenshots|docs\/guides\/img)\/[a-z0-9-]+\.png$/)
		// the App Store links four existing files from main; the run must never overwrite them
		for (const s of SHOTS) expect(s.dest).not.toMatch(/screenshots\/(08|09|10|11)-/)
		expect(BUDGET_BYTES).toBe(400 * 1024)
		expect(VIEWPORT.width * DEVICE_SCALE_FACTOR).toBe(2880)
	})
})
