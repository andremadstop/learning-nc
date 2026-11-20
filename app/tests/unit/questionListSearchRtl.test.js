/**
 * RTL (Arabic) in "View questions" / "Manage": the search field of the question list kept its ×
 * physically on the right and its text room physically on the right, so in a right-to-left
 * interface the × covered the start of the typed text. The × now sits at the end of the field and
 * the room for it is kept at the end, like the Pools search (PoolList, GitHub #98).
 */
import { describe, expect, it } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'

const css = fs.readFileSync(path.resolve(__dirname, '../../src/components/QuestionList.vue'), 'utf8')
const rule = (selector) => new RegExp(`\\n${selector.replace(/[.#]/g, (c) => `\\${c}`)} \\{([^}]*)\\}`).exec(css)?.[1] ?? ''

describe('QuestionList search: the × follows the reading direction', () => {
	it.each(['.question-search-input', '.question-search-bar .clear-search-btn'])('%s has no physical left/right', (selector) => {
		const body = rule(selector)
		expect(body).not.toBe('')
		expect(body).not.toMatch(/(^|[\s;])(left|right|padding-left|padding-right|margin-left|margin-right)\s*:/)
		// a padding or margin shorthand with different left and right values is physical as well
		for (const [, , values] of body.matchAll(/(^|[\s;])(?:padding|margin)\s*:\s*([^;]+)/g)) {
			const parts = values.trim().split(/\s+/)
			if (parts.length === 4) expect(parts[1]).toBe(parts[3])
			if (parts.length === 3) throw new Error(`3-value shorthand in ${selector}: ${values}`)
		}
	})

	it('the × sits at the end, the text keeps room for it there', () => {
		expect(rule('.question-search-bar .clear-search-btn')).toMatch(/inset-inline-end:\s*8px/)
		expect(rule('.question-search-input')).toMatch(/padding-inline:\s*12px 32px/)
	})
})
