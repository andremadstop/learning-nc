/**
 * Media per question (GitHub #66), the logic of QuestionMediaPlayer.vue: which language, which
 * subtitle line, which keywords and which part of the "misconception → right" contrast are on
 * the stage at a moment of a track. Pure functions, so the stage is the same whether the clock is
 * the <audio> element or the learner stepping through the lines without sound.
 *
 * What the server sends (QuestionMediaService):
 * - tracks: [{id, kind: 'intro'|'dialog', lang, duration_ms, timeline: [{role, text, start, end}], url}]
 *   (timeline in seconds, validated by QuestionMediaBundle::normalizeTimeline; role may be null)
 * - cues: a JSON object the import does NOT check beyond "is an object", e.g.
 *   {icon, <lang>: {line_keywords: [[kw, …] per dialog line], contrast: {wrong, right}, merksatz, scene}}
 *   Everything read from it is therefore checked here and shown as plain text only.
 *
 * The cues format names no "doubter" role. The contrast follows the dialog positions instead: the
 * misconception appears with the second line (index 1), and it is struck through and the right
 * reading appears with the next line spoken by somebody else.
 */

const LANG_FALLBACK = ['en', 'de']
// Keywords stay on the stage this long before the line they belong to is spoken.
const KEYWORD_LEAD = 0.15
// A line stays "current" this long after its end (pauses between lines keep the subtitle).
const LINE_TAIL = 0.35
// Keywords at most in the middle of the stage; older ones move to the trail.
export const STAGE_KEYWORDS = 2
export const WRONG_LINE_INDEX = 1

const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
const text = (value) => (typeof value === 'string' ? value.trim() : '')

/** Tracks of one kind, well-formed only. */
export function tracksOfKind(tracks, kind) {
	if (!Array.isArray(tracks)) {
		return []
	}
	return tracks.filter((track) => isObject(track)
		&& track.kind === kind
		&& typeof track.url === 'string' && track.url !== ''
		&& typeof track.lang === 'string' && track.lang !== '')
}

/**
 * #66: the spoken explanations of the chosen options, from `question_media.option_tracks`
 * ([{answer_id, position, tracks}], answer order) — one list of well-formed tracks (kind opt<n>)
 * per chosen option that has a voice, in answer order. Nothing for options not chosen: their
 * voices stay unplayed (the learner can read every option's explanation below).
 */
export function optionSegments(optionTracks, selectedIds) {
	if (!Array.isArray(optionTracks) || !Array.isArray(selectedIds) || selectedIds.length === 0) {
		return []
	}
	const chosen = new Set(selectedIds.map((id) => String(id)))
	return optionTracks
		.filter((entry) => isObject(entry) && chosen.has(String(entry.answer_id)))
		.map((entry) => (Array.isArray(entry.tracks) ? entry.tracks : []).filter((track) => isObject(track)
			&& typeof track.kind === 'string' && /^opt\d+$/.test(track.kind)
			&& typeof track.url === 'string' && track.url !== ''
			&& typeof track.lang === 'string' && track.lang !== ''))
		.filter((tracks) => tracks.length > 0)
}

/** Languages of the tracks, in first-seen order, without duplicates. */
export function trackLanguages(tracks) {
	return [...new Set((tracks || []).map((track) => track.lang))]
}

/**
 * The language to play: the one asked for (content language of the question, else the user's
 * interface language, also as its base like "de" for "de-DE"), else English, else German, else the
 * first one there is. Returns '' when there are no tracks.
 *
 * @param {string[]} available
 * @param {Array<string|null|undefined>} preferred most wanted first
 */
export function pickLanguage(available, preferred = []) {
	if (!Array.isArray(available) || available.length === 0) {
		return ''
	}
	const candidates = []
	for (const raw of [...preferred, ...LANG_FALLBACK]) {
		if (typeof raw !== 'string' || raw === '') {
			continue
		}
		const lang = raw.toLowerCase().replace('_', '-')
		candidates.push(lang, lang.split('-')[0])
	}
	return candidates.find((lang) => available.includes(lang)) ?? available[0]
}

/** The track of a language, or the first one. */
export function trackFor(tracks, lang) {
	return (tracks || []).find((track) => track.lang === lang) ?? (tracks || [])[0] ?? null
}

/** The timeline of a track, well-formed lines only, in time order. */
export function timelineOf(track) {
	if (!track || !Array.isArray(track.timeline)) {
		return []
	}
	return track.timeline
		.filter((line) => isObject(line) && typeof line.text === 'string'
			&& Number.isFinite(line.start) && Number.isFinite(line.end) && line.end >= line.start)
		.map((line) => ({ role: typeof line.role === 'string' ? line.role : '', text: line.text, start: line.start, end: line.end }))
		.sort((a, b) => a.start - b.start)
}

/**
 * The cues of a language (falling back like the audio), as checked plain text:
 * {icon, scene, merksatz, wrong, right, lineKeywords: string[][]}.
 */
export function cuesFor(cues, lang) {
	const empty = { icon: '', scene: '', merksatz: '', wrong: '', right: '', lineKeywords: [] }
	if (!isObject(cues)) {
		return empty
	}
	const langs = Object.keys(cues).filter((key) => isObject(cues[key]))
	const chosen = pickLanguage(langs, [lang])
	const entry = chosen ? cues[chosen] : {}
	const contrast = isObject(entry.contrast) ? entry.contrast : {}
	const lineKeywords = Array.isArray(entry.line_keywords)
		? entry.line_keywords.map((list) => (Array.isArray(list) ? list.map(text).filter((kw) => kw !== '') : []))
		: []
	return {
		// Only a short symbol (an emoji), never a text the stage would have to translate.
		icon: text(cues.icon).length <= 8 ? text(cues.icon) : '',
		scene: text(entry.scene),
		merksatz: text(entry.merksatz),
		wrong: text(contrast.wrong),
		right: text(contrast.right),
		lineKeywords,
	}
}

/** Index of the line spoken at time t (with a short tail after its end), or -1. */
export function lineIndexAt(timeline, t) {
	let found = -1
	timeline.forEach((line, index) => {
		if (t >= line.start && t <= line.end + LINE_TAIL) {
			found = index
		}
	})
	return found
}

/**
 * Index of the last line that has started by time t, or -1 before the first one. Unlike
 * lineIndexAt it stays on the line just spoken during a pause between two lines, so "next line",
 * "previous line" and "start over" keep their place there instead of losing it.
 */
export function lastStartedIndex(timeline, t) {
	let found = -1
	timeline.forEach((line, index) => {
		if (t >= line.start) {
			found = index
		}
	})
	return found
}

/**
 * When a keyword of a line is said: its position in the line text, spread over the line's time.
 * A keyword that is not in the text (another wording, another language) comes with the line start.
 */
export function keywordTime(line, keyword) {
	const position = line.text.toLowerCase().indexOf(keyword.toLowerCase())
	if (position <= 0 || line.text.length === 0) {
		return line.start
	}
	return line.start + (line.end - line.start) * position / line.text.length
}

/** Index of the line that resolves the misconception: the first one after it by another speaker. */
export function resolveLineIndex(timeline) {
	if (timeline.length <= WRONG_LINE_INDEX) {
		return -1
	}
	const wrongRole = timeline[WRONG_LINE_INDEX].role
	for (let index = WRONG_LINE_INDEX + 1; index < timeline.length; index++) {
		if (timeline[index].role !== wrongRole || wrongRole === '') {
			return index
		}
	}
	return -1
}

/**
 * What the stage shows. Position is either {t} (seconds of the playing track) or
 * {lineIndex} (stepping without sound: that line and everything before it are complete).
 * `ended` shows the key takeaway.
 *
 * @return {{lineIndex: number, line: ?object, keywords: Array<{key: string, text: string, role: string, doubt: boolean}>, trail: string[], wrong: boolean, struck: boolean, right: boolean, merksatz: boolean}}
 */
export function stageAt(timeline, cues, position, { dialog = true, ended = false } = {}) {
	const byLine = 'lineIndex' in position
	const lineIndex = byLine
		? Math.min(Math.max(position.lineIndex, -1), timeline.length - 1)
		: lineIndexAt(timeline, position.t)
	const t = byLine ? null : position.t
	const reached = (index) => (byLine ? index <= lineIndex : (timeline[index] && t >= timeline[index].start))
	const stage = {
		lineIndex,
		line: lineIndex >= 0 ? timeline[lineIndex] : null,
		keywords: [],
		trail: [],
		wrong: false,
		struck: false,
		right: false,
		merksatz: false,
	}
	if (!dialog) {
		return stage
	}

	const all = []
	timeline.forEach((line, index) => {
		const keywords = cues.lineKeywords[index] || []
		keywords.forEach((keyword, n) => {
			const due = byLine ? index <= lineIndex : t >= keywordTime(line, keyword) - KEYWORD_LEAD
			if (due) {
				// The keywords of the misconception line are marked as doubtful (wavy underline).
				all.push({ key: index + '|' + n, text: keyword, role: line.role, doubt: index === WRONG_LINE_INDEX })
			}
		})
	})
	stage.keywords = all.slice(-STAGE_KEYWORDS)
	stage.trail = all.slice(0, Math.max(0, all.length - STAGE_KEYWORDS)).map((keyword) => keyword.text)

	if (cues.wrong || cues.right) {
		const resolve = resolveLineIndex(timeline)
		stage.wrong = cues.wrong !== '' && reached(WRONG_LINE_INDEX)
		stage.struck = stage.wrong && resolve >= 0 && reached(resolve)
		stage.right = cues.right !== '' && ((resolve >= 0 && reached(resolve)) || ended)
		if (ended && stage.wrong) {
			stage.struck = true
		}
	}
	stage.merksatz = ended && cues.merksatz !== ''
	return stage
}

/** Display name of a role: "MARA" → "Mara", "dr_keller" → "Dr keller". */
export function roleName(role) {
	const value = text(role).replace(/[_]+/g, ' ')
	if (value === '') {
		return ''
	}
	const lower = value.toLocaleLowerCase()
	return value === value.toLocaleUpperCase() ? lower.charAt(0).toLocaleUpperCase() + lower.slice(1) : value
}

// Speaker colours: readable as dot backgrounds with dark text in light and dark themes.
const ROLE_COLOURS = ['#4fd1c5', '#f6ad55', '#fc8181', '#a3bffa', '#b794f4', '#f6e05e', '#68d391', '#fbb6ce']

/** A stable colour per role name (same speaker, same colour, in every question). */
export function roleColour(role) {
	const value = text(role).toUpperCase()
	let hash = 0
	for (const char of value) {
		hash = (hash * 31 + char.codePointAt(0)) >>> 0
	}
	return ROLE_COLOURS[hash % ROLE_COLOURS.length]
}

/** "1:05" for 65 seconds. */
export function formatSeconds(seconds) {
	const total = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0))
	return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0')
}

/** The native name of a language code, e.g. "Deutsch" for "de"; the code when unknown. */
export function languageLabel(lang) {
	try {
		const name = new Intl.DisplayNames([lang], { type: 'language' }).of(lang)
		if (name && name.toLowerCase() !== lang.toLowerCase()) {
			return name.charAt(0).toLocaleUpperCase(lang) + name.slice(1)
		}
	} catch (e) {
		// an unknown code: show it as it is
	}
	return lang.toUpperCase()
}
