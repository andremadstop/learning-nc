/**
 * Character animation WAAPI helpers.
 * Every helper is gated by both OS-level reduced-motion and a manual
 * animations-enabled getter that Wave 3 wires to the a11y store.
 *
 * Keyframes respect ART_STYLE_GUIDE.md Section 4: transform + opacity only.
 */

let animationsEnabledGetter = () => true

export function setAnimationsEnabledGetter(getter) {
	if (typeof getter === 'function') {
		animationsEnabledGetter = getter
	}
}

function prefersReducedMotion() {
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
		return false
	}

	return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function isAnimationGated() {
	return prefersReducedMotion() || animationsEnabledGetter() === false
}

async function playGated(element, keyframes, options) {
	if (!element || typeof element.animate !== 'function') {
		return null
	}

	if (isAnimationGated()) {
		return null
	}

	const animation = element.animate(keyframes, options)
	if (animation?.finished) {
		await animation.finished.catch(() => {})
	}

	return animation
}

export function playWave(element) {
	return playGated(
		element,
		[
			{ transform: 'rotate(0deg)' },
			{ transform: 'rotate(15deg)' },
			{ transform: 'rotate(-5deg)' },
			{ transform: 'rotate(0deg)' },
		],
		{ duration: 600, easing: 'ease-out', fill: 'none' },
	)
}

export function playCelebrate(element) {
	return playGated(
		element,
		[
			{ transform: 'scale(1)', opacity: 1 },
			{ transform: 'scale(1.1)', opacity: 1 },
			{ transform: 'scale(0.98)', opacity: 1 },
			{ transform: 'scale(1)', opacity: 1 },
		],
		{ duration: 1200, easing: 'ease-out', fill: 'none' },
	)
}

export function playShrug(element) {
	return playGated(
		element,
		[
			{ transform: 'translateY(0) rotate(0deg)' },
			{ transform: 'translateY(-3px) rotate(-2deg)' },
			{ transform: 'translateY(-3px) rotate(2deg)' },
			{ transform: 'translateY(0) rotate(0deg)' },
		],
		{ duration: 800, easing: 'ease-in-out', fill: 'none' },
	)
}
