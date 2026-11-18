/**
 * Persona sweep C9 (pre-existing since the Participants tab was split out, Phase 112): a paused
 * question in "Weak questions" faded its whole row with `opacity: 0.5`. That took the question
 * text (3.15:1), its wrong rate (3.1:1) and the "Resume" button (3.15:1) below the 4.5:1 that
 * WCAG 1.4.3 asks for. Earlier sweeps never saw it because nobody had answered wrong; C9's learner
 * fails an exam on purpose (#87), and the sweep's click on "Pause" faded the row.
 * The paused row now keeps full opacity; only its question text is set in the theme's
 * --color-text-maxcontrast (made to hold 4.5:1 on the main background) and in italics.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

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

describe('Weak questions: a paused row keeps its contrast', () => {
	const rules = ownRules('CourseTabTeilnehmer.vue')
	const paused = Object.entries(rules).filter(([selector]) => selector.includes('.paused-row'))

	it('no rule on a paused row lowers the opacity', () => {
		for (const [selector, declarations] of paused) {
			const m = /opacity:\s*([\d.]+)/.exec(declarations)
			expect(m && Number(m[1]) < 1 ? `${selector} { opacity: ${m[1]} }` : null).toBeNull()
		}
	})

	it('the paused question text uses the theme colour that keeps 4.5:1', () => {
		expect(rules['.paused-row .q-text'] || '').toMatch(/color:\s*var\(--color-text-maxcontrast\)/)
	})

	it('the row is still marked as paused in the template', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/CourseTabTeilnehmer.vue'), 'utf8')
		expect(source).toMatch(/:class="\{ 'paused-row': q\.is_paused \}"/)
	})
})
