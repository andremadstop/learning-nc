import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/utils/nova-audio-manager.js', () => ({
	novaAudio: {
		play: vi.fn(),
	},
}))

import { novaAudio } from '../../src/utils/nova-audio-manager.js'
import {
	EVENT_MAP,
	characterReactions,
	resolveReaction,
} from '../../src/utils/character-reaction-engine.js'
import { novaReactions } from '../../src/utils/nova-reaction-engine.js'

const NOVA_STATES = ['idle', 'talk', 'celebrate']

describe('resolveReaction', () => {
	it('returns a reaction object for every known event', () => {
		Object.keys(EVENT_MAP).forEach((eventType) => {
			const reaction = resolveReaction(eventType, NOVA_STATES)
			expect(reaction).not.toBeNull()
			expect(reaction).toHaveProperty('animation')
			expect(reaction).toHaveProperty('emotion')
			expect(reaction).toHaveProperty('sound')
			expect(reaction).toHaveProperty('duration')
		})
	})

	it('passes supported animations through unchanged', () => {
		expect(resolveReaction('answer-correct', NOVA_STATES)?.animation).toBe('talk')
	})

	it('falls back to idle when the skin does not support the requested state', () => {
		const reaction = resolveReaction('streak-5', ['idle'])
		expect(reaction?.animation).toBe('idle')
		expect(reaction?.emotion).toBe('celebrate')
	})

	it('returns null for unknown events', () => {
		expect(resolveReaction('not-a-real-event', NOVA_STATES)).toBeNull()
	})
})

describe('characterReactions', () => {
	beforeEach(() => {
		characterReactions.reset()
		novaReactions.reset()
		vi.clearAllMocks()
	})

	it('respects session cooldowns', () => {
		expect(characterReactions.react('streak-5', NOVA_STATES)).not.toBeNull()
		expect(characterReactions.react('streak-5', NOVA_STATES)).toBeNull()
	})

	it('respects pool cooldowns per poolId', () => {
		expect(characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'a' })).not.toBeNull()
		expect(characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'a' })).toBeNull()
		expect(characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'b' })).not.toBeNull()
	})

	it('reset clears cooldowns', () => {
		characterReactions.react('streak-5', NOVA_STATES)
		characterReactions.reset()
		expect(characterReactions.react('streak-5', NOVA_STATES)).not.toBeNull()
	})
})

describe('novaReactions wrapper', () => {
	beforeEach(() => {
		characterReactions.reset()
		novaReactions.reset()
		vi.clearAllMocks()
	})

	it('preserves the public answer-correct reaction shape', () => {
		expect(novaReactions.react('answer-correct')).toEqual({
			animation: 'talk',
			emotion: 'happy',
			sound: 'success',
			duration: 2000,
		})
		expect(novaAudio.play).toHaveBeenCalledWith('success')
	})

	it('preserves cooldown semantics', () => {
		expect(novaReactions.react('streak-5')).not.toBeNull()
		expect(novaReactions.react('streak-5')).toBeNull()
	})

	it('shares cooldown state with characterReactions', () => {
		expect(novaReactions.react('streak-5')).not.toBeNull()
		expect(characterReactions.react('streak-5', NOVA_STATES)).toBeNull()
	})

	it('canReact reflects cooldown state and reset restores it', () => {
		expect(novaReactions.canReact('streak-5')).toBe(true)
		novaReactions.react('streak-5')
		expect(novaReactions.canReact('streak-5')).toBe(false)
		novaReactions.reset()
		expect(novaReactions.canReact('streak-5')).toBe(true)
	})
})
