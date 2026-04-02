const FOCUSABLE_SELECTOR = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled]):not([type="hidden"])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
	'[contenteditable="true"]',
].join(', ')

function isVisible(element) {
	const style = typeof window !== 'undefined' && typeof window.getComputedStyle === 'function'
		? window.getComputedStyle(element)
		: null

	return Boolean(
		element
		&& !element.hasAttribute('hidden')
		&& element.getAttribute('aria-hidden') !== 'true'
		&& style?.display !== 'none'
		&& style?.visibility !== 'hidden',
	)
}

export function getFocusableElements(root) {
	if (!root || typeof root.querySelectorAll !== 'function') {
		return []
	}

	return Array.from(root.querySelectorAll(FOCUSABLE_SELECTOR))
		.filter(isVisible)
}

export function focusFirstElement(root, fallback = null) {
	const first = getFocusableElements(root)[0] || fallback
	if (first && typeof first.focus === 'function') {
		first.focus({ preventScroll: true })
	}
	return first || null
}

export function trapTabKey(event, root, fallback = null) {
	if (!event || event.key !== 'Tab') {
		return false
	}

	const focusable = getFocusableElements(root)
	if (focusable.length === 0) {
		event.preventDefault()
		fallback?.focus?.({ preventScroll: true })
		return true
	}

	const first = focusable[0]
	const last = focusable[focusable.length - 1]
	const active = document.activeElement
	const activeInsideRoot = Boolean(root && active && root.contains(active))

	if (event.shiftKey) {
		if (!activeInsideRoot || active === first) {
			event.preventDefault()
			last.focus({ preventScroll: true })
			return true
		}
		return false
	}

	if (!activeInsideRoot || active === last) {
		event.preventDefault()
		first.focus({ preventScroll: true })
		return true
	}

	return false
}
