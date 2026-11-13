/**
 * Pure helpers for how a course is shown to the current viewer (GitHub #35).
 *
 * The student view is a UI preview only. The server still enforces every permission from the
 * real course membership; nothing here grants or removes a right. It only decides which tabs
 * the browser renders. What the instructor does in the preview is real (an exam attempt is
 * stored like any other), but the server never certifies an instructor of the course
 * (PassCriteriaService), so the preview leaves no certificate or compliance record behind.
 */

/**
 * Whether the viewer gets the instructor tabs of a course.
 *
 * Only the course membership counts (course.is_instructor, computed by the server). The global
 * role does not: an instructor enrolled as a student in a colleague's course is a student there,
 * and a global student enrolled as a co-instructor is an instructor there. The student view
 * turns the instructor tabs off without touching the membership.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {boolean} studentViewActive - the instructor switched on the student view
 * @return {boolean}
 */
export function actsAsCourseInstructor(course, studentViewActive) {
	return course?.is_instructor === true && studentViewActive !== true
}

/**
 * The leaf tabs of the Lernraum, in display order. CourseDetail (routing, default tab) and
 * CourseTabLernraum (the sub-navigation) both read this list, so they cannot drift apart:
 * CourseDetail used to always list Training and never the required content.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {object} options
 * @param {boolean} options.actsAsInstructor - see actsAsCourseInstructor()
 * @param {boolean} options.hasCourseTools - at least one simulator is enabled for the course
 * @param {boolean} options.hasVideos - the course has required content (videos or documents)
 * @return {string[]}
 */
export function lernraumTabIds(course, { actsAsInstructor = false, hasCourseTools = false, hasVideos = false } = {}) {
	if (actsAsInstructor) {
		const tabs = ['pools']
		if (hasCourseTools) tabs.push('tools')
		tabs.push('curriculum', 'materials', 'knowledge')
		return tabs
	}
	const modeConfig = course?.mode_config || {}
	const enabled = (key) => modeConfig[key] !== false
	const tabs = []
	if (hasVideos) tabs.push('videos')
	if (enabled('training')) tabs.push('training')
	if (enabled('leitner')) tabs.push('leitner')
	if (enabled('exam')) tabs.push('exam')
	// Codeberg #9: independent of mode_config.exam, which governs the pool exam tab —
	// a course can offer its own practice exam with the preset exam switched off.
	if (course?.practice_enabled) tabs.push('practice')
	if (hasCourseTools) tabs.push('tools')
	if (course?.material_folder) tabs.push('materials')
	return tabs
}
