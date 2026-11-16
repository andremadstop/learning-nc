/**
 * Review C5: the Arabic catalog quotes with « » (as its older strings do); the strings new in
 * 5.14.0 had taken over the German „…“, which look out of place in right-to-left text.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const ar = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/ar.json'), 'utf8')).translations

const KEYS = [
	'„{title}“ löschen',
	'„{title}“ wurde gelöscht.',
	'„{title}“ wurde hinzugefügt.',
	'_„{title}“ wurde gelöscht. %n Kurs trägt jetzt wieder das Logo dieser Nextcloud._::_„{title}“ wurde gelöscht. %n Kurse tragen jetzt wieder das Logo dieser Nextcloud._',
	'„{name}“ wird optional. Wird die Datei später wieder Pflicht, müssen alle Lernenden sie erneut als gelesen bestätigen. Bisherige Bestätigungen bleiben im Audit-Protokoll. Fortfahren?',
	'Leer lassen für „{name}“ (rechtlicher Name aus den Betreiberangaben, sonst der Name dieser Nextcloud). Der Name wird in neue Zertifikate signiert und steht auch auf der öffentlichen Prüfseite.',
]

describe('Arabic quotation marks', () => {
	it.each(KEYS)('%s uses « »', (key) => {
		const values = [ar[key]].flat()
		expect(values.length).toBeGreaterThan(0)
		for (const value of values) {
			expect(value).not.toMatch(/[„“]/)
			expect(value).toMatch(/«[^»]+»/)
		}
	})
})
