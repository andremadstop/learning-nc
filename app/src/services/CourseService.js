import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * API client for course certification config + pass status (Phase 154).
 *
 * Consumed by CourseTabVerwaltung.vue (instructor cert config) and
 * CourseSummary.vue (student pass status) in 154-05.
 */

/**
 * Update cert configuration for a course (instructor only).
 * @param {number} courseId — the course to configure
 * @param {object} config — any subset of: certEnabled, certPassPercent, certRequiredPoolIds, certValidityDays
 * @return {Promise<object>} updated cert config fields
 */
export async function updateCertConfig(courseId, config) {
	const response = await axios.patch(
		generateUrl(`/apps/learning/api/courses/${courseId}/cert-config`),
		config,
	)
	return response.data
}

/**
 * Get the pass status for the current user in a course.
 * Returns 403 if the user is not enrolled (IDOR guard enforced server-side).
 * @param {number} courseId — the course to evaluate
 * @return {Promise<{applicable: boolean, passed: boolean, score: number|null, threshold: number, poolsMastered: boolean, passedAt: number|null}>}
 */
export async function getPassStatus(courseId) {
	const response = await axios.get(
		generateUrl(`/apps/learning/api/courses/${courseId}/pass-status`),
	)
	return response.data
}
