/**
 * GitHub #110: whether a click on a material link is a plain left click, which opens Learning's
 * Markdown reader. Middle click, Ctrl/Cmd/Shift/Alt-click and "open in new tab" keep the link's
 * own behaviour: the file as plain text in a new tab.
 *
 * @param {MouseEvent} event - the click
 * @return {boolean}
 */
export function isPlainLeftClick(event) {
	return !!event && (event.button ?? 0) === 0
		&& !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey
		&& !event.defaultPrevented
}
