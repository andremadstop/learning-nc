/**
 * GitHub #34: the shared JSON import cases (app/tests/fixtures/import-json-cases.json) through
 * the import preview. PHPUnit runs the same file through the real ImportController::importJson()
 * (ImportJsonFixturesTest.php), so what the preview promises and what the server stores cannot
 * drift apart. Counting rules are those of the fixture's `_doc`; error texts are never compared.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
}))

import { analyzeJsonImport } from '../../src/utils/importPreview.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(readFileSync(resolve(here, '../fixtures/import-json-cases.json'), 'utf-8'))
const OPTIONAL = ['difficulty', 'chapter_key', 'chapter_title', 'chapter_order', 'exam_key', 'subtype']

/** Same counting as ImportJsonFixturesTest::counts(): a shape error is 0 importable, 1 error. */
function counts(result) {
	if (result.error !== undefined) {
		return [0, 1]
	}
	return [result.importable, result.errors.length]
}

describe('import preview runs the shared fixture cases (GitHub #34)', () => {
	it('the fixture holds enough cases', () => {
		expect(fixture.cases.length).toBeGreaterThanOrEqual(15)
	})

	it.each(fixture.cases.map((c) => [c.name, c]))('%s', (_name, { input, expect: want }) => {
		const result = analyzeJsonImport(JSON.stringify(input))
		const [importable, errors] = counts(result)
		expect(importable, JSON.stringify(result)).toBe(want.importable)
		expect(errors, JSON.stringify(result)).toBe(want.errors)

		const stored = result.error !== undefined ? [] : result.items.filter((i) => i.error === null).map((i) => i.question)
		expect(stored).toHaveLength(want.questions.length)
		want.questions.forEach((q, i) => {
			expect(stored[i].text, `question ${i} text`).toBe(q.text)
			expect(stored[i].type, `question ${i} type`).toBe(q.type)
			expect(stored[i].answers, `question ${i} answers`).toEqual(q.answers)
			for (const field of OPTIONAL) {
				if (field in q) {
					expect(stored[i][field], `question ${i} ${field}`).toBe(q[field])
				}
			}
		})
	})
})
