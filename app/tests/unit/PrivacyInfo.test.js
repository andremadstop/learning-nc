import { describe, it, expect } from 'vitest'
import fs from 'fs'

import { buildPrivacyCategories } from '../../src/utils/privacyInfo.js'

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

const privacyInfo = readJson('../../data/privacy-info.json')
// GitHub #38: the category texts left the German-only JSON for literal t() calls
const categories = buildPrivacyCategories()

describe('privacy categories', () => {
	const requiredFields = ['id', 'group', 'name', 'items', 'purpose', 'who_sees', 'retention', 'legal_basis']

	it('each category has all required fields', () => {
		for (const category of categories) {
			for (const field of requiredFields) {
				expect(category, `Category "${category.name}" missing field "${field}"`).toHaveProperty(field)
				expect(category[field], `Category "${category.name}" has empty "${field}"`).not.toBe('')
			}
		}
	})

	it('ids are unique (the page keys its entries by them)', () => {
		const ids = categories.map((c) => c.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it.each(['learning', 'ai', 'social', 'audit', 'gamification', 'assessment', 'external'])(
		'covers the named group "%s"',
		(group) => {
			expect(categories.some((c) => c.group === group), `No category in group "${group}"`).toBe(true)
		},
	)

	it('has at least 8 category entries (7 named types, ai has two entries)', () => {
		expect(categories.length).toBeGreaterThanOrEqual(8)
	})

	it('privacy-info.json keeps only the version, no German texts', () => {
		expect(privacyInfo.version).toBe('1.3')
		expect(privacyInfo.categories).toBeUndefined()
		expect(privacyInfo.rights).toBeUndefined()
		expect(privacyInfo.ai_notice).toBeUndefined()
	})
})
