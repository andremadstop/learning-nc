/**
 * GitHub #79: hovering a course title in the course list showed a square block. The title is a
 * <button> (#64); Nextcloud's core style gives every plain button a background on :hover and
 * :focus (`button:not(.button-vue, [class^="vs__"]):hover`, specificity 0,2,1), which beat the
 * title's own `background: none` (scoped `.course-card-open[data-v]`, 0,2,0); the title's
 * `border-radius: 0` made it square. The card reacts to the pointer already (lift, shadow,
 * border), so the title gets no hover background: the component states `background: none` for
 * :hover, :focus and :active, which as a scoped rule (0,3,0) outranks the core rule.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/CourseList.vue'), 'utf8')

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

describe('course list: the title has no square hover block (GitHub #79)', () => {
	it.each([':hover', ':focus', ':active'])('the title button clears the core button background on %s', (state) => {
		const rule = ownRules()['.course-card-open' + state]
		expect(rule, `.course-card-open${state}`).toBeDefined()
		expect(rule).toMatch(/background:\s*none/)
	})

	it('the card keeps its own hover reaction and the keyboard focus outline', () => {
		const rules = ownRules()
		expect(rules['.course-card:hover']).toMatch(/border-color:\s*var\(--color-primary-element\)/)
		expect(rules['.course-card:has(.course-card-open:focus-visible)']).toMatch(/outline:/)
	})
})
