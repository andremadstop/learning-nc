/**
 * Arrow-key navigation inside a role="tablist" (WAI-ARIA tabs, manual activation):
 * ArrowRight/ArrowDown focus the next tab, ArrowLeft/ArrowUp the previous one, wrapping around.
 * Enter/Space activate the focused button natively. Bind it as @keydown on the tablist element.
 *
 * Shared by App.vue and CourseDetail.vue (#46) so every tab bar behaves the same.
 */
export function moveTablistFocus(event) {
	const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]')]
	const currentIndex = tabs.indexOf(document.activeElement)
	if (currentIndex === -1) {
		return
	}

	let nextIndex
	if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
		nextIndex = (currentIndex + 1) % tabs.length
	} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
		nextIndex = (currentIndex - 1 + tabs.length) % tabs.length
	} else {
		return
	}

	event.preventDefault()
	tabs[nextIndex].focus()
}
