import confettiLib from 'canvas-confetti'

// canvas-confetti's default instance draws in a Web Worker created from a blob: URL. Nextcloud's
// Content-Security-Policy forbids such workers, so every celebration logged a CSP violation in
// the console (found by the persona sweep, GitHub #36); the library then fell back to the main
// thread anyway. One instance of our own, without the worker, created on first use.
let instance = null
// Fresh-eyes review (GitHub #36): confetti covered the result of a mandatory exam although the
// admin had switched gamification off, and it ignored "Ruhige Darstellung" as well. App.vue sets
// the gate from both switches; until it does, celebrations are allowed as before.
let celebrationsAllowed = () => true

/**
 * @param {() => boolean} getter - true when celebrations may play (gamification on, animations on)
 */
export function setCelebrationGate(getter) {
	celebrationsAllowed = typeof getter === 'function' ? getter : () => true
}

function confetti(options) {
	if (!celebrationsAllowed()) return undefined
	if (!instance) instance = confettiLib.create(null, { resize: true, useWorker: false })
	return instance(options)
}

const prefersReducedMotion = () =>
	typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * Gold confetti for mastering a card (reaching Box 5).
 */
export function celebrateMastery() {
	if (prefersReducedMotion()) return
	confetti({
		particleCount: 80,
		spread: 70,
		origin: { y: 0.6 },
		colors: ['#FFD700', '#FFA500', '#FF8C00', '#DAA520'],
	})
}

/**
 * Big firework for a perfect training session (100%).
 */
export function celebratePerfectSession() {
	if (prefersReducedMotion()) return
	const duration = 1500
	const end = Date.now() + duration

	function frame() {
		confetti({
			particleCount: 5,
			angle: 60,
			spread: 55,
			origin: { x: 0 },
		})
		confetti({
			particleCount: 5,
			angle: 120,
			spread: 55,
			origin: { x: 1 },
		})
		if (Date.now() < end) {
			requestAnimationFrame(frame)
		}
	}
	frame()
}

/**
 * Fire-themed confetti for streak milestones (7, 14, 30, 100 days).
 */
export function celebrateStreak(days) {
	if (prefersReducedMotion()) return
	const count = days >= 100 ? 150 : days >= 30 ? 100 : 60
	confetti({
		particleCount: count,
		spread: 100,
		origin: { y: 0.5 },
		colors: ['#FF4500', '#FF6347', '#FF8C00', '#FFD700', '#FFA500'],
	})
}

/** Streak milestone thresholds */
const STREAK_MILESTONES = [7, 14, 30, 100]

/**
 * Check if a streak day count hits a milestone.
 */
export function isStreakMilestone(days) {
	return STREAK_MILESTONES.includes(days)
}

/**
 * Badge unlock celebration — sparkle + small burst.
 */
export function celebrateBadge() {
	if (prefersReducedMotion()) return
	confetti({
		particleCount: 50,
		spread: 60,
		origin: { y: 0.4 },
		colors: ['#FFD700', '#4FC3F7', '#AB47BC', '#66BB6A', '#FF7043'],
		shapes: ['star', 'circle'],
	})
}

/**
 * Two bursts when a gameshow is won. GameshowMode used require('canvas-confetti'), which
 * does not exist in the Vite/ESM bundle, so its confetti never fired (audit #99).
 */
export function celebrateGameshowWin() {
	if (prefersReducedMotion()) return
	confetti({ particleCount: 120, spread: 80, origin: { y: 0.6 } })
	setTimeout(() => {
		confetti({ particleCount: 60, spread: 100, origin: { y: 0.5 }, startVelocity: 25 })
	}, 300)
}
