/**
 * Klassifizierung von Chat-Eingaben für VirtuProf.
 * Reine Funktionen, aus VirtuProf.vue extrahiert (Zero-Behavior-Change).
 */

/**
 * Erkennt, ob eine Nachricht eine Tipp-/Hinweis-Anfrage ist.
 * Match nur bei Wortgrenze (exakt, am Anfang oder am Ende), nicht bei bloßem Substring.
 * @param {string} message
 * @returns {boolean}
 */
export function isHintRequest(message) {
	const lower = message.toLowerCase().trim()
	const hintKeywords = ['tipp', 'hint', 'hilfe', 'help me', 'einen tipp', 'give me a hint', 'gib mir einen tipp']
	return hintKeywords.some(kw => lower === kw || lower.startsWith(kw + ' ') || lower.endsWith(' ' + kw))
}

/**
 * Erkennt kurze/verwirrte Rückfragen oder Meta-Fragen (z. B. "was meinst du?").
 * @param {string} message
 * @returns {boolean}
 */
export function isMetaQuestion(message) {
	const lower = message.toLowerCase().trim()
	// Short confused messages (< 5 words, ends with ?)
	if (lower.endsWith('?') && lower.split(/\s+/).length <= 5) {
		return true
	}
	const metaPatterns = [
		'was meinst du', 'wie meinst du', 'verstehe ich nicht', 'versteh ich nicht',
		'was bedeutet', 'erklaer', 'erklär', 'was heisst', 'was heißt',
		'kannst du das', 'was soll das', 'hä', 'huh', 'what do you mean',
		'i don\'t understand', 'what?', 'explain', 'come again',
		'nochmal bitte', 'bitte nochmal', 'wiederhole', 'repeat',
	]
	return metaPatterns.some(p => lower.includes(p))
}
