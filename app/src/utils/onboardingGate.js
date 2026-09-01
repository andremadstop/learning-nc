/**
 * Decides whether the automatic onboarding still owes a user an appearance.
 *
 * Codeberg #5: until 5.4.1 this question was answered by window.localStorage alone. That store
 * is scoped to one browser on one machine, so a second computer, a different browser, a private
 * window or cleared site data all brought the wizard back — for users who had long since
 * completed or dismissed it. The server value is authoritative now; the local flag survives only
 * so that an acknowledgement which never reached the server is not lost.
 */

/**
 * The storage key 5.4.1 and earlier wrote. Kept byte-identical on purpose: changing it would
 * re-trigger the wizard for everyone whose acknowledgement has not reached the server yet.
 *
 * @param {string} uid the current user id
 * @return {string} the storage key for that user
 */
export function onboardingSeenKey(uid) {
	return `learning:onboarding-seen:${uid}`
}

/**
 * @param {boolean} acknowledged what the server says (onboarding_acknowledged)
 * @param {string} uid the current user id
 * @param {Storage} [storage] injectable for tests; defaults to window.localStorage
 * @return {boolean} true when the onboarding must not be shown again
 */
export function hasSeenOnboarding(acknowledged, uid, storage = undefined) {
	if (acknowledged) {
		return true
	}

	const store = storage || (typeof window !== 'undefined' ? window.localStorage : null)
	if (!store) {
		return false
	}

	try {
		return store.getItem(onboardingSeenKey(uid)) === 'yes'
	} catch {
		// Storage can throw outright (private mode, blocked site data). Treat it as "no local
		// record" rather than letting the exception escape.
		return false
	}
}

/**
 * @return {string} the current Nextcloud user id, or 'user' outside a Nextcloud page
 */
export function currentOnboardingUid() {
	return (typeof OC !== 'undefined'
		&& typeof OC.getCurrentUser === 'function'
		&& OC.getCurrentUser()?.uid) || 'user'
}

/**
 * Whether the instructor slide tour still owes this user an appearance.
 *
 * Codeberg #5: App.vue ran both gates off the same localStorage key and renders the tour with
 * `v-if="showInstructorOnboarding && !showOnboarding"`. An instructor therefore sat through the
 * full wizard and got the slide tour the instant it closed. One intro, one decision — the wizard
 * already covers the instructor case.
 *
 * @param {object} params the decision inputs
 * @param {string} params.role the user's role
 * @param {boolean} params.acknowledged what the server says
 * @param {boolean} params.wizardShowing whether the onboarding wizard is already on screen
 * @param {string} params.uid the current user id
 * @param {Storage} [params.storage] injectable for tests
 * @return {boolean} true when the instructor tour should open
 */
export function shouldShowInstructorIntro({ role, acknowledged, wizardShowing, uid, storage }) {
	if (role !== 'instructor' || wizardShowing) {
		return false
	}

	return !hasSeenOnboarding(acknowledged, uid, storage)
}
