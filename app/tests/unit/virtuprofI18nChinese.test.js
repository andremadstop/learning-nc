import { afterEach, describe, expect, it } from 'vitest'

import { zh_CN as zhCatalogue } from '../../src/l10n/virtuprof-strings.js'
import { detectVirtuProfLanguage, translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'

/**
 * GitHub #105: Simplified Chinese. The Nextcloud catalogue is l10n/zh_CN.json, while
 * <html lang> carries "zh-CN" and the backend stores the base code "zh". Every spelling
 * must reach the same dictionary; a spelling missing from ALLOWED falls back to the
 * detected language or English without any error.
 */
describe('VirtuProf Simplified Chinese', () => {
	const expected = zhCatalogue['Close panel']

	afterEach(() => {
		document.documentElement.lang = ''
	})

	it('has a real Chinese value to compare against', () => {
		expect(expected).toMatch(/\p{Script=Han}/u)
	})

	it.each(['zh', 'zh_CN', 'zh-CN', 'ZH_cn'])('translates for the spelling %s', (lang) => {
		expect(translateVirtuProf(lang, 'Close panel')).toBe(expected)
	})

	it('detects Chinese from the Nextcloud page language', () => {
		document.documentElement.lang = 'zh-CN'
		expect(detectVirtuProfLanguage()).toBe('zh')
		expect(translateVirtuProf('', 'Close panel')).toBe(expected)
	})
})
