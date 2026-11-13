/**
 * Pure, dependency-free helpers for the instructor compliance-report section.
 *
 * These back CourseTabTeilnehmer.vue's cert-report table + CSV export. Keeping
 * the branchable logic here (no Vue, no axios) lets Vitest exercise it in
 * isolation (the 155-06 lesson: component mounting is not a pattern in this
 * codebase — `tests/unit/**\/*.test.js` only).
 */

// Stable param order so the table fetch and the CSV download produce an
// identical query string for the same filter state.
const PARAM_ORDER = ['from', 'to', 'expiringDays']

/**
 * Build a query string carrying ONLY the set filter params.
 * null / undefined / '' are omitted (no stray `from=&to=`); 0 is a real value.
 *
 * @param {object} filters - { from, to, expiringDays }
 * @return {string} e.g. '' | 'expiringDays=30' | 'from=1000&to=2000'
 */
export function buildCertReportQuery(filters = {}) {
	const parts = []
	for (const key of PARAM_ORDER) {
		const value = filters[key]
		if (value === null || value === undefined || value === '') {
			continue
		}
		parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value))
	}
	return parts.join('&')
}

/**
 * The compliance section is instructor-only AND certifying-course-only.
 * course.cert_enabled is snake_case (the entity's jsonSerialize emits snake_case).
 *
 * @param {boolean} isInstructor - viewer is the course owner/instructor
 * @param {object|null|undefined} course - the course prop (snake_case fields)
 * @return {boolean}
 */
export function shouldShowCertReport(isInstructor, course) {
	return isInstructor === true && !!course && !!course.cert_enabled
}

/**
 * Render a report score: the backend emits '' when no score was recorded.
 *
 * @param {string} score
 * @return {string}
 */
export function formatScore(score) {
	return score === '' || score === null || score === undefined ? '—' : score
}

/**
 * Format a unix-seconds timestamp as YYYY-MM-DD (UTC), '' when falsy.
 *
 * @param {number|null} unix - seconds since epoch
 * @return {string}
 */
export function formatDate(unix) {
	if (!unix) {
		return ''
	}
	const d = new Date(unix * 1000)
	const y = d.getUTCFullYear()
	const m = String(d.getUTCMonth() + 1).padStart(2, '0')
	const day = String(d.getUTCDate()).padStart(2, '0')
	return `${y}-${m}-${day}`
}

/**
 * GitHub #35: whether a LEARNER sees the course's completion tab. A certifying course always
 * shows it — that is where the certificate lives — independent of mode_config.course_summary,
 * which only releases the summary statistics. Before, a certifying course with course_summary
 * off (the default) hid the only place a learner could get a certificate.
 *
 * @param {object|null|undefined} course - the course (snake_case fields)
 * @return {boolean}
 */
export function shouldShowCompletionTab(course) {
	if (!course) return false
	return course.cert_enabled === true || course.mode_config?.course_summary === true
}

/**
 * Whether the summary statistics (mastery, XP, badges, AI reflection …) are released to learners.
 *
 * @param {object|null|undefined} course - the course (snake_case fields)
 * @return {boolean}
 */
export function isSummaryReleased(course) {
	return course?.mode_config?.course_summary === true
}
