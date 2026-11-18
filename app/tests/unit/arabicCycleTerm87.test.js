/**
 * GitHub #84 / #87 (review): Arabic used the same word for the course (الدورة) and for the
 * certification cycle (دورة اعتماد, "دورة جديدة", once دورة شهادة). The member badge "Neuer Zyklus
 * offen" read "new course open", and the notification "a new accreditation course was opened in
 * the course". The cycle is جولة اعتماد in every key that names it; الدورة stays the course.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const catalogue = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/ar.json'), 'utf8')).translations
const cycleKeys = Object.keys(catalogue).filter((key) => /Zyklus|Zertifizierungszyklus/.test(key))

describe('Arabic certification cycle term', () => {
	it('there are cycle keys to check', () => {
		expect(cycleKeys.length).toBeGreaterThanOrEqual(15)
	})

	it.each(cycleKeys)('%s names the cycle جولة اعتماد, never دورة', (key) => {
		const value = [].concat(catalogue[key]).join(' ')
		expect(value).toContain('جولة')
		// دورة alone is the course ("في الدورة %s"); as the cycle it must not appear
		expect(value).not.toMatch(/دورة (اعتماد|شهادة|جديدة)|دورة الاعتماد|ودورة|توجد دورة/)
	})
})
