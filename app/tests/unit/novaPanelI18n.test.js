import { describe, expect, it } from 'vitest'

import NovaPanel from '../../src/components/nova/NovaPanel.vue'
import { translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'

/**
 * Codeberg #6: NovaPanel is the frame around the whole assistant — the header in the
 * reporter's screenshots. Its vt() helper called translateVirtuProf(key, params) while
 * the function signature is translateVirtuProf(lang, key, params). The key landed in the
 * lang slot, params (usually undefined) landed in the key slot, and the lookup resolved
 * to the empty string. Result: the panel kicker rendered blank and BOTH icon buttons
 * carried an empty aria-label and title, in every language including German.
 *
 * These assert against the real translateVirtuProf, not a mock — a mock with the wrong
 * argument order would have passed the broken code too.
 */
describe('NovaPanel translation wiring', () => {
	const vt = (language, key, params) => NovaPanel.methods.vt.call({ language }, key, params)

	it('resolves the panel kicker instead of returning an empty string', () => {
		expect(vt('uk', 'VirtuProf')).toBe('VirtuProf')
		expect(vt('uk', 'VirtuProf')).not.toBe('')
	})

	it('translates the action button labels into the panel language', () => {
		expect(vt('uk', 'Close panel')).toBe(translateVirtuProf('uk', 'Close panel'))
		expect(vt('uk', 'Close panel')).toBe('Закрити панель')
		expect(vt('de', 'Minimize panel')).toBe('Panel minimieren')
	})

	it('does not leak the key into the language slot', () => {
		// With the old argument order every call fell through to detectVirtuProfLanguage(),
		// so German and Ukrainian produced the SAME output. They must differ now.
		expect(vt('de', 'Close panel')).not.toBe(vt('uk', 'Close panel'))
	})

	it('declares a language prop so the parent can drive it', () => {
		expect(NovaPanel.props).toHaveProperty('language')
	})
})
