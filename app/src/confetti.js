import confetti from 'canvas-confetti'

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
export const STREAK_MILESTONES = [7, 14, 30, 100]

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
