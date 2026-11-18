/**
 * GitHub #96: in Participants › Weak questions the question text ran over the Chapter column.
 * `.q-text { max-width: 300px }` does not hold a table cell under the auto table layout, nothing
 * let a long word or URL break, and the table had no scroll container, so on a narrow screen the
 * columns overlapped. The text now wraps inside its column (anywhere, if it must) and the table
 * sits in a keyboard-reachable region that scrolls sideways.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')

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

describe('weak questions table (GitHub #96)', () => {
	it('the question text wraps inside its column, long words and URLs included', () => {
		expect(ownRules()['.q-text']).toMatch(/overflow-wrap:\s*anywhere/)
	})

	it('the table sits in a region that scrolls sideways and has a minimum width', () => {
		const rules = ownRules()
		expect(rules['.weak-questions-scroll']).toMatch(/overflow-x:\s*auto/)
		expect(rules['.weak-questions-table']).toMatch(/min-width:/)
	})

	it('the scroll region wraps the table and is reachable by keyboard with a name', () => {
		const template = SOURCE.slice(0, SOURCE.indexOf('<script'))
		const region = template.match(/<div[^>]*class="weak-questions-scroll"[^>]*>/)
		expect(region, 'scroll container').not.toBeNull()
		expect(region[0]).toMatch(/tabindex="0"/)
		expect(region[0]).toMatch(/role="region"/)
		expect(region[0]).toMatch(/aria-label=/)
		const afterRegion = template.slice(template.indexOf(region[0]))
		expect(afterRegion.indexOf('class="weak-questions-table"')).toBeGreaterThan(0)
		expect(afterRegion.indexOf('class="weak-questions-table"')).toBeLessThan(afterRegion.indexOf('</table>'))
	})
})
