/**
 * NOVA Audio Manager — Web Audio API sound synthesis for bot interactions.
 * Source: CHARACTER_BIBLE.md Section 11, VIRTPROF_SOUND_CONCEPT.md
 *
 * All sounds are synthesized in-browser via OscillatorNode. No external files.
 * Default: disabled. User enables via PersonalSettings toggle.
 */

let _audioCtx = null
let _masterGain = null
let _enabled = false
let _volume = 0.2

function ensureContext() {
	if (_audioCtx) {
		if (_audioCtx.state === 'suspended') {
			_audioCtx.resume().catch(() => {})
		}
		return true
	}
	try {
		_audioCtx = new (window.AudioContext || window.webkitAudioContext)()
		_masterGain = _audioCtx.createGain()
		_masterGain.gain.value = _volume
		_masterGain.connect(_audioCtx.destination)
		return true
	} catch {
		return false
	}
}

function playTone(freq, duration, type, opts = {}) {
	if (!ensureContext()) return
	try {
		const osc = _audioCtx.createOscillator()
		const gain = _audioCtx.createGain()
		osc.type = type || 'sine'
		osc.frequency.value = freq
		if (opts.detune) osc.detune.value = opts.detune
		gain.gain.value = 0
		osc.connect(gain)
		gain.connect(_masterGain)

		const now = _audioCtx.currentTime
		const attack = opts.attack || 0.01
		const release = opts.release || 0.01
		gain.gain.linearRampToValueAtTime(_volume, now + attack)
		gain.gain.setValueAtTime(_volume, now + duration - release)
		gain.gain.linearRampToValueAtTime(0, now + duration)

		osc.start(now + (opts.delay || 0))
		osc.stop(now + duration + (opts.delay || 0))
	} catch {
		// Never crash for audio errors
	}
}

const CUE_PLAYERS = {
	success() {
		// Ascending 3-tone arpeggio C5-E5-G5
		playTone(523, 0.08, 'sine')
		playTone(659, 0.08, 'sine', { delay: 0.08 })
		playTone(784, 0.08, 'sine', { delay: 0.16 })
	},
	error() {
		// Descending 2-tone A3-E3 with glitch detune
		playTone(220, 0.12, 'triangle', { detune: 5 })
		playTone(165, 0.12, 'triangle', { delay: 0.12, detune: 5 })
	},
	talk() {
		// Short pling
		playTone(1200, 0.04, 'sine')
	},
	thinking() {
		// Low pulse — returns stop function
		if (!ensureContext()) return () => {}
		try {
			const osc = _audioCtx.createOscillator()
			const gain = _audioCtx.createGain()
			const lfo = _audioCtx.createOscillator()
			const lfoGain = _audioCtx.createGain()

			osc.type = 'sine'
			osc.frequency.value = 200
			lfo.type = 'sine'
			lfo.frequency.value = 0.67
			lfoGain.gain.value = _volume * 0.25

			lfo.connect(lfoGain)
			lfoGain.connect(gain.gain)
			gain.gain.value = 0.05
			osc.connect(gain)
			gain.connect(_masterGain)

			osc.start()
			lfo.start()

			return () => {
				try {
					const now = _audioCtx.currentTime
					gain.gain.linearRampToValueAtTime(0, now + 0.3)
					setTimeout(() => { osc.stop(); lfo.stop() }, 350)
				} catch {
					// ignore
				}
			}
		} catch {
			return () => {}
		}
	},
	alarm() {
		// Double ping high-low
		playTone(880, 0.1, 'square')
		playTone(440, 0.1, 'square', { delay: 0.1 })
	},
	joke() {
		// Boing — pitch bend 300→600Hz
		if (!ensureContext()) return
		try {
			const osc = _audioCtx.createOscillator()
			const gain = _audioCtx.createGain()
			osc.type = 'sine'
			osc.frequency.value = 300
			gain.gain.value = _volume
			osc.connect(gain)
			gain.connect(_masterGain)

			const now = _audioCtx.currentTime
			osc.frequency.linearRampToValueAtTime(600, now + 0.15)
			gain.gain.linearRampToValueAtTime(0, now + 0.15)
			osc.start(now)
			osc.stop(now + 0.15)
		} catch {
			// ignore
		}
	},
}

export const novaAudio = {
	play(cueType) {
		if (!_enabled) return
		const player = CUE_PLAYERS[cueType]
		if (player) return player()
	},
	setEnabled(val) {
		_enabled = !!val
	},
	isEnabled() {
		return _enabled
	},
	setVolume(val) {
		_volume = Math.max(0, Math.min(1, val))
		if (_masterGain) {
			_masterGain.gain.value = _volume
		}
	},
	fadeOut() {
		if (!_masterGain || !_audioCtx) return
		try {
			_masterGain.gain.linearRampToValueAtTime(0, _audioCtx.currentTime + 0.3)
			setTimeout(() => {
				if (_masterGain) _masterGain.gain.value = _volume
			}, 350)
		} catch {
			// ignore
		}
	},
}
