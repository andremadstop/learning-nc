import { describe, it, expect } from 'vitest'
import fs from 'fs'

function readJson(relativePath) {
	return JSON.parse(
		fs.readFileSync(new URL(relativePath, import.meta.url), 'utf8'),
	)
}

const deRaw = readJson('../../l10n/de.json')
const enRaw = readJson('../../l10n/en.json')
const deJson = deRaw.translations || deRaw
const enJson = enRaw.translations || enRaw

const BADGE_IDS = [
	'pioneer',
	'streak_7',
	'streak_14',
	'mastermind',
	'exam_ready',
	'simulator',
	'weekend',
	'swarm',
	'trouble_fixer',
	'quick_thinker',
]

describe('Badge l10n completeness', () => {
	it.each(BADGE_IDS)('badge "%s" has name and desc keys in de.json', (badgeId) => {
		const nameKey = `badge_${badgeId}_name`
		const descKey = `badge_${badgeId}_desc`

		expect(deJson, `Missing ${nameKey} in de.json`).toHaveProperty(nameKey)
		expect(deJson, `Missing ${descKey} in de.json`).toHaveProperty(descKey)
		expect(deJson[nameKey], `${nameKey} is empty in de.json`).not.toBe('')
		expect(deJson[descKey], `${descKey} is empty in de.json`).not.toBe('')
	})

	it.each(BADGE_IDS)('badge "%s" has name and desc keys in en.json', (badgeId) => {
		const nameKey = `badge_${badgeId}_name`
		const descKey = `badge_${badgeId}_desc`

		expect(enJson, `Missing ${nameKey} in en.json`).toHaveProperty(nameKey)
		expect(enJson, `Missing ${descKey} in en.json`).toHaveProperty(descKey)
		expect(enJson[nameKey], `${nameKey} is empty in en.json`).not.toBe('')
		expect(enJson[descKey], `${descKey} is empty in en.json`).not.toBe('')
	})

	it('covers all 10 non-legacy badges', () => {
		expect(BADGE_IDS).toHaveLength(10)
	})
})
