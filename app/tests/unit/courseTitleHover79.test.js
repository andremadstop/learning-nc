/**
 * GitHub #79: hovering a course title in the course list showed a square block. The title is a
 * <button> (#64); Nextcloud's core style gives every plain button a background on :hover and
 * :focus (`button:not(.button-vue, [class^="vs__"]):hover`, specificity 0,2,1), which beat the
 * title's own `background: none` (scoped `.course-card-open[data-v]`, 0,2,0); the title's
 * `border-radius: 0` made it square. The card reacts to the pointer already (lift, shadow,
 * border), so the title gets no hover background: the component states `background: none` for
 * :hover, :focus and :active, which as a scoped rule (0,3,0) outranks the core rule.
 *
 * 5.14.0 fixed the course list only. The same title button sits on the cards of the instructor
 * Dashboard and of the pool lists (My pools, Shared with me, Pools in my courses — one rule in
 * PoolList.vue covers all three tabs); the reporter found the block there again (#79, 18.11.),
 * and then on the pool rows inside a course (CourseTabLernraum.vue).
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/** The rules of a component's own <style> block, selector → declarations. */
function ownRules(file) {
	const source = readFileSync(resolve(__dirname, '../../src/components', file), 'utf8')
	const style = source.slice(source.indexOf('<style'))
	const rules = {}
	for (const m of style.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^}]*)\}/g)) {
		for (const selector of m[1].split(',').map((s) => s.trim())) {
			rules[selector] = (rules[selector] || '') + m[2]
		}
	}
	return rules
}

// file, title button, card, what the card does on hover, the card's keyboard focus outline
const CARDS = [
	['CourseList.vue', '.course-card-open', '.course-card', /border-color:\s*var\(--color-primary-element\)/],
	['InstructorDashboard.vue', '.card-open', '.dashboard-course-card', /border-color:\s*var\(--color-primary-element\)/],
	['PoolList.vue', '.pool-card-open', '.pool-card', /box-shadow:/],
	// #79 (18.11. 11:19): the pool rows of a course's Pools tab (instructor view).
	['CourseTabLernraum.vue', '.pool-item-open', '.pool-item', /box-shadow:/],
]
const CASES = CARDS.flatMap(([file, title]) => [':hover', ':focus', ':active'].map((state) => [file, title, state]))

describe('card titles have no square hover block (GitHub #79)', () => {
	it.each(CASES)('%s: %s clears the core button background on %s', (file, title, state) => {
		const rule = ownRules(file)[title + state]
		expect(rule, `${file}: ${title}${state}`).toBeDefined()
		expect(rule).toMatch(/background:\s*none/)
	})

	it.each(CARDS)('%s: the card keeps its own hover reaction and the keyboard focus outline', (file, title, card, reaction) => {
		const rules = ownRules(file)
		expect(rules[card + ':hover']).toMatch(reaction)
		expect(rules[`${card}:has(${title}:focus-visible)`]).toMatch(/outline:/)
	})
})
