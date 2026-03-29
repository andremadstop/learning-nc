import { describe, it, expect } from 'vitest'
import fs from 'fs'

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

const privacyInfo = readJson('../../data/privacy-info.json')

describe('privacy-info.json', () => {
	const requiredFields = ['name', 'items', 'purpose', 'who_sees', 'retention', 'legal_basis']

	it('each category has all required fields', () => {
		for (const category of privacyInfo.categories) {
			for (const field of requiredFields) {
				expect(category, `Category "${category.name}" missing field "${field}"`).toHaveProperty(field)
				expect(category[field], `Category "${category.name}" has empty "${field}"`).not.toBe('')
			}
		}
	})

	const namedCategoryMap = {
		learning: 'Lerndaten',
		ai: 'Persoenlichkeitsprofil|KI-Interaktion',
		social: 'Schwarm',
		audit: 'Audit',
		gamification: 'Gamification',
		assessment: 'Pruefung',
		external: 'Externe',
	}

	it.each(Object.entries(namedCategoryMap))(
		'covers named category "%s" (matching: %s)',
		(namedType, pattern) => {
			const regex = new RegExp(pattern)
			const match = privacyInfo.categories.find(c => regex.test(c.name))
			expect(match, `No category name matches pattern "${pattern}" for named type "${namedType}"`).toBeDefined()
		},
	)

	it('has at least 8 category entries (7 named types, ai has two entries)', () => {
		expect(privacyInfo.categories.length).toBeGreaterThanOrEqual(8)
	})
})
