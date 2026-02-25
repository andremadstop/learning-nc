/**
 * Animate a number counting up from 0 to target.
 * @param {HTMLElement} el — element whose textContent will be updated
 * @param {number} target — final number to display
 * @param {number} duration — animation duration in ms
 * @param {string} suffix — optional suffix (e.g. '%')
 */
export function countUp(el, target, duration = 1000, suffix = '') {
	if (!el || target <= 0) {
		if (el) el.textContent = target + suffix
		return
	}
	const start = performance.now()
	function frame(now) {
		const elapsed = now - start
		const progress = Math.min(elapsed / duration, 1)
		// ease-out quad
		const eased = 1 - (1 - progress) * (1 - progress)
		const current = Math.round(eased * target)
		el.textContent = current + suffix
		if (progress < 1) {
			requestAnimationFrame(frame)
		}
	}
	requestAnimationFrame(frame)
}
