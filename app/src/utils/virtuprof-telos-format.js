/**
 * Formatierung der Telos-Profil-Zusammenfassung für VirtuProf.
 * Aus VirtuProf.vue extrahiert (Zero-Behavior-Change). `vt` wird injiziert.
 */

/**
 * Normalisiert einen Profilwert (String oder Array) zu Anzeigetext oder Fallback.
 * @param {string|string[]} value
 * @param {string} fallback
 * @returns {string}
 */
export function formatTelosSummaryValue(value, fallback) {
	const normalized = Array.isArray(value)
		? value.map(entry => String(entry || '').trim()).filter(Boolean)
		: String(value || '').trim()
	if (Array.isArray(normalized)) {
		return normalized.length > 0 ? normalized.join(', ') : fallback
	}
	return normalized || fallback
}

/**
 * Formatiert Stunden/Woche; nicht-positiv/ungültig → übersetztes "Flexible".
 * @param {number|string} value
 * @param {(key: string) => string} vt
 * @returns {string}
 */
export function formatTelosHours(value, vt) {
	const hours = Number(value)
	if (!Number.isFinite(hours) || hours <= 0) {
		return vt('Flexible')
	}
	const display = Number.isInteger(hours) ? String(hours) : hours.toFixed(1).replace(/\.0$/, '')
	return `${display}h/Woche`
}
