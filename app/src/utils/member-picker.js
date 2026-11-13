/**
 * Helpers of the course member picker (GitHub #35).
 *
 * Options are keyed "user:<id>" / "group:<id>": an account and a group may share an id, and the
 * picker must not mistake one for the other.
 */

/**
 * The body of POST /api/courses/{id}/members/bulk for the current selection.
 *
 * @param {Array<{type: string, value: string}>} selected picker options
 * @param {string} role 'student' | 'instructor'
 * @return {{userIds: string[], groupIds: string[], role: string}}
 */
export function buildBulkPayload(selected, role) {
	const userIds = []
	const groupIds = []
	for (const option of selected) {
		if (option.type === 'group') {
			groupIds.push(option.value)
		} else if (option.type === 'user') {
			userIds.push(option.value)
		}
	}
	return { userIds, groupIds, role }
}

/**
 * Picker options from the GET /member-search response.
 *
 * @param {{users?: Array, groups?: Array}} data response body
 * @return {Array<{id: string, type: string, value: string, label: string, isMember: boolean}>}
 */
export function toOptions(data) {
	const users = (data?.users || []).map((user) => ({
		id: 'user:' + user.id,
		type: 'user',
		value: user.id,
		label: user.label || user.id,
		isMember: user.is_member === true,
	}))
	const groups = (data?.groups || []).map((group) => ({
		id: 'group:' + group.id,
		type: 'group',
		value: group.id,
		label: group.label || group.id,
		isMember: false,
	}))
	return [...users, ...groups]
}
