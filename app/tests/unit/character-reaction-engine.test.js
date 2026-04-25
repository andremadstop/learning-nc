import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	EVENT_MAP,
	characterReactions,
	resolveReaction,
} from '../../src/utils/character-reaction-engine.js'
import { novaAudio } from '../../src/utils/nova-audio-manager.js'
import { novaReactions } from '../../src/utils/nova-reaction-engine.js'

vi.mock('../../src/utils/nova-audio-manager.js', () => ({
	novaAudio: { play: vi.fn() },
}))

const NOVA_STATES = ['idle', 'talk', 'celebrate']

describe('resolveReaction (pure, no side effects)', () => {
	it('returns a reaction object for every known EVENT_MAP key', () => {
		for (const event of Object.keys(EVENT_MAP)) {
			const reaction = resolveReaction(event, NOVA_STATES)

			expect(reaction).not.toBeNull()
			expect(reaction).toHaveProperty('animation')
			expect(reaction).toHaveProperty('emotion')
			expect(reaction).toHaveProperty('sound')
			expect(reaction).toHaveProperty('duration')
		}
	})

	it('passes the animation through when supported', () => {
		const reaction = resolveReaction('answer-correct', NOVA_STATES)

		expect(reaction.animation).toBe('talk')
	})

	it('falls back to idle when requested animation is not supported', () => {
		const reaction = resolveReaction('streak-5', ['idle'])

		expect(reaction.animation).toBe('idle')
		expect(reaction.emotion).toBe('celebrate')
		expect(reaction.sound).toBe('success')
		expect(reaction.duration).toBe(3000)
	})

	it('falls back to idle when supportedStates is empty', () => {
		const reaction = resolveReaction('streak-5', [])

		expect(reaction.animation).toBe('idle')
	})

	it('returns null for unknown events', () => {
		expect(resolveReaction('not-a-real-event', NOVA_STATES)).toBeNull()
	})

	it('does not call the Nova audio manager', () => {
		resolveReaction('answer-correct', NOVA_STATES)

		expect(novaAudio.play).not.toHaveBeenCalled()
	})
})

describe('characterReactions.react (with cooldowns)', () => {
	beforeEach(() => {
		characterReactions.reset()
		vi.clearAllMocks()
	})

	it('respects session cooldown', () => {
		const first = characterReactions.react('streak-5', NOVA_STATES)
		const second = characterReactions.react('streak-5', NOVA_STATES)

		expect(first).not.toBeNull()
		expect(second).toBeNull()
	})

	it('reset clears cooldowns', () => {
		characterReactions.react('streak-5', NOVA_STATES)
		characterReactions.reset()

		expect(characterReactions.react('streak-5', NOVA_STATES)).not.toBeNull()
	})

	it('respects pool cooldown per poolId', () => {
		const a1 = characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'pool-a' })
		const a2 = characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'pool-a' })
		const b1 = characterReactions.react('error-repeated', NOVA_STATES, { poolId: 'pool-b' })

		expect(a1).not.toBeNull()
		expect(a2).toBeNull()
		expect(b1).not.toBeNull()
	})

	it('returns null for unknown events', () => {
		expect(characterReactions.react('not-a-real-event', NOVA_STATES)).toBeNull()
	})
})

describe('novaReactions wrapper (non-breaking)', () => {
	beforeEach(() => {
		novaReactions.reset()
		vi.clearAllMocks()
	})

	it('returns the expected reaction for answer-correct', () => {
		const reaction = novaReactions.react('answer-correct')

		expect(reaction).toEqual({
			animation: 'talk',
			emotion: 'happy',
			sound: 'success',
			duration: 2000,
		})
		expect(novaAudio.play).toHaveBeenCalledWith('success')
	})

	it('preserves cooldown semantics for streak-5 session events', () => {
		expect(novaReactions.react('streak-5')).not.toBeNull()
		expect(novaReactions.react('streak-5')).toBeNull()
	})

	it('canReact reflects cooldown state', () => {
		expect(novaReactions.canReact('streak-5')).toBe(true)
		novaReactions.react('streak-5')
		expect(novaReactions.canReact('streak-5')).toBe(false)
		novaReactions.reset()
		expect(novaReactions.canReact('streak-5')).toBe(true)
	})

	it('returns false for unknown events in canReact', () => {
		expect(novaReactions.canReact('not-a-real-event')).toBe(false)
	})

	it('shares cooldown state with characterReactions', () => {
		expect(novaReactions.react('streak-5')).not.toBeNull()
		expect(characterReactions.react('streak-5', NOVA_STATES)).toBeNull()
	})
})
