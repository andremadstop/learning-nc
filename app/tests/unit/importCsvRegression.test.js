/**
 * GitHub #65: the CSV readers read the shipped examples and a set of hand-written CSV texts
 * exactly as before the three CSV findings were fixed (app/tests/Fixtures/import-csv-regression.json,
 * recorded from the old readers). PHPUnit runs the same file through the real
 * ImportController::importCsv() (ImportCsvRegressionTest.php). Error texts are not compared.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
}))

import { analyzeCsvImport } from '../../src/utils/importPreview.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(readFileSync(resolve(here, '../Fixtures/import-csv-regression.json'), 'utf-8'))

/** What the fixture records of a result. */
function recorded(result) {
	if (result.error !== undefined) {
		return { error: true }
	}
	return {
		importable: result.importable,
		errors: result.errors.length,
		warnings: result.warnings.length,
		items: result.items.map((i) => ({ ok: i.error === null, question: i.question })),
	}
}

describe('CSV import reads the recorded files as before (GitHub #65)', () => {
	it('the fixture covers both examples and enough cases', () => {
		expect(Object.keys(fixture.files).sort()).toEqual(['comptia-security-plus.csv', 'general-knowledge.csv'])
		expect(fixture.cases.length).toBeGreaterThanOrEqual(20)
	})

	it.each(Object.entries(fixture.files))('example %s', (file, want) => {
		const csv = readFileSync(resolve(here, '../../examples', file), 'utf-8')
		expect(recorded(analyzeCsvImport(csv))).toEqual(want)
	})

	it.each(fixture.cases.map((c) => [c.name, c]))('%s', (_name, { csv, expect: want }) => {
		expect(recorded(analyzeCsvImport(csv))).toEqual(want)
	})
})
