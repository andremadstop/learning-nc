/**
 * GitHub #38 (follow-up to 38-c): the compiler drops the whitespace between
 * <strong>{{ fileName }}</strong> and <small>(… KB)</small>; a margin on the
 * <small> makes up the gap. A physical margin-left lands on the outer side in
 * RTL (Arabic), so file name and size ran together there.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

function ruleFor(file, selector) {
	const sfc = readFileSync(resolve(__dirname, '../../src/components', file), 'utf8')
	const css = [...sfc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n')
	const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
		.filter(m => m[1].split(',').map(s => s.trim()).includes(selector))
		.map(m => m[2])
	return rules.join(';')
}

describe('import dialogs keep file name and size apart in RTL (GitHub #38)', () => {
	it.each([
		['ImportDialog.vue', '.dropzone-file small'],
	])('%s %s uses a logical margin', (file, selector) => {
		const decls = ruleFor(file, selector)
		expect(decls, `${selector} not found`).not.toBe('')
		expect(decls).toMatch(/margin-inline-start:\s*8px/)
		expect(decls).not.toMatch(/margin-(left|right)\s*:/)
	})
})
