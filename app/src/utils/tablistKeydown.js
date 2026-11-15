/**
 * Arrow-key navigation inside a role="tablist" (WAI-ARIA tabs, manual activation):
 * ArrowRight/ArrowDown focus the next tab, ArrowLeft/ArrowUp the previous one, wrapping around.
 * Enter/Space activate the focused button natively. Bind it as @keydown on the tablist element.
 *
 * In a right-to-left layout (Arabic) the tabs run from right to left, so ArrowLeft is the next
 * tab and ArrowRight the previous one; Up/Down keep their meaning.
 *
 * Shared by App.vue, CourseDetail.vue, PoolList.vue, PoolGenerator.vue and ImportDialog.vue (#46)
 * and by the sub-tab bars of the course areas (CourseTabLernraum incl. its tool bar,
 * CourseTabTeilnehmer, CourseTabVerwaltung, CourseTabKommunikation, CourseTabWettbewerb; #64)
 * so every tab bar behaves the same.
 */
export function moveTablistFocus(event) {
	const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]')]
	const currentIndex = tabs.indexOf(document.activeElement)
	if (currentIndex === -1) {
		return
	}

	const rtl = getComputedStyle(event.currentTarget).direction === 'rtl'
	const nextKey = rtl ? 'ArrowLeft' : 'ArrowRight'
	const previousKey = rtl ? 'ArrowRight' : 'ArrowLeft'

	let nextIndex
	if (event.key === nextKey || event.key === 'ArrowDown') {
		nextIndex = (currentIndex + 1) % tabs.length
	} else if (event.key === previousKey || event.key === 'ArrowUp') {
		nextIndex = (currentIndex - 1 + tabs.length) % tabs.length
	} else {
		return
	}

	event.preventDefault()
	tabs[nextIndex].focus()
}
