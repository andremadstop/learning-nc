/**
 * Character animation WAAPI helpers.
 * Every helper is gated by BOTH:
 *   1. window.matchMedia('(prefers-reduced-motion: reduce)').matches
 *   2. animationsEnabledGetter()
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

function animationsAllowed() {
	try {
		return animationsEnabledGetter() !== false
	} catch (error) {
		return true
	}
}

function animateElement(element, keyframes, options) {
	if (!element || typeof element.animate !== 'function') {
		return Promise.resolve(null)
	}

	if (prefersReducedMotion() || !animationsAllowed()) {
		return Promise.resolve(null)
	}

	const animation = element.animate(keyframes, options)
	return animation?.finished || Promise.resolve(animation)
}

export function playWave(element) {
	return animateElement(
		element,
		[
			{ transform: 'rotate(0deg)', opacity: 1 },
			{ transform: 'rotate(15deg)', opacity: 1 },
			{ transform: 'rotate(-5deg)', opacity: 1 },
			{ transform: 'rotate(0deg)', opacity: 1 },
		],
		{ duration: 600, easing: 'ease-out', fill: 'both' },
	)
}

export function playCelebrate(element) {
	return animateElement(
		element,
		[
			{ transform: 'scale(1)', opacity: 1 },
			{ transform: 'scale(1.1)', opacity: 1 },
			{ transform: 'scale(0.98)', opacity: 0.96 },
			{ transform: 'scale(1)', opacity: 1 },
		],
		{ duration: 1200, easing: 'ease-out', fill: 'both' },
	)
}

export function playShrug(element) {
	return animateElement(
		element,
		[
			{ transform: 'translateY(0) rotate(0deg)', opacity: 1 },
			{ transform: 'translateY(-2px) rotate(2deg)', opacity: 1 },
			{ transform: 'translateY(0) rotate(-2deg)', opacity: 1 },
			{ transform: 'translateY(0) rotate(0deg)', opacity: 1 },
		],
		{ duration: 800, easing: 'ease-in-out', fill: 'both' },
	)
}
