import { describe, expect, it } from 'vitest'

import { buildPrivacyCategoryGroups, getPrivacyInfoData } from '../../src/utils/privacyInfo.js'

describe('privacy info grouping', () => {
	it('derives the 7 named privacy groups from privacy-info.json', () => {
		const groups = buildPrivacyCategoryGroups(getPrivacyInfoData())

		expect(groups.map((group) => group.id)).toEqual([
			'learning',
			'ai',
			'social',
			'audit',
			'gamification',
			'assessment',
			'external',
		])
	})

	it('keeps Telos and KI transfer entries inside the combined ai group', () => {
		const groups = buildPrivacyCategoryGroups(getPrivacyInfoData())
		const aiGroup = groups.find((group) => group.id === 'ai')

		expect(aiGroup).toBeDefined()
		expect(aiGroup.entries).toHaveLength(2)
		expect(aiGroup.entries.map((entry) => entry.name)).toEqual(
			expect.arrayContaining([
				'Persoenlichkeitsprofil (Telos)',
				'KI-Interaktion (Drittland-Transfer)',
			]),
		)
	})
})
