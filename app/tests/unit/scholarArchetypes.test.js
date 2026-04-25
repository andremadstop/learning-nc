import { describe, expect, it } from 'vitest'

import { CHARACTERS, getCharacter } from '../../src/data/characters.js'

const SCHOLAR_IDS = ['theoretiker', 'kosmologe', 'popularisierer']

describe('Phase 152 scholar archetype registry contract', () => {
	it('defines all three scholar archetype ids', () => {
		for (const id of SCHOLAR_IDS) {
			expect(CHARACTERS[id]).toBeDefined()
			expect(getCharacter(id).id).toBe(id)
		}
	})

	it('marks scholars as user-selectable scholar skins', () => {
		for (const id of SCHOLAR_IDS) {
			expect(CHARACTERS[id]).toMatchObject({
				user_selectable: true,
				category: 'scholar',
				preview_thumbnail_svg: null,
			})
		}
	})

	it('ships each scholar with minimum animation state coverage', () => {
		for (const id of SCHOLAR_IDS) {
			expect(CHARACTERS[id].states).toEqual(expect.arrayContaining([
				'idle',
				'wave',
				'celebrate',
			]))
		}
	})

	it('uses safe archetype labels without real-person names', () => {
		const forbidden = /einstein|hawking|tyson|neil degrasse|cosmos|startalk/i

		for (const id of SCHOLAR_IDS) {
			const character = CHARACTERS[id]
			const haystack = [
				character.id,
				character.name,
				character.role,
				character.personality,
				character.silhouette,
			].join(' ')

			expect(haystack).not.toMatch(forbidden)
		}
	})
})
