/**
 * GitHub #66: a short tone right after answering — "Pling" (two bright notes going up) for a
 * right answer, "Zonk" (a low buzz going down) for a wrong one. Synthesised with the Web Audio
 * API: no sound files, nothing to license, a few bytes.
 *
 * Browsers (Safari above all) let an AudioContext start only from a user gesture, and the result
 * of an answer arrives after a request. unlockAnswerSounds() is therefore called in the click or
 * key press that submits the answer; playAnswerSound() later uses the context it started.
 * Everything fails silently: no Web Audio, a refused resume — then there is just no tone.
 */

let context = null

function audioContextClass() {
	if (typeof window === 'undefined') {
		return null
	}
	return window.AudioContext || window.webkitAudioContext || null
}

/** Create or resume the shared context; call it inside a user gesture. Returns it or null. */
export function unlockAnswerSounds() {
	const Ctor = audioContextClass()
	if (!Ctor) {
		return null
	}
	try {
		if (!context || context.state === 'closed') {
			context = new Ctor()
		}
		if (context.state === 'suspended' && typeof context.resume === 'function') {
			const pending = context.resume()
			pending?.catch?.(() => {})
		}
		return context
	} catch (e) {
		context = null
		return null
	}
}

/** How long a tone lasts (ms): a spoken explanation starts after it, not over it. */
export const ANSWER_SOUND_MS = 450

/** The notes of a tone: [{type, from, to, start, length, gain}] (seconds, Hz). */
export function answerSoundNotes(correct) {
	if (correct) {
		return [
			{ type: 'sine', from: 1046.5, to: 1046.5, start: 0, length: 0.16, gain: 0.18 },
			{ type: 'sine', from: 1568, to: 1568, start: 0.09, length: 0.32, gain: 0.16 },
		]
	}
	return [
		{ type: 'square', from: 196, to: 98, start: 0, length: 0.42, gain: 0.07 },
		{ type: 'sawtooth', from: 147, to: 73.5, start: 0.02, length: 0.4, gain: 0.05 },
	]
}

/**
 * Play the tone for a right (true) or wrong (false) answer. Uses the context a gesture started
 * (unlockAnswerSounds), else tries to start one. Returns true when the notes were scheduled.
 */
export function playAnswerSound(correct) {
	const ctx = context || unlockAnswerSounds()
	if (!ctx) {
		return false
	}
	try {
		if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
			// opened outside a gesture: try once more (allowed once the page had one)
			ctx.resume()?.catch?.(() => {})
		}
		const now = ctx.currentTime || 0
		for (const note of answerSoundNotes(!!correct)) {
			const osc = ctx.createOscillator()
			const gain = ctx.createGain()
			osc.type = note.type
			osc.frequency.setValueAtTime(note.from, now + note.start)
			if (note.to !== note.from) {
				osc.frequency.exponentialRampToValueAtTime(note.to, now + note.start + note.length)
			}
			// A short attack and an exponential release: no click at either end.
			gain.gain.setValueAtTime(0.0001, now + note.start)
			gain.gain.exponentialRampToValueAtTime(note.gain, now + note.start + 0.012)
			gain.gain.exponentialRampToValueAtTime(0.0001, now + note.start + note.length)
			osc.connect(gain)
			gain.connect(ctx.destination)
			osc.start(now + note.start)
			osc.stop(now + note.start + note.length + 0.02)
		}
		return true
	} catch (e) {
		return false
	}
}

/** Tests only: forget the shared context. */
export function resetAnswerSoundsForTests() {
	context = null
}
