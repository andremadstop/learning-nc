/**
 * GitHub #88: in Pool rules the "Specific question IDs" field (#pool-rule-question-ids) was 130px
 * wide while the other fields spanned the dialog. Nextcloud's core style sets `width: 130px` on
 * every textarea (NC 33 also on every select); in the column of `.rules-field` an explicit width
 * beats the flex stretch. The component now gives the textarea and the selects of a rules field
 * the full width of the column.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')

/** The rules of the component's own <style> block, selector → declarations. */
function ownRules() {
	const style = SOURCE.slice(SOURCE.indexOf('<style'))
	const rules = {}
	for (const m of style.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^}]*)\}/g)) {
		for (const selector of m[1].split(',').map((s) => s.trim())) {
			rules[selector] = (rules[selector] || '') + m[2]
		}
	}
	return rules
}

describe('Pool rules: every field spans the column (GitHub #88)', () => {
	it('the question id field is a textarea inside a rules field', () => {
		expect(SOURCE).toMatch(/<div class="rules-field">\s*<label for="pool-rule-question-ids">[\s\S]*?<textarea\s+id="pool-rule-question-ids"/)
	})

	it.each(['.rules-field textarea', '.rules-field select'])('%s overrides the core width of 130px', (selector) => {
		const rule = ownRules()[selector]
		expect(rule, selector).toBeDefined()
		expect(rule).toMatch(/(^|;|\s)width:\s*100%/)
		expect(rule).toMatch(/box-sizing:\s*border-box/)
	})
})
