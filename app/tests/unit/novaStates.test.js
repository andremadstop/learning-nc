import { describe, it, expect } from 'vitest'
import { STATES, EMOTIONS, mapLegacyAnimation } from '../../src/components/nova/nova-states.js'

// ---------------------------------------------------------------------------
// STATES
// ---------------------------------------------------------------------------

describe('STATES', () => {
	it('hat genau 6 Einträge', () => {
		expect(Object.keys(STATES)).toHaveLength(6)
	})

	it('enthält alle erwarteten State-Keys', () => {
		const keys = ['idle', 'thinking', 'talk', 'celebrate', 'glitch', 'sleep']
		for (const key of keys) {
			expect(STATES[key]).toBeDefined()
		}
	})

	it('jeder State hat cssClass und defaultEmotion', () => {
		for (const [key, state] of Object.entries(STATES)) {
			expect(typeof state.cssClass).toBe('string', `${key}.cssClass fehlt`)
			expect(state.cssClass.length).toBeGreaterThan(0)
			expect(typeof state.defaultEmotion).toBe('string', `${key}.defaultEmotion fehlt`)
		}
	})

	it('cssClass folgt dem nova-- Präfix-Muster', () => {
		for (const state of Object.values(STATES)) {
			expect(state.cssClass).toMatch(/^nova--/)
		}
	})
})

// ---------------------------------------------------------------------------
// EMOTIONS
// ---------------------------------------------------------------------------

describe('EMOTIONS', () => {
	it('hat genau 6 Einträge', () => {
		expect(Object.keys(EMOTIONS)).toHaveLength(6)
	})

	it('enthält alle erwarteten Emotion-Keys', () => {
		const keys = ['neutral', 'happy', 'sad', 'surprised', 'sleep', 'celebrate']
		for (const key of keys) {
			expect(EMOTIONS[key]).toBeDefined()
		}
	})

	it('jede Emotion hat eyeSymbol', () => {
		for (const [key, emotion] of Object.entries(EMOTIONS)) {
			expect(typeof emotion.eyeSymbol).toBe('string', `${key}.eyeSymbol fehlt`)
			expect(emotion.eyeSymbol.length).toBeGreaterThan(0)
		}
	})

	it('jede Emotion hat eine color-Eigenschaft (kann null sein)', () => {
		for (const key of Object.keys(EMOTIONS)) {
			expect('color' in EMOTIONS[key]).toBe(true)
		}
	})
})

// ---------------------------------------------------------------------------
// mapLegacyAnimation
// ---------------------------------------------------------------------------

describe('mapLegacyAnimation', () => {
	it('mappt idle → idle', () => {
		expect(mapLegacyAnimation('idle')).toBe('idle')
	})

	it('mappt talk → talk', () => {
		expect(mapLegacyAnimation('talk')).toBe('talk')
	})

	it('mappt wave → idle (wave existiert nicht in NOVA, fällt auf idle zurück)', () => {
		expect(mapLegacyAnimation('wave')).toBe('idle')
	})

	it('mappt celebrate → celebrate', () => {
		expect(mapLegacyAnimation('celebrate')).toBe('celebrate')
	})

	it('mappt unbekannte Werte auf idle', () => {
		expect(mapLegacyAnimation('unknown_animation')).toBe('idle')
		expect(mapLegacyAnimation('')).toBe('idle')
		expect(mapLegacyAnimation(undefined)).toBe('idle')
	})
})
