/**
 * Preview of a question import in the dialog, JSON and CSV (GitHub #34).
 *
 * The import dialog used to preview with its own, much narrower reader: it knew only
 * `questions`/`fragen`/`items` and `answers`/`antworten`, threw silently on the documented
 * `{_meta, pools: [...]}` format, and counted "(4 answers)" for items the server then refused
 * ("Need at least 2 answers", 0 imported). This module ports the server's readers
 * (ImportController::importJson / importCsv, PoolJsonFormat) rule for rule, so the preview says
 * what the server will do and what it will store. Keep both in step: every rule here names its
 * PHP counterpart, and app/tests/fixtures/import-json-cases.json runs through both
 * (ImportJsonFixturesTest.php and importPreviewFixtures.test.js).
 *
 * The server translates its own messages (IL10N); the messages here are the preview's, built
 * with the same wording so preview and result read alike.
 *
 * PHP semantics that a JS port gets wrong by default, and how they are mirrored:
 *  - `??` / isset() skip only null or missing values  → `pick()` (never `||`).
 *  - is_array() is true for JSON objects AND arrays   → `isArr()`.
 *  - json_decode makes `{}` an empty list, and an object with keys "0".."n-1" a list
 *                                                      → `isList()`.
 *  - FILTER_VALIDATE_BOOLEAN accepts 1, "1", "true", "on", "yes" → `phpBool()`.
 *  - trim() strips only " \t\n\r\0\x0B"               → `phpTrim()`.
 *  - is_numeric() / (int)                              → `phpIsNumeric()` / `phpInt()`.
 *  - strtolower() changes ASCII letters only           → `asciiLower()`.
 *  - fgetcsv(…, '"', '') (RFC 4180, no backslash escape) → `csvRecords()`.
 *
 * Known, accepted divergence: JavaScript orders integer-like object keys numerically, PHP
 * keeps the file's order. Only an options object with numeric keys written out of order
 * (`{"2": "b", "1": "a"}`) previews in a different order than it is stored.
 */
import { translate as t } from '@nextcloud/l10n'
import { SCENARIO_MAX_LENGTH } from './questionScenario.js'

export const MAX_IMPORT_ITEMS = 500
export const MAX_ANSWERS = 8
/** ImportController::MAX_RAW_ANSWERS: a longer answer list is refused, not cut to MAX_ANSWERS. */
const MAX_RAW_ANSWERS = 50
/** ImportController::CSV_MAX_RECORDS: a header plus MAX_IMPORT_ITEMS, plus one to see "too many". */
const CSV_MAX_RECORDS = MAX_IMPORT_ITEMS + 2
/** PoolJsonFormat::DIFFICULTY_MAX_LENGTH. */
const DIFFICULTY_MAX_LENGTH = 20
const MAX_TEXT_LENGTH = 5000
const MAX_ANSWER_LENGTH = 2000
/** QuestionService::QUESTION_TYPES — the question types the app knows (GitHub #34). */
export const QUESTION_TYPES = ['single', 'multi', 'open', 'pbq']
/** PoolJsonFormat::PBQ_SUBTYPES. */
export const PBQ_SUBTYPES = ['dropdown', 'placement', 'cli', 'cable', 'multi_panel', 'switch_config', 'routing_config', 'diagnostic', 'ranking']

// ---------------------------------------------------------------------------------------------
// Messages of the preview, in the importer's wording.
// ---------------------------------------------------------------------------------------------

// Values are shown through Vue text interpolation, which escapes them already; the
// default HTML escaping of translate() would show a quote in an answer as &quot;.
const RAW = { escape: false }

export const importMessages = {
	invalidJson: () => t('learning', 'Invalid JSON — check for missing brackets, commas, or quotes'),
	unreadableJson: (message) => t('learning', 'The JSON cannot be read: {message}', { message }, undefined, RAW),
	noPools: () => t('learning', 'The file contains no pools — expected a list of questions'),
	// The server's question import says the same in its own words (ImportController); the
	// dialog shows this one, which names the Pools-tab import of whole pool files.
	severalPools: (count) => t('learning', 'This file contains {count} pools, but this dialog imports into one pool. Instructors can import all of them at once in the Pools tab with "Import pools" (or with "occ learning:import-pool-json").', { count }, undefined, RAW),
	expectedList: () => t('learning', 'Expected a list of questions'),
	noQuestions: () => t('learning', 'No questions found in the JSON'),
	tooManyItems: () => t('learning', 'Maximum 500 items per import'),
	notAnObject: (num) => t('learning', 'Item {num}: Not a valid object', { num }, undefined, RAW),
	missingText: (num) => t('learning', 'Item {num}: Missing question text', { num }, undefined, RAW),
	unknownType: (num, type) => t('learning', 'Item {num}: Unknown question type \'{type}\' (valid: {valid}) — question skipped', { num, type, valid: QUESTION_TYPES.join(', ') }, undefined, RAW),
	answersNotList: (num) => t('learning', 'Item {num}: Answers must be a list', { num }, undefined, RAW),
	markerUnmatched: (num, shown) => t('learning', 'Item {num}: Correct answer "{shown}" matches no answer — numbers count from 0 (0 = first answer, or use correct_number to count from 1), letters from A, or give the exact answer text', { num, shown }, undefined, RAW),
	markerDuplicate: (num, shown) => t('learning', 'Item {num}: Correct answer "{shown}" names the same answer twice — list each correct answer once (a 0/1 list per answer is not supported)', { num, shown }, undefined, RAW),
	markerTooMany: (num, shown) => t('learning', 'Item {num}: Correct answer "{shown}" names more answers than the question has', { num, shown }, undefined, RAW),
	tooManyAnswers: (num, max) => t('learning', 'Item {num}: More than {max} answers — question skipped', { num, max }, undefined, RAW),
	correctCutOff: (num, max) => t('learning', 'Item {num}: A correct answer comes after answer {max}, but only the first {max} answers are imported — question skipped', { num, max }, undefined, RAW),
	answerTruncated: (num) => t('learning', 'Item {num}: Answer text truncated to 2000 chars', { num }, undefined, RAW),
	scenarioTooLong: (num, max) => t('learning', 'Item {num}: Scenario longer than {max} characters — question skipped', { num, max }, undefined, RAW),
	textTruncated: (num) => t('learning', 'Item {num}: Question text truncated to 5000 chars', { num }, undefined, RAW),
	openNeedsAnswer: (num) => t('learning', 'Item {num}: Open question needs at least 1 answer', { num }, undefined, RAW),
	needTwoAnswers: (num) => t('learning', 'Item {num}: Need at least 2 answers', { num }, undefined, RAW),
	truncatedAnswers: (num) => t('learning', 'Item {num}: Truncated to 8 answers', { num }, undefined, RAW),
	defaultedFirst: (num) => t('learning', 'Item {num}: No correct answer marked — defaulted to first answer', { num }, undefined, RAW),
	pbqMissingText: (num) => t('learning', 'PBQ #{num}: missing question_text', { num }, undefined, RAW),
	pbqInvalidSubtype: (num, subtype) => t('learning', 'PBQ #{num}: invalid subtype \'{subtype}\'', { num, subtype }, undefined, RAW),
	pbqConfigNotObject: (num) => t('learning', 'PBQ #{num}: pbq_config must be an object', { num }, undefined, RAW),
	// CSV (ImportController::importCsv)
	noData: () => t('learning', 'No data to import'),
	csvEmptyText: (line) => t('learning', 'Line {line}: Empty question text', { line }, undefined, RAW),
	csvTextTooLong: (line) => t('learning', 'Line {line}: Question text too long (max 5000)', { line }, undefined, RAW),
	csvEmptyModelAnswer: (line) => t('learning', 'Line {line}: Empty model answer for open question', { line }, undefined, RAW),
	csvNotEnoughFields: (line) => t('learning', 'Line {line}: Not enough fields (need: question, answers, correct number)', { line }, undefined, RAW),
	csvNeedTwoAnswers: (line) => t('learning', 'Line {line}: Need at least 2 answers', { line }, undefined, RAW),
	csvMaxAnswers: (line) => t('learning', 'Line {line}: Maximum 8 answers', { line }, undefined, RAW),
	csvNoCorrect: (line) => t('learning', 'Line {line}: Could not determine correct answer — use a number (1-N) to indicate which answer is correct', { line }, undefined, RAW),
	csvAnswerTruncated: (line) => t('learning', 'Line {line}: Answer text truncated to 2000 chars', { line }, undefined, RAW),
}

// ---------------------------------------------------------------------------------------------
// PHP value semantics
// ---------------------------------------------------------------------------------------------

const hasOwn = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key)

/** PHP is_array() on a json_decode(…, true) value: JSON arrays and objects. */
const isArr = (v) => v !== null && typeof v === 'object'

/** PHP array_is_list() on a decoded value: an array, or an object keyed "0".."n-1". */
function isList(v) {
	if (Array.isArray(v)) {
		return true
	}
	if (!isArr(v)) {
		return false
	}
	return Object.keys(v).every((key, i) => key === String(i))
}

const valuesOf = (v) => (Array.isArray(v) ? v : Object.values(v))
const entriesOf = (v) => (Array.isArray(v) ? v.map((x, i) => [i, x]) : Object.entries(v))

/** PHP `$a[k1] ?? $a[k2] ?? …`: the first value that is set and not null. */
function pick(obj, ...keys) {
	if (!isArr(obj)) {
		return null
	}
	for (const key of keys) {
		if (hasOwn(obj, key) && obj[key] !== null && obj[key] !== undefined) {
			return obj[key]
		}
	}
	return null
}

const phpTrim = (s) => s.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
const mbLength = (s) => Array.from(s).length
const mbCut = (s, max) => Array.from(s).slice(0, max).join('')
const asciiLower = (s) => s.replace(/[A-Z]/g, (c) => c.toLowerCase())
/** PHP empty() on a string: '' and '0'. */
const phpEmpty = (s) => s === '' || s === '0'
const isScalar = (v) => typeof v === 'string' || (typeof v === 'number' && Number.isFinite(v))

/** PHP 8 is_numeric(): numbers, and numeric strings (leading/trailing whitespace allowed). */
function phpIsNumeric(v) {
	if (typeof v === 'number') {
		return Number.isFinite(v)
	}
	return typeof v === 'string' && /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(v)
}

/** PHP (int) of a numeric value. */
const phpInt = (v) => Math.trunc(Number(typeof v === 'string' ? v.trim() : v))

/** PoolJsonFormat::scalarText(): strings and numbers become trimmed text, anything else ''. */
function scalarText(v) {
	return isScalar(v) ? phpTrim(String(v)) : ''
}

/** filter_var($v, FILTER_VALIDATE_BOOLEAN). */
function phpBool(v) {
	if (typeof v === 'boolean') {
		return v
	}
	if (typeof v === 'number') {
		return v === 1
	}
	if (typeof v === 'string') {
		return ['1', 'true', 'on', 'yes'].includes(phpTrim(v).toLowerCase())
	}
	return false
}

// ---------------------------------------------------------------------------------------------
// PoolJsonFormat (shared by both server importers)
// ---------------------------------------------------------------------------------------------

/** PoolJsonFormat::ALIASES: field → accepted keys on a question item, canonical key first. */
const ALIASES = {
	handbook_key: ['handbook_key', 'handbookKey', 'buch_key'],
	handbook_title: ['handbook_title', 'handbookTitle', 'buch_titel'],
	exam_key: ['exam_key', 'examKey', 'pruefung_key'],
	chapter_key: ['chapter_key', 'chapterKey', 'kapitel_key'],
	chapter_title: ['chapter_title', 'chapterTitle', 'kapitel_titel'],
	chapter_order: ['chapter_order', 'chapterOrder', 'kapitel_nummer'],
	difficulty: ['difficulty', 'schwierigkeit'],
}

/** PoolJsonFormat::field(): the first accepted key that holds a non-empty scalar. */
function field(item, name) {
	if (!isArr(item)) {
		return null
	}
	for (const key of ALIASES[name] ?? [name]) {
		const value = hasOwn(item, key) ? item[key] : null
		if (isScalar(value) && scalarText(value) !== '') {
			return value
		}
	}
	return null
}

function fieldText(item, name) {
	const value = field(item, name)
	return value === null ? null : scalarText(value)
}

function fieldInt(item, name) {
	const value = field(item, name)
	return phpIsNumeric(value) ? phpInt(value) : null
}

/** PoolJsonFormat::difficulty(): cut to the column length. */
function difficultyOf(item) {
	const text = fieldText(item, 'difficulty')
	return text === null ? null : mbCut(text, DIFFICULTY_MAX_LENGTH)
}

/** PoolJsonFormat::CHAPTER_ORDER_MIN / CHAPTER_ORDER_MAX. */
const CHAPTER_ORDER_MIN = 1
const CHAPTER_ORDER_MAX = 9999

/** PoolJsonFormat::chapterOrder(): valid only from 1 to 9999, a value outside is dropped (silently). */
function chapterOrderOf(item) {
	const order = fieldInt(item, 'chapter_order')
	return order === null || order < CHAPTER_ORDER_MIN || order > CHAPTER_ORDER_MAX ? null : order
}

/** PoolJsonFormat::defaultQuestionType(). */
function defaultQuestionType(meta) {
	const type = scalarText(pick(meta, 'default_question_type')).toLowerCase()
	if (type === '') {
		return null
	}
	return type === 'true_false' ? 'single' : type
}

/**
 * PoolJsonFormat::applyPoolDefaults(): fill a question's missing metadata from its pool and from
 * the file's `_meta`, in the order question > pool > _meta. A value the question carries itself
 * (under any accepted key) is never overwritten.
 */
function applyPoolDefaults(question, pool, meta, index) {
	const defaults = {
		handbook_key: fieldText(pool, 'handbook_key') ?? fieldText(meta, 'handbook_key'),
		handbook_title: fieldText(pool, 'handbook_title') ?? fieldText(meta, 'handbook_title'),
		chapter_key: fieldText(pool, 'chapter_key'),
		chapter_title: fieldText(pool, 'chapter_title'),
		chapter_order: chapterOrderOf(pool),
		difficulty: scalarText(pick(meta, 'default_difficulty')) || null,
		exam_key: null,
	}
	const prefix = scalarText(pick(pool, 'exam_key_prefix'))
	if (prefix !== '') {
		const number = scalarText(pick(question, 'original_number'))
		defaults.exam_key = `${prefix}-q${number !== '' ? number : String(index + 1)}`
	}
	const out = { ...question }
	for (const [name, value] of Object.entries(defaults)) {
		if (value !== null && field(out, name) === null) {
			out[name] = value
		}
	}
	return out
}

// ---------------------------------------------------------------------------------------------
// The JSON reader (ImportController helpers, same names)
// ---------------------------------------------------------------------------------------------

/** typeFrom(): type > question_type > typ, lower-cased; true_false reads as single. */
function typeFrom(item) {
	const type = scalarText(pick(item, 'type', 'question_type', 'typ')).toLowerCase()
	if (type === '') {
		return null
	}
	return type === 'true_false' ? 'single' : type
}

/** scenarioFrom(): scenario > szenario > context > info, strings only. */
function scenarioFrom(item) {
	const raw = pick(item, 'scenario', 'szenario', 'context', 'info')
	if (typeof raw !== 'string' || phpTrim(raw) === '') {
		return null
	}
	return phpTrim(raw)
}

function scenarioTooLong(item) {
	const scenario = scenarioFrom(item)
	return scenario !== null && mbLength(scenario) > SCENARIO_MAX_LENGTH
}

/**
 * metadataFrom() + applyQuestionMetadata(): the metadata the server stores for a question, with
 * the column limits applied.
 */
function storedMetadata(item) {
	const limited = (value, max) => (value === null ? null : mbCut(value, max))
	return {
		handbook_key: limited(fieldText(item, 'handbook_key'), 64),
		handbook_title: limited(fieldText(item, 'handbook_title'), 255),
		exam_key: limited(fieldText(item, 'exam_key'), 64),
		chapter_key: limited(fieldText(item, 'chapter_key'), 64),
		chapter_title: limited(fieldText(item, 'chapter_title'), 255),
		chapter_order: chapterOrderOf(item),
	}
}

/** correctMarkerFrom(): correct > correct_answer > answer > correct_index (0-based), correct_number (1-based). */
function correctMarkerFrom(item) {
	for (const key of ['correct', 'correct_answer', 'answer', 'correct_index']) {
		const v = pick(item, key)
		if (v !== null && typeof v !== 'boolean') {
			return [v, false]
		}
	}
	const n = pick(item, 'correct_number')
	if (n !== null && typeof n !== 'boolean') {
		return [n, true]
	}
	return [null, false]
}

/** resolveOneMarker(): answer text → option key → letter → whole number. */
function resolveOneMarker(value, oneBased, positions, labels, byText) {
	if (typeof value === 'number') {
		if (!Number.isInteger(value)) {
			return null
		}
		const label = labels.get(String(value))
		if (label !== undefined) {
			return label
		}
		return positions.get(oneBased ? value - 1 : value) ?? null
	}
	if (typeof value !== 'string' || phpTrim(value) === '') {
		return null
	}
	const byTextIndex = byText.get(phpTrim(value).toLowerCase())
	if (byTextIndex !== undefined) {
		return byTextIndex
	}
	const upper = phpTrim(value).toUpperCase()
	if (labels.has(upper)) {
		return labels.get(upper)
	}
	const letter = /^\(?([A-Z])[).:]?$/.exec(upper)
	if (letter) {
		return positions.get(letter[1].charCodeAt(0) - 65) ?? null
	}
	if (/^[0-9]+$/.test(phpTrim(value))) {
		const n = parseInt(phpTrim(value), 10)
		return positions.get(oneBased ? n - 1 : n) ?? null
	}
	return null
}

/**
 * resolveCorrectMarker(): every value must match, an array names several correct answers.
 * Returns the indices, or the reason it cannot be used: 'unmatched', 'too_many' (more entries
 * than answers) or 'duplicate' (two values name the same answer, e.g. a 0/1 mask).
 */
function resolveCorrectMarker(marker, oneBased, answerCount, positions, labels, byText) {
	const values = isArr(marker) ? valuesOf(marker) : [marker]
	if (values.length === 0) {
		return 'unmatched'
	}
	if (values.length > answerCount) {
		return 'too_many'
	}
	const result = []
	for (const value of values) {
		const index = resolveOneMarker(value, oneBased, positions, labels, byText)
		if (index === null) {
			return 'unmatched'
		}
		if (result.includes(index)) {
			return 'duplicate'
		}
		result.push(index)
	}
	return result
}

/** questionTextFrom(): MC/open text > frage > question > question_text; a PBQ reads question_text first. */
function questionTextFrom(item, pbq = false) {
	const keys = pbq ? ['question_text', 'text', 'frage', 'question'] : ['text', 'frage', 'question', 'question_text']
	return scalarText(pick(item, ...keys))
}

/** rawAnswersFrom(): answers > antworten > options > choices, as given. */
const rawAnswersFrom = (item) => pick(item, 'answers', 'antworten', 'options', 'choices')

/** answerFlag(): is_correct > korrekt > correct > richtig. */
const answerFlag = (a) => phpBool(pick(a, 'is_correct', 'korrekt', 'correct', 'richtig') ?? false)

/**
 * marksSeveralCorrect(): more than one flagged (non-blank) answer object, or a marker list with
 * more than one distinct value. `_meta.default_question_type` — pbq included — does not apply.
 */
function marksSeveralCorrect(item) {
	const raw = rawAnswersFrom(item)
	const flagged = isArr(raw) ? valuesOf(raw).filter((a) => isArr(a) && scalarText(pick(a, 'text')) !== '' && answerFlag(a)).length : 0
	if (flagged > 1) {
		return true
	}
	const [marker] = correctMarkerFrom(item)
	return isArr(marker) && new Set(valuesOf(marker).map(scalarText)).size > 1
}

/** normalizeJsonItem(): { item } or { error }. */
function normalizeJsonItem(raw, num, defaultType) {
	const text = questionTextFrom(raw)
	if (text === '') {
		return { error: importMessages.missingText(num) }
	}
	let type = typeFrom(raw)

	const rawAnswers = rawAnswersFrom(raw) ?? []
	if (!isArr(rawAnswers)) {
		return { error: importMessages.answersNotList(num) }
	}
	const rawEntries = entriesOf(rawAnswers)
	if (rawEntries.length > MAX_RAW_ANSWERS) {
		return { error: importMessages.tooManyAnswers(num, MAX_RAW_ANSWERS) }
	}

	const keyed = !isList(rawAnswers)
	const answers = []
	const positions = new Map() // position in the file's list → index in answers
	const labels = new Map() // upper-cased option key → index in answers
	const byText = new Map() // lower-cased answer text → index of its first occurrence
	let flagged = false
	let position = 0
	for (const [key, a] of rawEntries) {
		const pos = position++
		let aText
		let isCorrect
		if (isArr(a)) {
			aText = scalarText(pick(a, 'text'))
			isCorrect = answerFlag(a)
		} else {
			aText = scalarText(a)
			isCorrect = false
		}
		if (aText === '') {
			continue
		}
		positions.set(pos, answers.length)
		if (keyed && phpTrim(String(key)) !== '') {
			labels.set(phpTrim(String(key)).toUpperCase(), answers.length)
		}
		const lower = aText.toLowerCase()
		if (!byText.has(lower)) {
			byText.set(lower, answers.length)
		}
		flagged = flagged || isCorrect
		answers.push({ text: aText, isCorrect })
	}

	let [marker, oneBased] = correctMarkerFrom(raw)

	// _meta.default_question_type: only without an own type, and not when several answers are
	// marked correct (that is a multiple-choice question).
	if (type === null && defaultType !== null && !marksSeveralCorrect(raw)) {
		type = defaultType
	}

	// An unknown type skips the item instead of silently becoming single or multi (GitHub #34).
	if (type !== null && !QUESTION_TYPES.includes(type)) {
		return { error: importMessages.unknownType(num, mbCut(type, 80)) }
	}

	if (type === 'open' && answers.length === 0 && scalarText(marker) !== '') {
		answers.push({ text: scalarText(marker), isCorrect: true })
		marker = null
	}

	if (!flagged && marker !== null && type !== 'open') {
		const correct = resolveCorrectMarker(marker, oneBased, answers.length, positions, labels, byText)
		if (typeof correct === 'string') {
			const shown = mbCut(isArr(marker) ? valuesOf(marker).map(scalarText).join(', ') : scalarText(marker), 80)
			const message = { duplicate: importMessages.markerDuplicate, too_many: importMessages.markerTooMany }[correct] ?? importMessages.markerUnmatched
			return { error: message(num, shown) }
		}
		for (const i of correct) {
			answers[i].isCorrect = true
		}
	}

	return { item: { text, type, answers, difficulty: difficultyOf(raw), scenarioTooLong: scenarioTooLong(raw) } }
}

/** questionListFrom(): { items, pool } or { error }. */
function questionListFrom(data) {
	let pool = {}
	let list = null
	for (const key of ['questions', 'fragen', 'items']) {
		const v = pick(data, key)
		if (v !== null && isArr(v)) {
			list = v
			break
		}
	}
	const pools = pick(data, 'pools')
	if (list === null && pools !== null && isArr(pools)) {
		const all = valuesOf(pools)
		if (all.length === 0) {
			return { error: importMessages.noPools() }
		}
		if (all.length > 1) {
			return { error: importMessages.severalPools(all.length), poolCount: all.length }
		}
		pool = all[0]
		const questions = pick(pool, 'questions')
		if (!isArr(pool) || questions === null || !isArr(questions)) {
			return { error: importMessages.expectedList() }
		}
		list = questions
	}
	if (list === null && !isList(data)) {
		if (['text', 'question', 'frage', 'question_text'].some((key) => pick(data, key) !== null)) {
			list = [data]
		}
	}
	list = list ?? data
	if (!isList(list)) {
		return { error: importMessages.expectedList() }
	}
	const items = valuesOf(list)
	if (items.length === 0) {
		return { error: importMessages.noQuestions() }
	}
	return { items, pool }
}

function newEntry(num) {
	return { num, text: '', kind: 'question', answerCount: 0, correctCount: 0, error: null, warnings: [], question: null }
}

function summarize(items, errors, warnings) {
	return {
		total: items.length,
		importable: items.filter((i) => i.error === null).length,
		items,
		errors,
		warnings,
	}
}

/**
 * What the server will do with this JSON text, without sending it.
 *
 * Each item carries `question`, what the server stores for it (null when it is skipped):
 * `{text, type, answers: [{text, correct}], difficulty, subtype, handbook_key, handbook_title,
 * exam_key, chapter_key, chapter_title, chapter_order}`.
 *
 * @param {string} text the pasted or loaded JSON
 * @return {{error: string, poolCount?: number}|{total: number, importable: number, items: Array<object>, errors: string[], warnings: string[]}}
 *   poolCount: set when the file holds several pools (the Pools tab imports those)
 */
export function analyzeJsonImport(text) {
	const source = String(text ?? '').replace(/^\uFEFF/, '')
	let data
	try {
		data = JSON.parse(source)
	} catch (e) {
		return { error: importMessages.unreadableJson(e && e.message ? e.message : String(e)) }
	}
	if (!isArr(data)) {
		return { error: importMessages.invalidJson() }
	}
	const fileMeta = isArr(pick(data, '_meta')) ? data._meta : {}
	const defaultType = defaultQuestionType(fileMeta)

	const shape = questionListFrom(data)
	if (shape.error) {
		return shape.poolCount ? { error: shape.error, poolCount: shape.poolCount } : { error: shape.error }
	}
	if (shape.items.length > MAX_IMPORT_ITEMS) {
		return { error: importMessages.tooManyItems() }
	}

	const items = []
	const errors = []
	const warnings = []
	shape.items.forEach((original, index) => {
		const num = index + 1
		const entry = newEntry(num)
		items.push(entry)
		const fail = (message) => {
			entry.error = message
			entry.question = null
			errors.push(message)
		}
		const warn = (message) => {
			entry.warnings.push(message)
			warnings.push(message)
		}

		if (!isArr(original)) {
			fail(importMessages.notAnObject(num))
			return
		}
		const raw = applyPoolDefaults(original, shape.pool, fileMeta, index)

		// A pbq default, like any default type, does not apply when several answers are correct.
		const pbqByDefault = defaultType === 'pbq' && !marksSeveralCorrect(raw)
		if ((typeFrom(raw) ?? (pbqByDefault ? 'pbq' : null)) === 'pbq') {
			// importPbqItem()
			entry.kind = 'pbq'
			entry.text = questionTextFrom(raw, true)
			const subtype = scalarText(pick(raw, 'subtype', 'pbq_subtype'))
			if (entry.text === '') {
				fail(importMessages.pbqMissingText(num))
			} else if (scenarioTooLong(raw)) {
				fail(importMessages.scenarioTooLong(num, SCENARIO_MAX_LENGTH))
			} else if (!PBQ_SUBTYPES.includes(subtype)) {
				fail(importMessages.pbqInvalidSubtype(num, subtype))
			} else if (!isArr(pick(raw, 'pbq_config'))) {
				fail(importMessages.pbqConfigNotObject(num))
			} else {
				entry.question = {
					text: entry.text,
					type: 'pbq',
					answers: [],
					difficulty: difficultyOf(raw) ?? 'medium',
					subtype,
					...storedMetadata(raw),
				}
			}
			return
		}

		const normalized = normalizeJsonItem(raw, num, defaultType === 'pbq' ? null : defaultType)
		if (normalized.error) {
			entry.text = questionTextFrom(raw)
			fail(normalized.error)
			return
		}
		const item = normalized.item
		entry.text = item.text
		if (item.scenarioTooLong) {
			fail(importMessages.scenarioTooLong(num, SCENARIO_MAX_LENGTH))
			return
		}
		let questionText = item.text
		if (mbLength(questionText) > MAX_TEXT_LENGTH) {
			questionText = mbCut(questionText, MAX_TEXT_LENGTH)
			warn(importMessages.textTruncated(num))
		}
		// The answers as stored, cut to MAX_ANSWER_LENGTH with one warning per item.
		const stored = (type, answers) => {
			if (answers.some((a) => mbLength(a.text) > MAX_ANSWER_LENGTH)) {
				warn(importMessages.answerTruncated(num))
			}
			return storedQuestion(type, answers)
		}
		const storedQuestion = (type, answers) => ({
			text: questionText,
			type,
			answers: answers.map((a) => ({ text: mbCut(a.text, MAX_ANSWER_LENGTH), correct: a.isCorrect })),
			difficulty: item.difficulty,
			subtype: null,
			...storedMetadata(raw),
		})

		if (item.type === 'open') {
			entry.kind = 'open'
			if (item.answers.length === 0) {
				fail(importMessages.openNeedsAnswer(num))
				return
			}
			entry.answerCount = 1
			entry.correctCount = 1
			entry.question = stored('open', [{ text: item.answers[0].text, isCorrect: true }])
			return
		}

		let answers = item.answers.map((a) => ({ ...a }))
		if (answers.length < 2) {
			fail(importMessages.needTwoAnswers(num))
			return
		}
		if (answers.length > MAX_ANSWERS) {
			// A correct answer in the cut-off part: refused, never a wrongly marked first answer.
			if (answers.slice(MAX_ANSWERS).some((a) => a.isCorrect)) {
				fail(importMessages.correctCutOff(num, MAX_ANSWERS))
				return
			}
			answers = answers.slice(0, MAX_ANSWERS)
			warn(importMessages.truncatedAnswers(num))
		}
		let correct = answers.filter((a) => a.isCorrect).length
		if (correct === 0) {
			answers[0].isCorrect = true
			correct = 1
			warn(importMessages.defaultedFirst(num))
		}
		// An explicit multi stays multi with one correct answer.
		entry.kind = correct > 1 || item.type === 'multi' ? 'multi' : 'single'
		entry.answerCount = answers.length
		entry.correctCount = correct
		entry.question = stored(entry.kind, answers)
	})

	return summarize(items, errors, warnings)
}

// ---------------------------------------------------------------------------------------------
// The CSV reader (ImportController::csvRecords / parseCsv / importCsv)
// ---------------------------------------------------------------------------------------------

/**
 * csvRecords(): fgetcsv($stream, null, $delimiter, '"', '') over the whole text. A quoted field
 * may hold the delimiter, line breaks and doubled quotes; there is no backslash escape. Line
 * breaks are normalized to "\n" first; blank and whitespace-only records are skipped.
 *
 * @param {string} text CSV text
 * @param {string} delimiter ',' or ';'
 * @return {string[][]}
 */
export function csvRecords(text, delimiter, limit = Infinity) {
	const s = String(text).replace(/\r\n|\r/g, '\n')
	const n = s.length
	const records = []
	let i = 0
	while (i < n && records.length < limit) {
		const fields = []
		for (;;) {
			// fgetcsv skips blanks before an opening quote (only then).
			let j = i
			while (j < n && (s[j] === ' ' || s[j] === '\t' || s[j] === '\v' || s[j] === '\f') && s[j] !== delimiter) {
				j++
			}
			let value = ''
			if (j < n && s[j] === '"') {
				i = j + 1
				while (i < n) {
					if (s[i] === '"') {
						if (s[i + 1] === '"') {
							value += '"'
							i += 2
							continue
						}
						i++
						// Text after the closing quote belongs to the field, up to the delimiter.
						while (i < n && s[i] !== delimiter && s[i] !== '\n') {
							value += s[i++]
						}
						break
					}
					value += s[i++]
				}
			} else {
				while (i < n && s[i] !== delimiter && s[i] !== '\n') {
					value += s[i++]
				}
			}
			fields.push(value)
			if (i < n && s[i] === delimiter) {
				i++
				continue
			}
			if (i < n && s[i] === '\n') {
				i++
			}
			break
		}
		if (fields.length === 1 && phpTrim(fields[0]) === '') {
			continue
		}
		records.push(fields)
	}
	return records
}

/** isCsvHeader(): a first record that names the columns (question/answer or Frage/Antwort). */
function isCsvHeader(fields) {
	const line = asciiLower(fields.join(' '))
	return (line.includes('question') && line.includes('answer'))
		|| (line.includes('frage') && line.includes('antwort'))
}

/**
 * parseCsv(): ';' or ',', whichever splits the first data record into more fields (tie: ',').
 * Decided on the first two records; the text is then parsed once, up to CSV_MAX_RECORDS.
 */
export function parseCsv(text) {
	const comma = csvRecords(text, ',', 2)
	const semicolon = csvRecords(text, ';', 2)
	const first = comma.length > 0 && isCsvHeader(comma[0]) ? 1 : 0
	const delimiter = (semicolon[first]?.length ?? 0) > (comma[first]?.length ?? 0) ? ';' : ','
	return csvRecords(text, delimiter, CSV_MAX_RECORDS)
}

/** importCsv(): the correct index and answers of a multiple-choice record, PHP rule for rule. */
function csvChoice(fields) {
	let answerTexts = []
	let correctIndex = null
	let explanation = null
	const lastIdx = fields.length - 1
	const secondLastIdx = lastIdx - 1
	const validNumber = (value, answerCount) => phpIsNumeric(value) && phpInt(value) >= 1 && phpInt(value) <= 8 && phpInt(value) <= answerCount

	if (lastIdx >= 2) {
		const possibleCorrect = phpTrim(fields[lastIdx])
		if (validNumber(possibleCorrect, lastIdx - 1)) {
			correctIndex = phpInt(possibleCorrect) - 1
			answerTexts = fields.slice(1, lastIdx)
		} else if (secondLastIdx >= 2) {
			const possibleCorrect2 = phpTrim(fields[secondLastIdx])
			if (validNumber(possibleCorrect2, secondLastIdx - 1)) {
				correctIndex = phpInt(possibleCorrect2) - 1
				answerTexts = fields.slice(1, secondLastIdx)
				explanation = phpTrim(fields[lastIdx])
				if (phpEmpty(explanation)) {
					explanation = null
				}
			} else {
				answerTexts = fields.slice(1, lastIdx + 1)
				const correctText = asciiLower(phpTrim(possibleCorrect))
				const found = answerTexts.findIndex((at) => asciiLower(phpTrim(at)) === correctText)
				if (found !== -1) {
					correctIndex = found
					answerTexts.pop()
				}
			}
		}
	}
	answerTexts = answerTexts.map(phpTrim).filter((a) => a !== '')
	return { answerTexts, correctIndex, explanation }
}

/**
 * What the server will do with this CSV text, without sending it. Same result shape as
 * analyzeJsonImport(); `num` is the record number the server names in "Line N" (records, not
 * physical lines, counted after the header).
 *
 * @param {string} text the pasted or loaded CSV
 * @return {{error: string}|{total: number, importable: number, items: Array<object>, errors: string[], warnings: string[]}}
 */
export function analyzeCsvImport(text) {
	let records = parseCsv(String(text ?? '').replace(/^\uFEFF/, ''))
	if (records.length === 0) {
		return { error: importMessages.noData() }
	}
	// The header is not a question and does not count toward the limit.
	if (isCsvHeader(records[0])) {
		records = records.slice(1)
	}
	if (records.length > MAX_IMPORT_ITEMS) {
		return { error: importMessages.tooManyItems() }
	}

	const items = []
	const errors = []
	const warnings = []
	records.forEach((fields, index) => {
		const line = index + 1
		const entry = newEntry(line)
		items.push(entry)
		const fail = (message) => {
			entry.error = message
			errors.push(message)
		}
		const warnTruncated = (texts) => {
			if (texts.some((a) => mbLength(a) > MAX_ANSWER_LENGTH)) {
				const message = importMessages.csvAnswerTruncated(line)
				entry.warnings.push(message)
				warnings.push(message)
			}
		}

		const questionText = phpTrim(fields[0] ?? '')
		entry.text = questionText
		if (phpEmpty(questionText)) {
			fail(importMessages.csvEmptyText(line))
			return
		}
		if (mbLength(questionText) > MAX_TEXT_LENGTH) {
			fail(importMessages.csvTextTooLong(line))
			return
		}

		if (fields.length === 3 && phpTrim(fields[2]).toLowerCase() === 'open') {
			entry.kind = 'open'
			const model = phpTrim(fields[1])
			if (model === '') {
				fail(importMessages.csvEmptyModelAnswer(line))
				return
			}
			entry.answerCount = 1
			entry.correctCount = 1
			warnTruncated([model])
			entry.question = { text: questionText, type: 'open', answers: [{ text: mbCut(model, MAX_ANSWER_LENGTH), correct: true }], explanation: null }
			return
		}

		if (fields.length < 4) {
			fail(importMessages.csvNotEnoughFields(line))
			return
		}
		const { answerTexts, correctIndex, explanation } = csvChoice(fields)
		if (answerTexts.length < 2) {
			fail(importMessages.csvNeedTwoAnswers(line))
			return
		}
		if (answerTexts.length > MAX_ANSWERS) {
			fail(importMessages.csvMaxAnswers(line))
			return
		}
		if (correctIndex === null || correctIndex >= answerTexts.length) {
			fail(importMessages.csvNoCorrect(line))
			return
		}
		entry.kind = 'single'
		entry.answerCount = answerTexts.length
		entry.correctCount = 1
		warnTruncated(answerTexts)
		entry.question = {
			text: questionText,
			type: 'single',
			answers: answerTexts.map((a, i) => ({ text: mbCut(a, MAX_ANSWER_LENGTH), correct: i === correctIndex })),
			explanation,
		}
	})

	return summarize(items, errors, warnings)
}
