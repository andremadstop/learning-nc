/**
 * Whether an exam result row shows the instructor note to the learner.
 *
 * The note is shown only when the question's author marked it visible
 * (note_visible) and there is a non-empty note to show.
 *
 * @param {boolean|number|null|undefined} noteVisible the question's note_visible flag
 * @param {string|null|undefined} instructorNote the note text
 * @return {boolean}
 */
export function shouldShowNote(noteVisible, instructorNote) {
	return !!(noteVisible && instructorNote)
}
