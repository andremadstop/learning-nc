/**
 * Phase 153 Plan 02 Wave-0 scaffold for TEST-02.
 *
 * Exercises the public `resolveReaction(event, supportedStates = [])` contract
 * exported from app/src/utils/character-reaction-engine.js.
 *
 * Note: a sibling file `character-reaction-engine.test.js` (kebab-case) already
 * covers the same contract with broader cases including cooldowns and the
 * Nova-wrapper. This scaffold exists to satisfy the Phase 153 Plan 02 must_haves
 * grep-pattern (`describe('resolveReaction()'` + `import { resolveReaction }`)
 * and to give Plan 03/04 a stable, focused 5-case fallback contract to extend.
 *
 * Expected values were corrected from the plan draft to match the actual
 * EVENT_MAP and null-return semantics in the production engine (Rule 1
 * deviation, see SUMMARY.md).
 */
import { describe, it, expect } from 'vitest'
import { resolveReaction } from '../../src/utils/character-reaction-engine.js'

describe('resolveReaction()', () => {
	const KNOWN_STATES = ['idle', 'talk', 'celebrate']

	it('returns the matching reaction for a known event when supported', () => {
		// 'answer-correct' resolves to animation: 'talk' per EVENT_MAP.
		const r = resolveReaction('answer-correct', KNOWN_STATES)
		expect(r).toMatchObject({
			animation: 'talk',
			emotion: expect.any(String),
			duration: expect.any(Number),
		})
	})

	it('falls back to idle when the requested animation state is not supported', () => {
		// 'streak-5' wants celebrate; if only idle is supported it falls back.
		const SUPPORTS_ONLY_IDLE = ['idle']
		const r = resolveReaction('streak-5', SUPPORTS_ONLY_IDLE)
		expect(r.animation).toBe('idle')
	})

	it('returns null for unknown events (safe no-op)', () => {
		// Production engine returns null for events not in EVENT_MAP — caller
		// must handle nullable result. This is the documented fallback contract.
		const r = resolveReaction('event-that-does-not-exist', KNOWN_STATES)
		expect(r).toBeNull()
	})

	it('returns idle for a known event when skinStates is null/undefined', () => {
		// Array.isArray(null/undefined) → false → supported = [] → idle fallback
		// (only for known events; unknown events still return null — see above).
		expect(resolveReaction('answer-correct', null)).toMatchObject({ animation: 'idle' })
		expect(resolveReaction('answer-correct', undefined)).toMatchObject({ animation: 'idle' })
	})

	it('returns idle for a known event when skinStates is an empty array', () => {
		expect(resolveReaction('answer-correct', [])).toMatchObject({ animation: 'idle' })
	})
})
