/**
 * Persona sweep C9b (axe "label", critical, pre-existing): in the question editor the radio button
 * or checkbox that marks an answer as right had no name — a screen reader said only "radio button,
 * not checked", not which answer it belongs to. Each now reads "Antwort {n} ist richtig".
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/QuestionForm.vue'), 'utf8')
const TEMPLATE = SOURCE.slice(0, SOURCE.indexOf('<script'))

describe('QuestionForm: the "right answer" toggles are named', () => {
	it('every NcCheckboxRadioSwitch without a label text carries an aria-label', () => {
		const selfClosing = [...TEMPLATE.matchAll(/<NcCheckboxRadioSwitch\b[^>]*?\/>/g)].map((m) => m[0])
		expect(selfClosing.length).toBeGreaterThanOrEqual(2)
		for (const tag of selfClosing) {
			expect(tag).toMatch(/:aria-label="t\('learning', 'Antwort \{n\} ist richtig', \{ n: index \+ 1 \}\)"/)
		}
	})
})
