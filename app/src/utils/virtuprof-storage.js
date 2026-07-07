/**
 * localStorage-Schlüssel und Tagesstempel für die VirtuProf-"daily"-Trigger.
 * Aus VirtuProf.vue extrahiert (Zero-Behavior-Change). Nutzt den globalen
 * Nextcloud-`OC` unverändert.
 */

/**
 * Heutiges Datum als ISO-Tagesstempel (YYYY-MM-DD).
 * @returns {string}
 */
export function todayStamp() {
	return new Date().toISOString().slice(0, 10)
}

/**
 * Nutzer- und trigger-spezifischer localStorage-Schlüssel.
 * @param {string} triggerId
 * @returns {string}
 */
export function storageKey(triggerId) {
	const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser())
		? (OC.getCurrentUser().uid || 'user')
		: 'user'
	return `learning:virtuprof:daily:${uid}:${triggerId}`
}
