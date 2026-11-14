/**
 * GitHub #52: the Adventure tab showed German character roles, descriptions and fallback
 * campaigns in an English interface. They now come through the catalogue; the campaign stories
 * themselves are German-only files, and the tab says so outside German.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { setTestLanguage } from './support/l10nCatalog.js'
import { buildAdventureCharacters, buildStaticCampaigns } from '../../src/utils/abenteuerCatalog.js'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/components/AbenteuerMode.vue'), 'utf8')

afterEach(() => setTestLanguage('de'))

describe('Adventure characters and fallback campaigns (GitHub #52)', () => {
	it('are translated into every interface language', () => {
		for (const lang of ['en', 'fr', 'ru', 'ar', 'uk']) {
			setTestLanguage('de')
			const german = [...buildAdventureCharacters(), ...buildStaticCampaigns()]
			setTestLanguage(lang)
			const translated = [...buildAdventureCharacters(), ...buildStaticCampaigns()]
			for (const [i, item] of translated.entries()) {
				for (const field of ['role', 'description', 'strength', 'weakness', 'title']) {
					if (!(field in item) || item.id === 'sysadmin' && field === 'role' || item.id === 'ransomware' && field === 'title' && lang === 'en') continue
					expect(item[field], `${lang} ${item.id}.${field}`).not.toBe(german[i][field])
				}
			}
		}
	})

	it('keep ids, names and icons whatever the language', () => {
		setTestLanguage('en')
		expect(buildAdventureCharacters().map((c) => c.id)).toEqual(['architect', 'security', 'sysadmin', 'helpdesk'])
		expect(buildAdventureCharacters()[0].name).toBe('Alex "The Architect" Neumann')
		expect(buildStaticCampaigns().find((c) => c.id === 'grosser_ausfall').is_graph).toBe(true)
	})

	it('are no longer German constants in the component', () => {
		expect(source).not.toContain('const CHARACTERS')
		expect(source).not.toContain('const STATIC_CAMPAIGNS')
		expect(source).not.toContain('aria-label="Abbrechen bestätigen"')
		expect(source).toContain("t('learning', 'Die Geschichten der Kampagnen gibt es bisher nur auf Deutsch.')")
	})
})
