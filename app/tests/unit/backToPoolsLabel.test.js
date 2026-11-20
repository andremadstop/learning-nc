/**
 * The "Back to pools" button in an open pool (App.vue) is named by aria-label "Zurück zu Pools" and
 * shows "← Zurück zu Pools". axe (label-content-name-mismatch) wants the visible text inside the
 * name: in Arabic, French and Russian the visible key was still English while the name was
 * translated (persona sweep, Arabic). Every language's visible text must contain its name.
 */
import { describe, expect, it } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'

const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk']
const catalog = (lang) => JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../l10n/${lang}.json`), 'utf8')).translations

describe('"← Zurück zu Pools" carries the translated name', () => {
	it.each(LANGS)('%s', (lang) => {
		const t = catalog(lang)
		const name = t['Zurück zu Pools']
		const visible = t['← Zurück zu Pools']
		expect(name).toBeTruthy()
		expect(visible).toBeTruthy()
		expect(visible.replace(/^←\s*/, '')).toBe(name)
	})
})
