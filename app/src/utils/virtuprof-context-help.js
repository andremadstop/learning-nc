/**
 * Kontextsensitive Hilfe-Texte und FAQ-Kategorie-Empfehlung für VirtuProf.
 * Aus VirtuProf.vue extrahiert (Zero-Behavior-Change). Statt `this.currentContext`
 * werden die Werte als Parameter übergeben, `vt` wird injiziert.
 */

/**
 * Empfohlene FAQ-Kategorie für den aktuellen Bereich.
 * @param {string} rawArea currentContext.area
 * @returns {string}
 */
export function recommendedFaqCategoryId(rawArea) {
	const area = String(rawArea || '')
	if (area.includes('leitner')) {
		return 'leitner'
	}
	if (area.includes('exam')) {
		return 'exam'
	}
	if (area.includes('duel') || area.includes('arena') || area.includes('gameshow') || area.includes('sprint') || area.includes('elimination')) {
		return 'arena'
	}
	if (area.includes('league')) {
		return 'league'
	}
	if (area.includes('progress') || area.includes('leaderboard')) {
		return 'progress'
	}
	if (area.includes('settings')) {
		return 'settings'
	}
	if (area.includes('required') || area.includes('pool-select') || area.includes('courses')) {
		return 'gettingStarted'
	}
	return 'training'
}

/**
 * Titel + Text für den kontextsensitiven Hilfe-Einstieg.
 * @param {string} rawArea currentContext.area
 * @param {string} rawPoolName currentContext.poolName
 * @param {string} rawCourseTitle currentContext.courseTitle
 * @param {(key: string, params?: object) => string} vt
 * @returns {{title: string, text: string}}
 */
export function contextHelpEntry(rawArea, rawPoolName, rawCourseTitle, vt) {
	const area = String(rawArea || 'courses')
	const poolName = rawPoolName || ''
	const courseTitle = rawCourseTitle || ''

	if (area === 'courses') {
		return {
			title: vt('Courses'),
			text: vt('Choose a course to open its learning modes, leaderboard, league and duels. Each course can have its own assigned pools and rules.'),
		}
	}
	if (area === 'course-training-pool-select') {
		return {
			title: vt('Training in {course}', { course: courseTitle || vt('this course') }),
			text: vt('Pick a pool to start Training. You will get direct feedback after each answer, and required enforced pools may lock the optional ones until you finish them once.'),
		}
	}
	if (area === 'course-leitner-pool-select') {
		return {
			title: vt('Leitner in {course}', { course: courseTitle || vt('this course') }),
			text: vt('Pick a pool to review cards with spaced repetition. The system will show difficult cards more often and mastered cards less often.'),
		}
	}
	if (area === 'course-exam-pool-select') {
		return {
			title: vt('Exam in {course}', { course: courseTitle || vt('this course') }),
			text: vt('Pick a pool to simulate an exam. You will finish the whole session first and only then see the full review.'),
		}
	}
	if (area === 'course-training-active') {
		return {
			title: vt('Training: {pool}', { pool: poolName || vt('selected pool') }),
			text: vt('You are inside an active training pool. Start the session to answer mixed questions with direct feedback after every answer.'),
		}
	}
	if (area === 'course-leitner-active') {
		return {
			title: vt('Leitner: {pool}', { pool: poolName || vt('selected pool') }),
			text: vt('You are inside an active Leitner pool. Review the due cards first; new or difficult questions will come back sooner than mastered ones.'),
		}
	}
	if (area === 'course-exam-active') {
		return {
			title: vt('Exam: {pool}', { pool: poolName || vt('selected pool') }),
			text: vt('You are inside an exam pool. Work through the whole run first; explanations and correct answers are shown at the end.'),
		}
	}
	if (area === 'course-my-progress') {
		return {
			title: vt('My Progress'),
			text: vt('This area shows your own progress in the course, including mastery and answered questions. Use it to see where you still have gaps.'),
		}
	}
	if (area === 'course-leaderboard') {
		return {
			title: vt('Leaderboard'),
			text: vt('The leaderboard compares learners in the same course. XP, mastery and other activity indicators show who is currently ahead.'),
		}
	}
	if (area === 'course-league') {
		return {
			title: vt('Liga'),
			text: vt('The league is course-specific. Challenge classmates, collect points and watch the standings change after each finished duel.'),
		}
	}
	if (area === 'course-duel' || area === 'course-arena' || area === 'arena' || area === 'pool-arena') {
		return {
			title: vt('Arena'),
			text: vt('Hier kannst du Duelle annehmen oder starten sowie Sprint- und Elimination-Runden beitreten. Die Arena bietet drei Modi: Duell (1 gegen 1), Sprint (2–5 Spieler) und Elimination (2–5 Spieler, 3 Leben).'),
		}
	}
	if (area === 'course-arena-sprint' || area === 'arena-sprint') {
		return {
			title: vt('Sprint'),
			text: vt('Im Sprint treten 2 bis 5 Spieler gleichzeitig an. Schnellste richtige Antwort gewinnt die meisten Punkte. Nach jeder der 15 Fragen siehst du die aktuelle Live-Rangliste.'),
		}
	}
	if (area === 'course-arena-elimination' || area === 'arena-elimination') {
		return {
			title: vt('Elimination'),
			text: vt('Starte mit 3 Leben. Jede falsche Antwort kostet ein Leben. Bei 2 verbleibenden Spielern beginnt der Sudden Death — wer zuerst falsch antwortet, scheidet aus.'),
		}
	}
	if (area === 'pool-training') {
		return {
			title: vt('Training'),
			text: vt('You are viewing a regular pool in Training mode. This is the fastest way to practice the pool with immediate feedback.'),
		}
	}
	if (area === 'pool-leitner') {
		return {
			title: vt('Leitner'),
			text: vt('You are viewing a regular pool in Leitner mode. Use it to keep difficult questions coming back until they stick.'),
		}
	}
	if (area === 'pool-exam') {
		return {
			title: vt('Exam'),
			text: vt('You are viewing a regular pool in exam mode. Treat it like a test run without instant correction.'),
		}
	}
	return {
		title: vt('This area'),
		text: vt('You can keep learning here, and I can explain the most important modes and rules whenever you need a quick reminder.'),
	}
}
