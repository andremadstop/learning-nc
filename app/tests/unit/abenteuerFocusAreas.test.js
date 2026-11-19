/**
 * Adventure: the focus tags on the campaign cards showed the raw ids of the campaign files
 * (`network_fundamentals`, `incident-response`). focusAreaLabel() gives each a readable, translated
 * label; an id without one is humanised, never shown raw.
 *
 * Every focus id the shipped campaigns use (data/campaigns, data/epochs/campaigns, the fallback
 * list) must have an explicit label, so a new campaign with a new tag turns this test red instead
 * of slipping through the fallback.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { catalogue, setTestLanguage } from './support/l10nCatalog.js'
import { buildStaticCampaigns, focusAreaLabel, knownFocusAreas, normalizeFocusArea } from '../../src/utils/abenteuerCatalog.js'

const APP = path.resolve(__dirname, '../..')

function shippedFocusAreas() {
	const found = new Map()
	for (const dir of ['data/campaigns', 'data/epochs/campaigns']) {
		for (const file of fs.readdirSync(path.join(APP, dir)).filter((f) => f.endsWith('.json') && f !== 'campaign-schema.json')) {
			const data = JSON.parse(fs.readFileSync(path.join(APP, dir, file), 'utf8'))
			for (const tag of data.focus_areas ?? []) found.set(tag, `${dir}/${file}`)
		}
	}
	setTestLanguage('de')
	for (const c of buildStaticCampaigns()) for (const tag of c.focus_areas) found.set(tag, `fallback ${c.id}`)
	return found
}

afterEach(() => setTestLanguage('de'))

describe('Adventure focus tags', () => {
	const shipped = shippedFocusAreas()

	it('the scan finds the tags of the shipped campaigns', () => {
		expect(shipped.size).toBeGreaterThanOrEqual(20)
		expect(shipped.has('network_fundamentals')).toBe(true)
		expect(shipped.has('incident-response')).toBe(true)
	})

	it('every shipped focus id has an explicit label', () => {
		const known = new Set(knownFocusAreas())
		const missing = [...shipped].filter(([tag]) => !known.has(normalizeFocusArea(tag))).map(([tag, where]) => `${tag} (${where})`)
		expect(missing).toEqual([])
	})

	it('no label is a raw id: no "_", no lower-case hyphenated words', () => {
		for (const lang of ['de', 'en', 'fr', 'ru', 'ar', 'uk']) {
			setTestLanguage(lang)
			for (const tag of shipped.keys()) {
				const label = focusAreaLabel(tag)
				expect(label, `${lang} ${tag}`).not.toMatch(/_/)
				expect(label, `${lang} ${tag}`).not.toMatch(/^[a-z]+(-[a-z]+)+$/)
				expect(label, `${lang} ${tag}`).not.toBe(tag)
			}
		}
	})

	it('both spellings of an id give the same label', () => {
		expect(focusAreaLabel('incident-response')).toBe(focusAreaLabel('incident_response'))
		expect(focusAreaLabel('Port-Scanning')).toBe(focusAreaLabel('port_scanning'))
	})

	it('the labels are translated into every interface language', () => {
		const german = Object.fromEntries(knownFocusAreas().map((k) => [k, focusAreaLabel(k)]))
		for (const label of Object.values(german)) {
			for (const lang of ['en', 'fr', 'ru', 'ar', 'uk']) expect(catalogue(lang)[label], `${lang}: ${label}`).toBeTruthy()
		}
		setTestLanguage('en')
		expect(focusAreaLabel('network_fundamentals')).toBe('Networking fundamentals')
		expect(focusAreaLabel('incident-response')).toBe('Incident response')
		setTestLanguage('ru')
		expect(focusAreaLabel('threat_analysis')).toBe('Анализ угроз')
	})

	it('names the skill, not a product: the packet analysis tag', () => {
		setTestLanguage('de')
		expect(focusAreaLabel('wireshark')).toBe('Paketanalyse')
		for (const lang of ['de', 'en', 'fr', 'ru', 'ar', 'uk']) {
			setTestLanguage(lang)
			for (const k of knownFocusAreas()) expect(focusAreaLabel(k), `${lang} ${k}`).not.toMatch(/wireshark/i)
		}
	})

	it('an unknown id is humanised, never shown raw', () => {
		expect(focusAreaLabel('cloud_security')).toBe('Cloud security')
		expect(focusAreaLabel('zero-trust')).toBe('Zero trust')
		expect(focusAreaLabel('')).toBe('')
		expect(focusAreaLabel(null)).toBe('')
	})

	it('the Adventure cards render the label, not the raw id', () => {
		const source = fs.readFileSync(path.join(APP, 'src/components/AbenteuerMode.vue'), 'utf8')
		const tags = source.match(/<span v-for="area in campaign\.focus_areas"[^>]*>[^<]*<\/span>/g) ?? []
		expect(tags).toHaveLength(2)
		for (const tag of tags) expect(tag).toContain('{{ focusAreaLabel(area) }}')
	})
})
