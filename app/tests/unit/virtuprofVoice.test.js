import { describe, it, expect, afterEach, vi } from 'vitest'
import { VOICE_LANGUAGE_OPTIONS, getBrowserVoiceLanguage } from '../../src/utils/virtuprof-voice.js'

// Charakterisierungs-Tests: fixieren das Verhalten aus VirtuProf.vue. Zero-Behavior-Change.

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('VOICE_LANGUAGE_OPTIONS', () => {
	it('enthält 15 Sprachen, jede mit value + label', () => {
		expect(VOICE_LANGUAGE_OPTIONS).toHaveLength(15)
		expect(VOICE_LANGUAGE_OPTIONS[0]).toEqual({ value: 'de-DE', label: 'Deutsch' })
		expect(VOICE_LANGUAGE_OPTIONS.every(o => typeof o.value === 'string' && typeof o.label === 'string')).toBe(true)
	})
})

describe('getBrowserVoiceLanguage', () => {
	it('exakter Sprach-Match wird übernommen', () => {
		vi.stubGlobal('navigator', { language: 'en-US' })
		expect(getBrowserVoiceLanguage()).toBe('en-US')
	})

	it('nur Basissprache bekannt → erste passende Region', () => {
		vi.stubGlobal('navigator', { language: 'en-GB' })
		expect(getBrowserVoiceLanguage()).toBe('en-US')
	})

	it('unbekannte Sprache fällt auf de-DE zurück', () => {
		vi.stubGlobal('navigator', { language: 'xx-YY' })
		expect(getBrowserVoiceLanguage()).toBe('de-DE')
	})

	it('ohne navigator → de-DE', () => {
		vi.stubGlobal('navigator', undefined)
		expect(getBrowserVoiceLanguage()).toBe('de-DE')
	})
})
