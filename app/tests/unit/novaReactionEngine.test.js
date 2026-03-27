import { describe, it, expect, beforeEach } from 'vitest'
import { novaReactions } from '../../src/utils/nova-reaction-engine.js'

beforeEach(() => {
	novaReactions.reset()
})

// ---------------------------------------------------------------------------
// react() — answer-correct
// ---------------------------------------------------------------------------

describe('novaReactions.react() — answer-correct', () => {
	it('gibt animation:talk zurück', () => {
		const result = novaReactions.react('answer-correct')
		expect(result.animation).toBe('talk')
	})

	it('gibt emotion:happy zurück', () => {
		const result = novaReactions.react('answer-correct')
		expect(result.emotion).toBe('happy')
	})

	it('gibt sound:success zurück', () => {
		const result = novaReactions.react('answer-correct')
		expect(result.sound).toBe('success')
	})

	it('gibt duration:2000 zurück', () => {
		const result = novaReactions.react('answer-correct')
		expect(result.duration).toBe(2000)
	})

	it('kann mehrfach hintereinander feuern (kein Cooldown)', () => {
		const first = novaReactions.react('answer-correct')
		const second = novaReactions.react('answer-correct')
		expect(first).not.toBeNull()
		expect(second).not.toBeNull()
	})
})

// ---------------------------------------------------------------------------
// react() — answer-wrong
// ---------------------------------------------------------------------------

describe('novaReactions.react() — answer-wrong', () => {
	it('gibt animation:talk zurück', () => {
		const result = novaReactions.react('answer-wrong')
		expect(result.animation).toBe('talk')
	})

	it('gibt emotion:sad zurück', () => {
		const result = novaReactions.react('answer-wrong')
		expect(result.emotion).toBe('sad')
	})

	it('gibt sound:error zurück', () => {
		const result = novaReactions.react('answer-wrong')
		expect(result.sound).toBe('error')
	})

	it('gibt duration:2000 zurück', () => {
		const result = novaReactions.react('answer-wrong')
		expect(result.duration).toBe(2000)
	})
})

// ---------------------------------------------------------------------------
// react() — unbekannter Event
// ---------------------------------------------------------------------------

describe('novaReactions.react() — unbekannter Event', () => {
	it('gibt null zurück für unbekannte Event-Types', () => {
		expect(novaReactions.react('does-not-exist')).toBeNull()
	})
})

// ---------------------------------------------------------------------------
// Cooldown — streak-5 (session-Cooldown)
// ---------------------------------------------------------------------------

describe('Cooldown — streak-5 feuert nur einmal pro Session', () => {
	it('erster Aufruf liefert eine Reaktion', () => {
		const result = novaReactions.react('streak-5')
		expect(result).not.toBeNull()
		expect(result.animation).toBe('celebrate')
	})

	it('zweiter Aufruf innerhalb derselben Session gibt null zurück', () => {
		novaReactions.react('streak-5')
		const second = novaReactions.react('streak-5')
		expect(second).toBeNull()
	})

	it('canReact gibt false zurück nach dem ersten Feuern', () => {
		novaReactions.react('streak-5')
		expect(novaReactions.canReact('streak-5')).toBe(false)
	})

	it('canReact gibt true zurück vor dem ersten Feuern', () => {
		expect(novaReactions.canReact('streak-5')).toBe(true)
	})
})

// ---------------------------------------------------------------------------
// reset()
// ---------------------------------------------------------------------------

describe('novaReactions.reset()', () => {
	it('löscht Cooldowns, sodass streak-5 wieder feuern kann', () => {
		novaReactions.react('streak-5')
		expect(novaReactions.canReact('streak-5')).toBe(false)

		novaReactions.reset()

		expect(novaReactions.canReact('streak-5')).toBe(true)
		const result = novaReactions.react('streak-5')
		expect(result).not.toBeNull()
	})

	it('löscht auch pool-basierte Cooldowns (error-repeated)', () => {
		// canReact() prüft mit leerem Context — Key ist 'error-repeated:default'
		// Wir feuern ohne poolId, damit der Key übereinstimmt
		novaReactions.react('error-repeated', {})
		// Nach dem Feuern: canReact mit leerem Context sollte false sein
		const afterFire = novaReactions.react('error-repeated', {})
		expect(afterFire).toBeNull()

		novaReactions.reset()

		// Nach reset() kann error-repeated wieder feuern
		const afterReset = novaReactions.react('error-repeated', {})
		expect(afterReset).not.toBeNull()
	})
})
