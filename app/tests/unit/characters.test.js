import { describe, expect, it } from 'vitest'

import { CHARACTERS, getCharacter } from '../../src/data/characters.js'

const CAMPAIGN_CHARACTER_IDS = [
	'architect',
	'security',
	'sysadmin',
	'helpdesk',
	'ghostline',
	'klaus_dau',
	'dr_hartmann',
	'frau_weber',
	'uschi',
	'tim_azubi',
	'sven_berater',
]

const SELECTABLE_CHARACTER_IDS = [
	'kosmologe',
	'nova',
	'popularisierer',
	'prof_lern_classic',
	'theoretiker',
]

describe('characters registry metadata', () => {
	it('adds picker metadata fields to every exported character', () => {
		for (const character of Object.values(CHARACTERS)) {
			expect(typeof character.user_selectable).toBe('boolean')
			expect(typeof character.category).toBe('string')
			expect(character).toHaveProperty('preview_thumbnail_svg')
			expect(
				character.preview_thumbnail_svg === null
					|| typeof character.preview_thumbnail_svg === 'string',
			).toBe(true)
		}
	})

	it('keeps campaign characters hidden from the picker by default', () => {
		for (const id of CAMPAIGN_CHARACTER_IDS) {
			expect(CHARACTERS[id].user_selectable).toBe(false)
			expect(CHARACTERS[id].category).toBe('campaign')
			expect(CHARACTERS[id].preview_thumbnail_svg).toBeNull()
		}
	})

	it('keeps existing campaign character core fields unchanged', () => {
		expect(getCharacter('architect')).toMatchObject({
			role: 'Netzwerk-Architekt',
			silhouette: 'architect',
			palette: {
				primary: 'var(--lnc-cyan)',
				accent: 'var(--lnc-primary)',
				glow: 'var(--lnc-amber)',
			},
			states: ['idle', 'thinking', 'alert', 'celebrate'],
			campaignAppearances: ['netzwerk_grundlagen', 'grosser_ausfall'],
		})
		expect(getCharacter('sven_berater')).toMatchObject({
			role: 'Externer Consultant',
			silhouette: 'sven_berater',
			states: ['idle', 'confused', 'frustrated', 'relieved', 'impressed'],
			campaignAppearances: ['grosser_ausfall'],
		})
	})

	it('marks nova as the selectable hero skin', () => {
		expect(CHARACTERS.nova).toMatchObject({
			user_selectable: true,
			category: 'hero',
			preview_thumbnail_svg: null,
		})
	})

	it('adds prof_lern_classic as a selectable classic skin', () => {
		expect(CHARACTERS.prof_lern_classic).toMatchObject({
			id: 'prof_lern_classic',
			name: 'Prof. Lern',
			role: 'Klassischer Lernbegleiter',
			silhouette: 'prof_lern',
			states: ['idle', 'wave', 'thinking'],
			campaignAppearances: [],
			user_selectable: true,
			category: 'classic',
			preview_thumbnail_svg: null,
		})
	})

	it('exposes shipped selectable skins as picker options', () => {
		const selectable = Object.values(CHARACTERS)
			.filter((character) => character.user_selectable)
			.map((character) => character.id)
			.sort()

		expect(selectable).toEqual(SELECTABLE_CHARACTER_IDS.sort())
	})
})
