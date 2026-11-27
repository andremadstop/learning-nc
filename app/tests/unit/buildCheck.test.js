import { describe, it, expect, afterEach } from 'vitest'
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'fs'
import { tmpdir } from 'os'
import { join } from 'path'
import { checkBuild, BUDGETS, MARKERS } from '../../build-check.mjs'

// The size budget after `npm run build`: one import from the @nextcloud/vue package entry made the
// main bundle 1.3 MB larger without anyone noticing (27.11.2026).
const dirs = []
function fakeBuild(overrides = {}) {
	const root = mkdtempSync(join(tmpdir(), 'build-check-'))
	dirs.push(root)
	mkdirSync(join(root, 'js'))
	mkdirSync(join(root, 'css'))
	for (const [file, limit] of Object.entries(BUDGETS)) {
		const size = overrides[file] ?? Math.floor(limit / 2)
		const body = file === 'js/learning.js' ? Object.keys(MARKERS).join(' ') : ''
		writeFileSync(join(root, file), body.padEnd(size, ' '))
	}
	return root
}

afterEach(() => {
	for (const d of dirs.splice(0)) rmSync(d, { recursive: true, force: true })
})

describe('build check', () => {
	it('passes a complete build within budget', () => {
		expect(Array.isArray(checkBuild(fakeBuild()))).toBe(false)
	})

	it('fails a bundle one byte over its budget', () => {
		const limit = BUDGETS['js/learning.js']
		const errors = checkBuild(fakeBuild({ 'js/learning.js': limit + 1 }))
		expect(errors).toEqual([`js/learning.js is ${limit + 1} bytes, budget ${limit} (+1)`])
	})

	it('fails a build without the markers of the main bundle', () => {
		const root = fakeBuild()
		writeFileSync(join(root, 'js/learning.js'), 'x')
		expect(checkBuild(root)).toContain('marker CourseDetail: expected >= 1, got 0')
	})

	it('fails a build that lacks a budgeted file', () => {
		const root = fakeBuild()
		rmSync(join(root, 'css/learning.css'))
		expect(checkBuild(root)).toEqual(['css/learning.css missing'])
	})
})
