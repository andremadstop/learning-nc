/**
 * A @nextcloud/l10n stand-in that answers from the real app catalogues (l10n/<lang>.json).
 *
 * The usual mock `translate: (app, s) => s` returns its input, so a test against it passes just as
 * well when the component never calls t() at all, or calls it with a key no catalogue has. Backed
 * by the catalogue, a string only comes out translated if both are true.
 *
 * Usage:
 *   vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())
 *   import { setTestLanguage } from './support/l10nCatalog.js'
 *   setTestLanguage('fr')
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const L10N_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '../../../l10n')
const cache = {}
let language = 'de'

export function catalogue(lang) {
	if (!cache[lang]) {
		cache[lang] = JSON.parse(readFileSync(resolve(L10N_DIR, `${lang}.json`), 'utf8')).translations
	}
	return cache[lang]
}

export function setTestLanguage(lang) {
	language = lang
}

export function translate(app, text, vars = {}) {
	const translated = catalogue(language)[text] ?? text
	return translated.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? String(vars[key]) : match))
}

export function l10nMock() {
	return {
		translate,
		translatePlural: (app, single, plural, count, vars = {}) => translate(app, count === 1 ? single : plural, { ...vars, n: count }),
		getLanguage: () => language,
		getLocale: () => language,
		getCanonicalLocale: () => language,
	}
}
