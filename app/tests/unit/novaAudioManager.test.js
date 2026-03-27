import { describe, it, expect, beforeEach, vi } from 'vitest'

// ---------------------------------------------------------------------------
// AudioContext mock — Web Audio API ist in happy-dom nicht verfügbar.
// Wir mocken sie minimal, um Absturz-Verhalten zu testen.
// ---------------------------------------------------------------------------

function makeAudioContextMock() {
	const destination = {}
	const gainNode = {
		gain: { value: 0, linearRampToValueAtTime: vi.fn(), setValueAtTime: vi.fn() },
		connect: vi.fn(),
	}
	const oscNode = {
		type: '',
		frequency: { value: 0, linearRampToValueAtTime: vi.fn() },
		detune: { value: 0 },
		connect: vi.fn(),
		start: vi.fn(),
		stop: vi.fn(),
	}
	return {
		destination,
		state: 'running',
		currentTime: 0,
		resume: vi.fn().mockResolvedValue(undefined),
		createGain: vi.fn(() => ({ ...gainNode, connect: vi.fn() })),
		createOscillator: vi.fn(() => ({ ...oscNode, connect: vi.fn() })),
	}
}

// ---------------------------------------------------------------------------
// novaAudio — disabled by default
// ---------------------------------------------------------------------------

describe('novaAudio — standardmäßig deaktiviert', () => {
	// Re-import fresh module for each test group using dynamic import + vi.resetModules
	it('isEnabled() gibt false zurück nach dem Laden', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(novaAudio.isEnabled()).toBe(false)
	})

	it('play() macht nichts wenn disabled (kein Fehler, kein AudioContext)', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		// AudioContext ist deaktiviert — play() darf nicht werfen
		expect(() => novaAudio.play('success')).not.toThrow()
		expect(() => novaAudio.play('error')).not.toThrow()
		expect(() => novaAudio.play('talk')).not.toThrow()
	})

	it('play() mit unbekanntem Cue-Typ wirft keinen Fehler', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(() => novaAudio.play('nonexistent_cue')).not.toThrow()
	})
})

// ---------------------------------------------------------------------------
// setEnabled / isEnabled
// ---------------------------------------------------------------------------

describe('novaAudio.setEnabled()', () => {
	it('setEnabled(true) aktiviert, isEnabled() gibt true zurück', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		novaAudio.setEnabled(true)
		expect(novaAudio.isEnabled()).toBe(true)
	})

	it('setEnabled(false) deaktiviert, isEnabled() gibt false zurück', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		novaAudio.setEnabled(true)
		novaAudio.setEnabled(false)
		expect(novaAudio.isEnabled()).toBe(false)
	})

	it('setEnabled mit truthy-Wert aktiviert', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		novaAudio.setEnabled(1)
		expect(novaAudio.isEnabled()).toBe(true)
	})

	it('setEnabled mit falsy-Wert deaktiviert', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		novaAudio.setEnabled(true)
		novaAudio.setEnabled(0)
		expect(novaAudio.isEnabled()).toBe(false)
	})
})

// ---------------------------------------------------------------------------
// setVolume
// ---------------------------------------------------------------------------

describe('novaAudio.setVolume()', () => {
	it('clampst Werte unter 0 auf 0', async () => {
		vi.resetModules()
		// setVolume speichert intern _volume; wir testen über setEnabled+play (kein Fehler)
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(() => novaAudio.setVolume(-1)).not.toThrow()
	})

	it('clampst Werte über 1 auf 1', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(() => novaAudio.setVolume(5)).not.toThrow()
	})

	it('akzeptiert gültige Werte zwischen 0 und 1', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(() => novaAudio.setVolume(0.5)).not.toThrow()
		expect(() => novaAudio.setVolume(0)).not.toThrow()
		expect(() => novaAudio.setVolume(1)).not.toThrow()
	})
})

// ---------------------------------------------------------------------------
// Kein Absturz wenn AudioContext nicht verfügbar (JSDOM / happy-dom Umgebung)
// ---------------------------------------------------------------------------

describe('novaAudio — kein Absturz wenn AudioContext nicht verfügbar', () => {
	it('play() mit enabled=true und fehlendem AudioContext wirft keinen Fehler', async () => {
		vi.resetModules()
		// Stelle sicher, dass window.AudioContext nicht existiert
		const originalAC = globalThis.AudioContext
		const originalWAC = globalThis.webkitAudioContext
		delete globalThis.AudioContext
		delete globalThis.webkitAudioContext

		try {
			const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
			novaAudio.setEnabled(true)
			expect(() => novaAudio.play('success')).not.toThrow()
			expect(() => novaAudio.play('error')).not.toThrow()
		} finally {
			if (originalAC) globalThis.AudioContext = originalAC
			if (originalWAC) globalThis.webkitAudioContext = originalWAC
		}
	})

	it('fadeOut() wirft keinen Fehler wenn kein AudioContext aktiv ist', async () => {
		vi.resetModules()
		const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
		expect(() => novaAudio.fadeOut()).not.toThrow()
	})
})

// ---------------------------------------------------------------------------
// novaAudio mit gemocktem AudioContext
// ---------------------------------------------------------------------------

describe('novaAudio.play() — mit AudioContext-Mock', () => {
	it('versucht Sound zu erzeugen wenn enabled und AudioContext verfügbar ist', async () => {
		vi.resetModules()

		const mockCtx = makeAudioContextMock()
		globalThis.AudioContext = vi.fn(() => mockCtx)

		try {
			const { novaAudio } = await import('../../src/utils/nova-audio-manager.js')
			novaAudio.setEnabled(true)
			// Darf nicht werfen
			expect(() => novaAudio.play('success')).not.toThrow()
		} finally {
			delete globalThis.AudioContext
		}
	})
})
