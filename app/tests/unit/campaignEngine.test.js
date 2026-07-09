import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

import {
	STATIC_CAMPAIGNS,
	FEATURED_CAMPAIGN_IDS,
	normalizeCampaignList,
	difficultyLabel,
} from '../../src/utils/campaignEngine.js'

describe('STATIC_CAMPAIGNS', () => {
	it('enthält nur Graph-Kampagnen mit Pflichtfeldern', () => {
		expect(STATIC_CAMPAIGNS.length).toBeGreaterThan(0)
		for (const c of STATIC_CAMPAIGNS) {
			expect(c.id).toBeTruthy()
			expect(c.title).toBeTruthy()
		}
	})
})

describe('normalizeCampaignList', () => {
	it('filtert Nicht-Graph-Kampagnen heraus', () => {
		const out = normalizeCampaignList([
			{ id: 'a', is_graph: true },
			{ id: 'b' },
			{ id: 'c', graph_mode: true },
		])
		expect(out.map(c => c.id).sort()).toEqual(['a', 'c'])
	})

	it('markiert featured Kampagnen und sortiert sie nach vorne', () => {
		const featuredId = FEATURED_CAMPAIGN_IDS[0]
		const out = normalizeCampaignList([
			{ id: 'zzz_normal', is_graph: true, title: 'ZZZ' },
			{ id: featuredId, is_graph: true, title: 'AAA featured' },
		])
		expect(out[0].id).toBe(featuredId)
		expect(out[0].is_featured).toBe(true)
		expect(out[1].is_featured).toBe(false)
	})

	it('sortiert nicht-featured alphabetisch nach Titel', () => {
		const out = normalizeCampaignList([
			{ id: 'b', is_graph: true, title: 'Bravo' },
			{ id: 'a', is_graph: true, title: 'Alpha' },
		])
		expect(out.map(c => c.title)).toEqual(['Alpha', 'Bravo'])
	})

	it('robust gegen Nicht-Array-Eingabe', () => {
		expect(normalizeCampaignList(null)).toEqual([])
		expect(normalizeCampaignList(undefined)).toEqual([])
	})

	it('übernimmt campaign_id als Fallback für id', () => {
		const out = normalizeCampaignList([{ campaign_id: 'x', is_graph: true }])
		expect(out[0].id).toBe('x')
	})
})

describe('difficultyLabel', () => {
	it('mappt bekannte Schwierigkeiten', () => {
		expect(difficultyLabel('beginner')).toBe('Einsteiger')
		expect(difficultyLabel('intermediate')).toBe('Fortgeschritten')
		expect(difficultyLabel('advanced')).toBe('Experte')
	})

	it('gibt unbekannte Werte unverändert zurück', () => {
		expect(difficultyLabel('legendary')).toBe('legendary')
	})
})
