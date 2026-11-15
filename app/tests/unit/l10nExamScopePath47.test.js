import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Review of GitHub #47: the pool-rules hint names the menu path to the course default
// ("Verwaltung → Kursregeln → Zertifizierung"). In uk and ar it used words that are on no
// screen (Керування; قواعد الدورة ← الشهادة), so the instructor could not find the place.
// Every segment must be the translation of the label the course tab really shows.
const catalog = (lang, ext) => {
	const raw = readFileSync(resolve(__dirname, `../../l10n/${lang}.${ext}`), 'utf8')
	if (ext === 'json') return JSON.parse(raw).translations
	const body = raw.slice(raw.indexOf('{'), raw.lastIndexOf('},') + 1)
	return JSON.parse(body.replace(/,\s*}$/, '}'))
}

const HINT = 'Gilt nur für Prüfungen in diesem Kurs. Die Fragen werden bei jedem Versuch zufällig aus dem Pool gezogen. Leere Felder übernehmen die Kurs-Vorgabe aus Verwaltung → Kursregeln → Zertifizierung; ohne Vorgabe richtet sich die Prüfung wie bisher nach der Poolgröße.'

describe('the exam-scope hint names the on-screen menu path (review of GitHub #47)', () => {
	for (const ext of ['json', 'js']) {
		for (const lang of ['de', 'fr', 'ru', 'ar', 'uk']) {
			it(`${lang}.${ext}`, () => {
				const tr = catalog(lang, ext)
				const label = (key) => tr[key] || key
				const arrow = lang === 'ar' ? ' ← ' : ' → '
				const path = ['Verwaltung', 'Kursregeln', 'Zertifizierung'].map(label).join(arrow)
				expect(tr[HINT]).toContain(path)
			})
		}
	}
})
