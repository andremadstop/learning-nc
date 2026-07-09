/**
 * Reine Display-/Text-Helfer für GameshowMode.vue (Zero-Behavior-Change extrahiert).
 * Keine `this`-Abhängigkeit: benötigter State wird als Argument übergeben, damit die
 * Funktionen isoliert per Vitest testbar sind (erste automatische Coverage für Gameshow).
 */

/** Formatiert einen Unix-Sekunden-Timestamp als lokales Datum + Uhrzeit (HH:MM). */
export function formatDate(ts) {
	if (!ts) return ''
	const d = new Date(ts * 1000)
	return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

/** Balkenbreite (Prozent-String) für einen Score relativ zum Maximum, mindestens 5%. */
export function barWidth(score, maxScore) {
	return Math.max(5, (score / maxScore) * 100) + '%'
}

/**
 * Live-Kommentar zum aktuellen Punktestand.
 * @param {object} ctx { sortedPlayers, isEliminationMode, remainingPlayerCount, recentlyEliminatedPlayers }
 */
export function buildStandingsCommentary(ctx = {}) {
	const {
		sortedPlayers,
		isEliminationMode,
		remainingPlayerCount,
		recentlyEliminatedPlayers = [],
	} = ctx
	if (!sortedPlayers || sortedPlayers.length === 0) return ''
	if (isEliminationMode) {
		const leader = sortedPlayers[0]
		const name1 = leader.display_name || leader.user_id
		if (remainingPlayerCount <= 1) {
			return name1 + ' ist der letzte Überlebende!'
		}
		if (remainingPlayerCount === 2 && sortedPlayers.length >= 2) {
			const rival = sortedPlayers[1]
			const name2 = rival.display_name || rival.user_id
			return 'Sudden Death zwischen ' + name1 + ' und ' + name2 + '!'
		}
		if (recentlyEliminatedPlayers.length > 0) {
			const eliminated = recentlyEliminatedPlayers[0]
			const eliminatedName = eliminated.display_name || eliminated.user_id
			return eliminatedName + ' ist raus. Noch ' + remainingPlayerCount + ' Spieler im Spiel.'
		}
		return name1 + ' fuehrt mit ' + (leader.lives || 0) + ' Leben.'
	}
	const leader = sortedPlayers[0]
	const name1 = leader.display_name || leader.user_id
	if (sortedPlayers.length >= 2) {
		const runner = sortedPlayers[1]
		const name2 = runner.display_name || runner.user_id
		const gap = leader.score - runner.score
		if (gap <= 50) return name2 + ' ist dicht dran an ' + name1 + '!'
		if (gap > 200) return name1 + ' dominiert!'
		return name1 + ' fuehrt mit ' + gap + ' Punkten Vorsprung!'
	}
	return name1 + ' fuehrt!'
}

/**
 * Zufällige Ermutigungs-/Trost-Nachricht.
 * @param {boolean} correct
 * @param {Function} rand injizierbarer Zufallsgenerator (default Math.random) — für Tests.
 */
export function buildAnswerMessage(correct, rand = Math.random) {
	const positives = ['Richtig! Weiter so!', 'Perfekt!', 'Genau richtig!', 'Stark!']
	const negatives = ['Knapp daneben!', 'Leider falsch!', 'Das war nichts!', 'Nicht ganz!']
	const arr = correct ? positives : negatives
	return arr[Math.floor(rand() * arr.length)]
}
