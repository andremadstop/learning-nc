/**
 * The content languages (5.18.0): one list in the frontend, equal to the server's
 * TranslationService::ALLOWED_LANGS, and no component keeps its own copy. fr and uk were missing
 * in six hard-coded copies of the list, so French and Ukrainian translations could be neither
 * entered (translation dialog) nor chosen as content language (settings), nor shown as a pool
 * language (course view).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

import { CONTENT_LANGUAGES, contentLanguageOptions, contentLanguageOrEmpty } from '../../src/utils/contentLanguages.js'

const APP = resolve(__dirname, '../..')

function serverList() {
	const php = readFileSync(join(APP, 'lib/Service/TranslationService.php'), 'utf8')
	const m = php.match(/public const ALLOWED_LANGS = \[([^\]]*)\]/)
	expect(m, 'TranslationService::ALLOWED_LANGS found').toBeTruthy()
	return [...m[1].matchAll(/'([a-z]+)'/g)].map((x) => x[1])
}

function sources(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return sources(path)
		return /\.(js|ts|vue)$/.test(name) ? [path] : []
	})
}

describe('content languages', () => {
	it('are the server\'s translation languages, the six interface languages', () => {
		expect([...CONTENT_LANGUAGES]).toEqual(serverList())
		expect([...CONTENT_LANGUAGES]).toEqual(['de', 'en', 'fr', 'ru', 'ar', 'uk', 'zh'])
	})

	it('have a label each, Arabic right to left', () => {
		expect(contentLanguageOptions()).toEqual([
			{ key: 'de', label: 'German (Deutsch)' },
			{ key: 'en', label: 'English' },
			{ key: 'fr', label: 'French' },
			{ key: 'ru', label: 'Russian' },
			{ key: 'ar', label: 'Arabic', dir: 'rtl' },
			{ key: 'uk', label: 'Ukrainian' },
			{ key: 'zh', label: 'Chinese (Simplified)' },
		])
	})

	it('map anything else to original content', () => {
		expect(contentLanguageOrEmpty('uk')).toBe('uk')
		expect(contentLanguageOrEmpty('es')).toBe('')
		expect(contentLanguageOrEmpty(undefined)).toBe('')
	})

	it('are not hard-coded again anywhere in src/', () => {
		const copies = sources(join(APP, 'src'))
			.filter((path) => /\[\s*'de',\s*'en',\s*'ru',\s*'ar'/.test(readFileSync(path, 'utf8')))
			.map((path) => path.slice(APP.length + 1))
		expect(copies).toEqual([])
	})
})
