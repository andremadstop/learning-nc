/**
 * GitHub #82: the catch-all route of the app router is named, so the start view can tell that it
 * was reached through an address the app does not know and say so. Kept apart from the router
 * module, which creates the browser history when it is imported.
 */
export const UNKNOWN_PATH_ROUTE = 'unknown-path'

/**
 * Whether a route was reached through the catch-all, i.e. the address named no view of the app
 * (a mistyped or outdated link). The app root itself is not such an address.
 *
 * @param {object|null|undefined} route a resolved route
 * @return {boolean}
 */
export function cameFromUnknownPath(route) {
	return route?.redirectedFrom?.name === UNKNOWN_PATH_ROUTE
}
