/**
 * Audit #194: VirtuProfDock shows dockLabel.kicker/title through t('learning', <value>). None of the
 * values ('Lernassistentin', 'Netzwerk', 'Endanwender' …) was in a catalogue, so they rendered
 * German in every language, and the coverage gate cannot see a t() call with a variable key.
 * This test is that gate for the dock labels.
 */
import { describe, expect, it } from 'vitest'
import { CHARACTERS, DOCK_LABEL_NAMES, FALLBACK_CHARACTER } from '../../src/data/characters.js'
import { catalogue } from './support/l10nCatalog.js'

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']

function dockLabelValues() {
	const values = new Set()
	for (const character of [...Object.values(CHARACTERS), FALLBACK_CHARACTER]) {
		if (!character.dockLabel) continue
		values.add(character.dockLabel.kicker)
		values.add(character.dockLabel.title)
	}
	return [...values].filter(Boolean)
}

describe('VirtuProfDock labels', () => {
	it('every kicker and title is a name or a key in all six catalogues', () => {
		const missing = []
		for (const value of dockLabelValues()) {
			if (DOCK_LABEL_NAMES.includes(value)) continue
			for (const lang of LANGS) {
				if (!(value in catalogue(lang))) missing.push(`${lang}: ${value}`)
			}
		}
		expect(missing).toEqual([])
	})

	it('the default skin label is translated, not German, for an English reader', () => {
		expect(catalogue('en')[FALLBACK_CHARACTER.dockLabel.title]).toBe('Learning assistant')
		expect(catalogue('en')[CHARACTERS.nova.dockLabel.title]).toBe('Learning assistant')
	})

	it('writes German labels with umlauts, not ae/oe/ue', () => {
		const transliterated = dockLabelValues().filter((value) => /ae|oe|ue/.test(value) && !DOCK_LABEL_NAMES.includes(value))
		expect(transliterated).toEqual([])
	})
})
