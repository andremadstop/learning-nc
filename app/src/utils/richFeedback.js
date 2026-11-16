/**
 * Rich feedback of a question (GitHub #66): a hint before answering, feedback per answer option,
 * a background fact and a key takeaway after answering.
 *
 * The pool JSON reader mirrors lib/Service/RichFeedbackFormat.php (the import preview has to
 * refuse and report exactly what the server does); both are held to the shared cases in
 * tests/Fixtures/rich-feedback-cases.json. The display helpers work on what the server sends
 * after an answer (`rich_feedback`, RichFeedbackService).
 */

// Mirrors RichFeedbackFormat::*_MAX_LENGTH.
export const HINT_MAX_LENGTH = 2000
export const BACKGROUND_MAX_LENGTH = 5000
export const TAKEAWAY_MAX_LENGTH = 500
export const WHY_MAX_LENGTH = 5000
export const MISCONCEPTION_MAX_LENGTH = 1000

const TEXT_ALIASES = {
	hint: ['hint', 'hinweis'],
	background_fact: ['background_fact', 'backgroundFact', 'background', 'hintergrund'],
	key_takeaway: ['key_takeaway', 'keyTakeaway', 'takeaway', 'merksatz'],
}
const TEXT_MAX = {
	hint: HINT_MAX_LENGTH,
	background_fact: BACKGROUND_MAX_LENGTH,
	key_takeaway: TAKEAWAY_MAX_LENGTH,
}
const LANGUAGE_ORDER = ['en', 'de']

const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object, key)
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
// PHP trim() characters (space, \t, \n, \r, \0, \x0B), not JS's Unicode whitespace.
const phpTrim = (value) => value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
const mbLength = (value) => Array.from(value).length

/** Text of a string or {lang: text} value; languages not taken are pushed to `dropped`. */
function textOf(value, lang, dropped) {
	if (typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value))) {
		const text = phpTrim(String(value))
		return text === '' ? null : text
	}
	if (!isObject(value)) {
		return null
	}
	const available = {}
	for (const [key, text] of Object.entries(value)) {
		if (typeof text === 'string' && phpTrim(text) !== '') {
			available[key.toLowerCase()] = phpTrim(text)
		}
	}
	const keys = Object.keys(available)
	if (keys.length === 0) {
		return null
	}
	const candidates = [...(lang !== null ? [lang] : []), ...LANGUAGE_ORDER]
	const chosen = candidates.find((candidate) => hasOwn(available, candidate)) ?? keys[0]
	for (const key of keys) {
		if (key !== chosen) {
			dropped.push(key)
		}
	}
	return available[chosen]
}

/** 0-based answer index an entry names: option_index, else letter, else its list order. */
function optionIndex(entry, listIndex) {
	const index = entry.option_index ?? entry.index
	if (Number.isInteger(index) && index >= 0) {
		return index
	}
	if (typeof index === 'string' && /^\d+$/.test(phpTrim(index))) {
		return Number(phpTrim(index))
	}
	const letter = typeof entry.letter === 'string' ? phpTrim(entry.letter).toUpperCase() : ''
	if (/^[A-Z]$/.test(letter)) {
		return letter.charCodeAt(0) - 65
	}
	return listIndex
}

/**
 * RichFeedbackFormat::fromItem(): the rich feedback of one question item of a pool JSON file.
 *
 * @param {object} item question item
 * @param {Map<number, number>|null} positionMap index in the file's answers → stored position; null = same index
 * @param {boolean[]} isCorrect is_correct of the stored answers, for the verdict check
 * @return {{hint: ?string, background_fact: ?string, key_takeaway: ?string, option_feedback: Array<{position: number, why: ?string, misconception: ?string}>, dropped_languages: string[], verdict_mismatches: number, unmatched: number}}
 */
export function richFeedbackFromItem(item, positionMap = null, isCorrect = []) {
	const lang = typeof item.lang === 'string' ? phpTrim(item.lang).toLowerCase() : null
	const dropped = []
	const result = {
		hint: null,
		background_fact: null,
		key_takeaway: null,
		option_feedback: [],
		dropped_languages: [],
		verdict_mismatches: 0,
		unmatched: 0,
	}
	for (const [field, keys] of Object.entries(TEXT_ALIASES)) {
		for (const key of keys) {
			if (hasOwn(item, key)) {
				result[field] = textOf(item[key], lang, dropped)
				if (result[field] !== null) {
					break
				}
			}
		}
	}

	const answers = Array.isArray(item.answers) ? item.answers : (isObject(item.answers) ? Object.values(item.answers) : [])
	const map = (fileIndex) => {
		if (positionMap === null) {
			return (answers.length === 0 || fileIndex < answers.length) ? fileIndex : null
		}
		return positionMap.has(fileIndex) ? positionMap.get(fileIndex) : null
	}

	const byPosition = new Map()
	answers.forEach((answer, index) => {
		if (!isObject(answer)) {
			return
		}
		const why = textOf(answer.why ?? answer.feedback ?? null, lang, dropped)
		const misconception = textOf(answer.misconception ?? answer.trap ?? null, lang, dropped)
		if (why === null && misconception === null) {
			return
		}
		const position = map(index)
		if (position === null) {
			result.unmatched++
			return
		}
		byPosition.set(position, { position, why, misconception })
	})

	const rawList = item.feedback_per_option ?? item.option_feedback ?? null
	const list = Array.isArray(rawList) ? rawList : (isObject(rawList) ? Object.values(rawList) : null)
	if (list !== null) {
		list.forEach((entry, listIndex) => {
			if (!isObject(entry)) {
				return
			}
			const why = textOf(entry.why ?? entry.feedback ?? null, lang, dropped)
			const misconception = textOf(entry.misconception ?? entry.trap ?? null, lang, dropped)
			const position = map(optionIndex(entry, listIndex))
			if (position === null) {
				if (why !== null || misconception !== null) {
					result.unmatched++
				}
				return
			}
			const verdict = typeof entry.verdict === 'string' ? phpTrim(entry.verdict).toLowerCase() : ''
			if (position < isCorrect.length && (verdict === 'correct' || verdict === 'wrong')
				&& (verdict === 'correct') !== isCorrect[position]) {
				result.verdict_mismatches++
			}
			if (why === null && misconception === null) {
				return
			}
			byPosition.set(position, { position, why, misconception })
		})
	}

	result.option_feedback = [...byPosition.values()].sort((a, b) => a.position - b.position)
	result.dropped_languages = [...new Set(dropped)]
	return result
}

/**
 * Whether RichFeedbackFormat::normalize() refuses this rich feedback (a field over the editor's
 * limit), after the per-option feedback is cut to the first `answerCount` answers as the import does.
 */
export function richFeedbackTooLong(rich, answerCount) {
	for (const [field, max] of Object.entries(TEXT_MAX)) {
		if (rich[field] !== null && mbLength(rich[field]) > max) {
			return true
		}
	}
	return rich.option_feedback
		.filter((o) => o.position < answerCount)
		.some((o) => (o.why !== null && mbLength(o.why) > WHY_MAX_LENGTH)
			|| (o.misconception !== null && mbLength(o.misconception) > MISCONCEPTION_MAX_LENGTH))
}

// ---------------------------------------------------------------------------------------------
// Display: what the learner sees after answering
// ---------------------------------------------------------------------------------------------

/** The hint of a question payload, or '' (an exam attempt never carries one). */
export function questionHint(question) {
	return question && typeof question.hint === 'string' ? question.hint.trim() : ''
}

/** Whether a `rich_feedback` payload has anything to show. */
export function hasRichFeedback(rich) {
	return isObject(rich) && (
		(Array.isArray(rich.option_feedback) && rich.option_feedback.length > 0)
		|| (typeof rich.background_fact === 'string' && rich.background_fact.trim() !== '')
		|| (typeof rich.key_takeaway === 'string' && rich.key_takeaway.trim() !== '')
	)
}

/**
 * The per-option feedback to show after an answer: every chosen option that has feedback, and —
 * when the answer was not right — the correct options as well, so a learner who chose wrong
 * also reads why the right one is right. In the order of the answers on screen.
 *
 * @param {object|null} rich `rich_feedback` of the answer response or review entry
 * @param {Array<{id: number, text: string}>} answers answers as shown (their order is kept)
 * @param {number[]} selectedIds chosen answer ids
 * @return {Array<{answerId: number, text: string, isCorrect: boolean, selected: boolean, why: ?string, misconception: ?string}>}
 */
export function optionFeedbackToShow(rich, answers, selectedIds) {
	if (!isObject(rich) || !Array.isArray(rich.option_feedback)) {
		return []
	}
	const byAnswer = new Map(rich.option_feedback.map((o) => [Number(o.answer_id), o]))
	const selected = new Set((selectedIds || []).map(Number))
	const answeredRight = rich.option_feedback.length > 0
		&& [...selected].every((id) => byAnswer.get(id)?.is_correct === true)
		&& rich.option_feedback.filter((o) => o.is_correct).every((o) => selected.has(Number(o.answer_id)))
	const shown = []
	for (const answer of answers || []) {
		const id = Number(answer.id)
		const entry = byAnswer.get(id)
		if (!entry) {
			continue
		}
		const isSelected = selected.has(id)
		if (!isSelected && !(entry.is_correct && !answeredRight)) {
			continue
		}
		shown.push({
			answerId: id,
			text: answer.text ?? '',
			isCorrect: entry.is_correct === true,
			selected: isSelected,
			why: entry.why ?? null,
			misconception: entry.misconception ?? null,
		})
	}
	return shown
}

// ---------------------------------------------------------------------------------------------
// The question editor
// ---------------------------------------------------------------------------------------------

/**
 * The form's answers with their stored feedback (option_feedback by position = index in the
 * stored answer list, which is the order the editor shows).
 */
export function answersWithFeedback(answers, optionFeedback) {
	const byPosition = new Map((Array.isArray(optionFeedback) ? optionFeedback : []).map((o) => [Number(o.position), o]))
	return answers.map((answer, index) => {
		const entry = byPosition.get(index)
		return { ...answer, why: entry?.why ?? '', misconception: entry?.misconception ?? '' }
	})
}

/**
 * The option_feedback the editor saves, for the answers it saves (blank answers already
 * dropped): position = index in that list, the position QuestionService gives each answer.
 */
export function optionFeedbackFromAnswers(answers) {
	const list = []
	answers.forEach((answer, position) => {
		const why = typeof answer.why === 'string' ? answer.why.trim() : ''
		const misconception = typeof answer.misconception === 'string' ? answer.misconception.trim() : ''
		if (why !== '' || misconception !== '') {
			list.push({ position, why: why || null, misconception: misconception || null })
		}
	})
	return list
}
