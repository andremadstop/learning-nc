/**
 * Persona sweep C9b (pre-existing): the position number "#n" next to "Question 1 of n" in Training
 * was --color-text-maxcontrast at opacity 0.6 on the hover background — 2.33:1 in light, 2.98:1 in
 * dark (axe, WCAG 1.4.3). maxcontrast is the lightest grey that keeps 4.5:1; any opacity breaks it.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/TrainingMode.vue'), 'utf8')

describe('Training: the question position keeps its contrast', () => {
	it('.question-db-id is maxcontrast without opacity', () => {
		const style = SOURCE.slice(SOURCE.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '')
		const rule = style.match(/\.question-db-id\s*\{([^}]*)\}/)
		expect(rule, '.question-db-id rule').not.toBeNull()
		expect(rule[1]).toMatch(/color:\s*var\(--color-text-maxcontrast\)/)
		expect(rule[1]).not.toMatch(/opacity\s*:/)
	})
})
