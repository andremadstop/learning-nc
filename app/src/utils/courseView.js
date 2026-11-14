/**
 * Pure helpers for how a course is shown to the current viewer (GitHub #35).
 *
 * The student view is a UI preview only. The server still enforces every permission from the
 * real course membership; nothing here grants or removes a right. It only decides which tabs
 * the browser renders. What the instructor does in the preview is real (an exam attempt is
 * stored like any other), but the server never certifies an instructor of the course
 * (PassCriteriaService), so the preview leaves no certificate or compliance record behind.
 */

import { shouldShowCompletionTab } from './cert-report.js'

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
 * @param {boolean} options.aiEnabled - the admin switched the AI assistant on (GET /api/settings/tools);
 *   the knowledge import only feeds the assistant, so without it the tab is left out
 * @return {string[]}
 */
export function lernraumTabIds(course, { actsAsInstructor = false, hasCourseTools = false, hasVideos = false, aiEnabled = false } = {}) {
	if (actsAsInstructor) {
		const tabs = ['pools']
		if (hasCourseTools) tabs.push('tools')
		tabs.push('curriculum', 'materials')
		if (aiEnabled) tabs.push('knowledge')
		return tabs
	}
	const modeConfig = course?.mode_config || {}
	const enabled = (key) => modeConfig[key] !== false
	const tabs = []
	if (hasVideos) tabs.push('videos')
	// GitHub #35: learners read the material before they practise and take the exam, so the
	// material comes before the learning modes (and becomes the landing tab without videos).
	if (course?.material_folder) tabs.push('materials')
	if (enabled('training')) tabs.push('training')
	if (enabled('leitner')) tabs.push('leitner')
	if (enabled('exam')) tabs.push('exam')
	// Codeberg #9: independent of mode_config.exam, which governs the pool exam tab —
	// a course can offer its own practice exam with the preset exam switched off.
	if (course?.practice_enabled) tabs.push('practice')
	if (hasCourseTools) tabs.push('tools')
	return tabs
}

/**
 * The leaf tabs of the Wettbewerb (competition) area, in display order. CourseDetail (routing,
 * whether the mega-tab shows at all) and CourseTabWettbewerb (the sub-navigation) both read
 * this list (GitHub #36).
 *
 * League, Arena (Duel and Gameshow) and Adventure are game modes: each needs its course switch on
 * and the admin's gamification on, for instructors too (GitHub #51/#53: an instructor saw Arena as
 * an empty page and League and Adventure despite their switches being off). Arena has no switch of
 * its own, it is the home of Duel and Gameshow. The leaderboard ranks learners by XP; with
 * gamification off learners lose it, instructors keep it ranked by mastered cards (GitHub #36).
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {object} options
 * @param {boolean} options.actsAsInstructor - see actsAsCourseInstructor()
 * @param {boolean} options.gamificationEnabled - the admin's global switch; anything but false counts as on
 * @return {string[]}
 */
export function wettbewerbTabIds(course, { actsAsInstructor = false, gamificationEnabled = true } = {}) {
	const modeConfig = course?.mode_config || {}
	const gameOn = gamificationEnabled !== false
	const enabled = (key) => gameOn && modeConfig[key] !== false
	const tabs = []
	if (gameOn || actsAsInstructor) tabs.push('leaderboard')
	if (enabled('league')) tabs.push('league')
	if (enabled('duel') || enabled('gameshow')) tabs.push('arena')
	if (enabled('abenteuer')) tabs.push('abenteuer')
	return tabs
}

/**
 * Whether the learner's own course progress ("Mein Fortschritt") still has something to show.
 * With gamification and Leitner both off, XP, level, badges and the boxes are gone; what is left
 * is the list of recent sessions and the accuracy per pool, and a course with a completion tab
 * shows both there (GitHub #36; CourseRecentSessions with showPoolAccuracy). Without a completion
 * tab the progress tab stays, it is their only home.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {boolean} gamificationEnabled - the admin's global switch; anything but false counts as on
 * @return {boolean}
 */
export function learnerHasProgressTab(course, gamificationEnabled = true) {
	const leitnerEnabled = course?.mode_config?.leitner !== false
	return gamificationEnabled !== false || leitnerEnabled || !shouldShowCompletionTab(course)
}

/**
 * The leaf tabs of the Teilnehmer (participants) area, in display order. CourseDetail (routing,
 * whether the mega-tab shows at all) and CourseTabTeilnehmer (the sub-navigation) both read this
 * list (GitHub #36).
 *
 * Learners land on their own progress first. The class register has its own course switch
 * (mode_config.classbook, a missing key counts as on); without visible profiles it showed an
 * empty page as the first thing a learner saw. The completion tab is no leaf here for learners,
 * it is an area of its own (abschlussTabIds). An empty list hides the area. Instructors keep
 * every leaf.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {object} options
 * @param {boolean} options.actsAsInstructor - see actsAsCourseInstructor()
 * @param {boolean} options.gamificationEnabled - the admin's global switch; anything but false counts as on
 * @return {string[]}
 */
export function teilnehmerTabIds(course, { actsAsInstructor = false, gamificationEnabled = true } = {}) {
	if (actsAsInstructor) {
		return ['members', 'classbook', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'summary']
	}
	const modeConfig = course?.mode_config || {}
	const tabs = []
	if (learnerHasProgressTab(course, gamificationEnabled)) tabs.push('my-progress')
	if (modeConfig.classbook !== false) tabs.push('classbook')
	return tabs
}

/**
 * The leaf tabs of the learners' Abschluss (completion) area: the course summary with the
 * certificate and the recent sessions (GitHub #36). A certifying course always has it (the
 * certificate lives there, GitHub #35); course_summary only releases the summary statistics
 * inside it. Instructors reach the summary and the compliance report under Teilnehmer, as before.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {object} options
 * @param {boolean} options.actsAsInstructor - see actsAsCourseInstructor()
 * @return {string[]}
 */
export function abschlussTabIds(course, { actsAsInstructor = false } = {}) {
	if (actsAsInstructor) return []
	return shouldShowCompletionTab(course) ? ['summary'] : []
}

/**
 * The leaf tabs of the Kommunikation area, in display order. CourseDetail (routing, deep links)
 * and CourseTabKommunikation (the sub-navigation) both read this list (GitHub #40).
 *
 * Learning partners have their own course switch (mode_config.buddies, a missing key counts as
 * on), so a mandatory training can leave them out; a deep link to the hidden leaf then falls back
 * to the feed. The swarm feeds the AI assistant's course knowledge and exists only while the
 * admin has AI on (GitHub #35). Instructors keep their three leaves.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @param {object} options
 * @param {boolean} options.actsAsInstructor - see actsAsCourseInstructor()
 * @param {boolean} options.aiEnabled - the admin switched the AI assistant on
 * @return {string[]}
 */
export function kommunikationTabIds(course, { actsAsInstructor = false, aiEnabled = false } = {}) {
	if (actsAsInstructor) {
		return ['announcements', 'feed', 'requests']
	}
	const tabs = ['feed']
	if (buddiesEnabled(course)) tabs.push('buddies')
	if (aiEnabled) tabs.push('schwarm')
	return tabs
}

/**
 * Whether the course shows learning partners (mode_config.buddies, GitHub #40). A course saved
 * before the switch existed keeps them.
 *
 * @param {object|null|undefined} course - the course detail (snake_case fields)
 * @return {boolean}
 */
export function buddiesEnabled(course) {
	return course?.mode_config?.buddies !== false
}
