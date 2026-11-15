/**
 * Persona sweep (GitHub #36): the course's sub-tab bar announced "Lernraum Bereiche" to screen
 * readers in the English UI. en.json (and fr.json) carried the German key as its own value.
 * The four i18n gates only compare the catalogues with each other and with the source, so a value
 * that is the German key passes all of them.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const load = (lang) => JSON.parse(readFileSync(resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations
const en = load('en')

// The same heuristic as scripts/persona-sweep: umlauts / ß or common German words.
const GERMAN = /[äöüÄÖÜß]|\b(und|oder|nicht|für|mit|eine?|einen|werden|wird|sind|ist|Kurs|Kurse|Prüfung|Fragen?|Einstellungen|Speichern|Abbrechen|Lernende[nr]?|Zertifikat|Bereiche|Werkzeuge|keine?|alle|noch|jetzt|bitte|Teilnehmer|Gruppe|Übersicht|zurück|Bericht|Lernraum|Verwaltung|Kommunikation|Wettbewerb|herunterladen)\b/
// Proper names of the two board games, deliberately not translated.
const NAMES = /Lernwürfel|Wissenssturm/g

describe('the English catalogue contains no German (persona sweep, GitHub #36)', () => {
	it('no English value reads as German', () => {
		const german = Object.entries(en)
			.filter(([, v]) => typeof v === 'string' && GERMAN.test(v.replace(NAMES, '')))
			.map(([k]) => k)
		expect(german).toEqual([])
	})

	it.each(['en', 'fr', 'ru', 'ar', 'uk'])('%s: the sub-tab bar of the learning space has a translated label', (lang) => {
		const value = load(lang)['Lernraum Bereiche']
		expect(value).toBeTruthy()
		expect(value).not.toBe('Lernraum Bereiche')
		expect(value).not.toMatch(/Lernraum/)
	})
})
