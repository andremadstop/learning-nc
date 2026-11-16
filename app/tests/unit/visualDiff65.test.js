/**
 * scripts/persona-sweep/visual-diff.mjs (GitHub #65): compares the screenshots of two Persona-Gate
 * runs. Checked with tiny generated PNGs: identical, changed, resized, new and gone stations, the
 * station key without the running number, and that the report never crashes on a broken file.
 */
import { describe, expect, it } from 'vitest'
import { mkdtempSync, mkdirSync, writeFileSync, existsSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { shotKey, comparePng, compareRuns, renderReport } from '../../../scripts/persona-sweep/visual-diff.mjs'

const { PNG } = createRequire(resolve(__dirname, '../../package.json'))('pngjs')

/** A w×h PNG filled with one colour, with an optional differently coloured pixel block. */
function png(w, h, rgb, block = null) {
	const p = new PNG({ width: w, height: h })
	for (let y = 0; y < h; y++) {
		for (let x = 0; x < w; x++) {
			const i = (y * w + x) * 4
			const inBlock = block && x >= block.x && x < block.x + block.w && y >= block.y && y < block.y + block.h
			const c = inBlock ? block.rgb : rgb
			p.data[i] = c[0]
			p.data[i + 1] = c[1]
			p.data[i + 2] = c[2]
			p.data[i + 3] = 255
		}
	}
	return PNG.sync.write(p)
}

describe('visual-diff (GitHub #65)', () => {
	it('matches stations by persona and station, not by running number', () => {
		expect(shotKey('admin--018-top-courses.png')).toBe('admin--top-courses')
		expect(shotKey('/x/shots/learner-1--203-course-tab-exam.png')).toBe('learner-1--course-tab-exam')
		expect(shotKey('self-test.png')).toBe('self-test')
	})

	it('reports identical images as unchanged and counts changed pixels', () => {
		const white = png(10, 10, [255, 255, 255])
		expect(comparePng(white, png(10, 10, [255, 255, 255]))).toMatchObject({ status: 'unchanged', diffPixels: 0, diff: null })
		const r = comparePng(white, png(10, 10, [255, 255, 255], { x: 0, y: 0, w: 2, h: 5, rgb: [0, 0, 0] }))
		expect(r.status).toBe('changed')
		expect(r.diffPixels).toBe(10)
		expect(r.ratio).toBeCloseTo(0.1)
		expect(PNG.sync.read(r.diff).width).toBe(10)
	})

	it('reports a different size as resized instead of crashing', () => {
		expect(comparePng(png(10, 10, [0, 0, 0]), png(10, 12, [0, 0, 0]))).toMatchObject({ status: 'resized', size: '10x10 -> 10x12' })
	})

	it('compares two runs: changed, resized, new, gone, unchanged, unreadable — and writes diff images and a report', () => {
		const root = mkdtempSync(join(tmpdir(), 'visual-diff-'))
		const a = join(root, 'a', 'shots')
		const b = join(root, 'b', 'shots')
		mkdirSync(a, { recursive: true })
		mkdirSync(b, { recursive: true })
		const grey = png(8, 8, [200, 200, 200])
		writeFileSync(join(a, 'admin--001-same.png'), grey)
		writeFileSync(join(b, 'admin--004-same.png'), grey) // running number shifted
		writeFileSync(join(a, 'admin--002-changed.png'), grey)
		writeFileSync(join(b, 'admin--005-changed.png'), png(8, 8, [200, 200, 200], { x: 0, y: 0, w: 8, h: 2, rgb: [255, 0, 0] }))
		writeFileSync(join(a, 'admin--003-resized.png'), grey)
		writeFileSync(join(b, 'admin--006-resized.png'), png(8, 9, [200, 200, 200]))
		writeFileSync(join(a, 'learner--001-gone.png'), grey)
		writeFileSync(join(b, 'learner--002-new.png'), grey)
		writeFileSync(join(a, 'learner--003-broken.png'), grey)
		writeFileSync(join(b, 'learner--004-broken.png'), Buffer.from('not a png'))

		const diffDir = join(root, 'out', 'diff')
		const entries = compareRuns(join(root, 'a'), join(root, 'b'), { diffDir })
		const byKey = Object.fromEntries(entries.map((e) => [e.key, e]))
		expect(byKey['admin--same'].status).toBe('unchanged')
		expect(byKey['admin--changed']).toMatchObject({ status: 'changed', diffPixels: 16 })
		expect(existsSync(byKey['admin--changed'].diffImage)).toBe(true)
		expect(byKey['admin--resized'].status).toBe('resized')
		expect(byKey['learner--gone'].status).toBe('gone')
		expect(byKey['learner--new'].status).toBe('new')
		expect(byKey['learner--broken'].status).toBe('unreadable')

		const md = renderReport(entries, { baseline: 'a', current: 'b', threshold: 0.1 })
		expect(md).toContain('| changed | 1 |')
		expect(md).toContain('| unchanged | 1 |')
		expect(md).toMatch(/\| admin--changed \| changed \| 16 \(25\.00 %\)/)
		expect(md).not.toMatch(/\| admin--same \|/)
		// the most changed station comes first
		expect(md.indexOf('admin--changed')).toBeLessThan(md.indexOf('learner--new'))
	})
})
