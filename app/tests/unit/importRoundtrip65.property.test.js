/**
 * Property-based tests for the pool import readers (GitHub #65), with fast-check.
 *
 * The example tests (importPreview*.test.js) pin chosen cases. These generate thousands: arbitrary
 * text in every field (quotes, delimiters, line breaks, formula prefixes, Unicode), and check
 *  1. csvRecords() reads back any table an independent RFC 4180 writer produced,
 *  2. a pool exported as CSV (DataMobilityService::exportPoolCsv) imports as the same questions,
 *  3. a pool exported as JSON (DataMobilityService::exportPoolJson) imports as the same questions,
 *     PBQs and metadata included (difficulty, handbook, exam key, chapter),
 *  4. neither reader throws on any input; both answer with an error or a summary.
 *
 * Scope: this is the JavaScript port of the server's readers (src/utils/importPreview.js, kept
 * rule for rule in step with ImportController and checked against the shared fixtures). It does not
 * run the PHP importer itself.
 *
 * Reproducible: the seed is fixed (FC_SEED overrides it, FC_RUNS the number of runs). A failure
 * prints the seed and the shrunk counterexample.
 */
import { describe, expect, it, vi } from 'vitest'
import fc from 'fast-check'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
}))

import { PBQ_SUBTYPES, analyzeCsvImport, analyzeJsonImport, csvRecords } from '../../src/utils/importPreview.js'

const SEED = Number(process.env.FC_SEED ?? 65065)
const RUNS = Number(process.env.FC_RUNS ?? 300)
const params = { seed: SEED, numRuns: RUNS }

/** PHP trim(): " \t\n\r\0\x0B" only. */
const phpTrim = (s) => s.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')

// --- generators -----------------------------------------------------------------------------

/** Characters that matter to the readers, mixed into arbitrary Unicode text. */
const tricky = fc.constantFrom(',', ';', '"', '\n', '\r', '\r\n', '\t', ' ', '=', '+', '-', '@', "'", '\\', '\u00a0', '\uFEFF', 'ä', 'é', 'ж', 'ي', '😀',
	'1', '2', '3', '9', 'open', 'question', 'answer', 'Frage', 'Antwort', 'true')
const text = fc.array(fc.oneof({ weight: 3, arbitrary: fc.string({ unit: 'grapheme', maxLength: 6 }) }, { weight: 2, arbitrary: tricky }), { maxLength: 12 }).map((p) => p.join(''))
/** A text the app stores: non-empty after trim(), stored trimmed (the importers trim). */
const storedText = text.map(phpTrim).filter((s) => s !== '')

// --- 1. csvRecords against an independent writer ---------------------------------------------

/** RFC 4180 writer, deliberately not the reader's mirror: every field quoted, quotes doubled. */
const writeCsv = (rows, delimiter) => rows.map((r) => r.map((f) => '"' + f.replace(/"/g, '""') + '"').join(delimiter)).join('\n')

describe('csvRecords (property)', () => {
	it('reads back every table an RFC 4180 writer produced', () => {
		fc.assert(fc.property(fc.array(fc.array(text, { minLength: 1, maxLength: 6 }), { maxLength: 8 }), fc.constantFrom(',', ';'), (rows, delimiter) => {
			// The reader normalizes line breaks first (also inside quotes) and skips blank records:
			// both are the server's rules (ImportController::csvRecords), so the model applies them.
			const expected = rows
				.map((r) => r.map((f) => f.replace(/\r\n|\r/g, '\n')))
				.filter((r) => !(r.length === 1 && phpTrim(r[0]) === ''))
			expect(csvRecords(writeCsv(rows, delimiter), delimiter)).toEqual(expected)
		}), params)
	})
})

// --- 2. CSV export -> import ----------------------------------------------------------------

/** fputcsv($h, $fields, ',', '"', ''): quote a field holding the delimiter, a quote, \n, \r, \t or a space. */
const fputcsvField = (f) => (/[,"\n\r\t ]/.test(f) ? '"' + f.replace(/"/g, '""') + '"' : f)
/** CsvFormulaGuard::row(): a field starting with = + - @ gets a leading tab. */
const guard = (f) => (f !== '' && '=+-@'.includes(f[0]) ? '\t' + f : f)
/** DataMobilityService::exportPoolCsv(), one line per question. */
function exportCsv(questions) {
	return questions.map((q) => {
		const fields = [q.text, ...q.answers.map((a) => a.text), String(q.correct + 1)]
		if (q.explanation) {
			fields.push(q.explanation)
		}
		return fields.map(guard).map(fputcsvField).join(',')
	}).join('\n')
}

/*
 * The three findings the property found (GitHub #65) are fixed: a first question that mentions
 * question+answer is read, not dropped as a header; an unquoted ';' in an answer no longer makes
 * the reader switch delimiters; a numeric explanation is no longer read as the number of the
 * correct answer when that reading cannot import. Each is pinned by an example below.
 *
 * AMBIGUITY, WARNED. Without a header the format cannot tell "answers …, 1, number" from
 * "answers …, number, explanation": the export of {answers: [a, b, "1"], correct: 2} and of
 * {answers: [a, b], correct: 1, explanation: "2"} is the same line "Q,a,b,1,2". The reader keeps
 * the reading of every version before 5.18.0 (the last field is the number) whenever it imports,
 * and warns when the other reading would import too. So:
 *  - lastAnswerReadsAsNumber: no explanation, the last answer is a number that could be the
 *    number of the correct answer among the answers before it. Comes back unchanged, with a
 *    warning for its line.
 *  - explanationReadsAsNumber: the explanation is a number that names one of the answers plus
 *    the correct-number field, and those still fit (at most 8). Read the old way (one answer
 *    more, no explanation) with a warning for its line; it is the one case that does not come
 *    back, so the round trip leaves it out.
 *  - every other question comes back unchanged and without that warning.
 * A header that names the correct column removes the ambiguity (example below).
 */
const IS_NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/
/** is_numeric() and (int), then a number from 1 to 8 and at most answerCount, as csvChoice() checks it. */
const namesAnswer = (value, answerCount) => {
	if (!IS_NUMERIC.test(value)) {
		return false
	}
	const n = Math.trunc(Number(value.trim()))
	return n >= 1 && n <= 8 && n <= answerCount
}
const lastAnswerReadsAsNumber = (q) => q.explanation === null && q.answers.length >= 3
	&& namesAnswer(q.answers[q.answers.length - 1].text, q.answers.length - 1)
// the old reading takes the answers plus the correct-number field as answers: there must be at most 8
const explanationReadsAsNumber = (q) => q.explanation !== null && q.answers.length + 1 <= 8
	&& namesAnswer(q.explanation, q.answers.length + 1)

const singleChoice = fc.integer({ min: 2, max: 8 }).chain((n) => fc.record({
	text: storedText,
	answers: fc.array(storedText.map((t) => ({ text: t })), { minLength: n, maxLength: n }),
	correct: fc.integer({ min: 0, max: n - 1 }),
	explanation: fc.option(storedText, { nil: null }),
}))

describe('CSV export -> import (property)', () => {
	it('a pool of single-choice questions comes back as the same questions', () => {
		fc.assert(fc.property(fc.array(singleChoice, { minLength: 1, maxLength: 5 }), (questions) => {
			const csv = exportCsv(questions)
			// Accepted: a leading U+FEFF is read as the file's byte order mark and dropped (both readers).
			fc.pre(!csv.startsWith('\uFEFF'))
			const result = analyzeCsvImport(csv)
			expect(result.error).toBeUndefined()
			expect(result.errors).toEqual([])
			// Both readings import: read the old way, never silently.
			const warned = result.items.map((item, i) => item.warnings.some((w) => w.startsWith(`Line ${i + 1}: Ambiguous numbers`)))
			expect(warned).toEqual(questions.map((q) => lastAnswerReadsAsNumber(q) || explanationReadsAsNumber(q)))
			// Line breaks come back as "\n" (the reader normalizes \r\n and \r first, as the server does).
			const nl = (s) => (s === null ? null : s.replace(/\r\n|\r/g, '\n'))
			const lost = questions.map(explanationReadsAsNumber)
			const kept = (list) => list.filter((_, i) => !lost[i])
			expect(kept(result.items.map((i) => i.question))).toEqual(kept(questions).map((q) => ({
				text: nl(q.text),
				type: 'single',
				answers: q.answers.map((a, i) => ({ text: nl(a.text), correct: i === q.correct })),
				explanation: nl(q.explanation),
			})))
		}), params)
	})
})

describe('CSV export -> import: the three findings of the property (GitHub #65)', () => {
	const second = { text: 'Second', answers: [{ text: 'x' }, { text: 'y' }], correct: 1, explanation: null }
	const exported = (q) => analyzeCsvImport(exportCsv([q, second]))
	it('1. a first question that mentions "Frage" and "Antwort" is not dropped as a header', () => {
		const r = exported({ text: 'Welche Antwort passt zur Frage?', answers: [{ text: 'A' }, { text: 'B' }], correct: 0, explanation: null })
		expect(r.items).toHaveLength(2)
		expect(r.items[0].question).toEqual({ text: 'Welche Antwort passt zur Frage?', type: 'single', answers: [{ text: 'A', correct: true }, { text: 'B', correct: false }], explanation: null })
	})
	it('1. ... also "question" and "answer", also in a semicolon file, also an open question', () => {
		expect(exported({ text: 'Which answer fits the question?', answers: [{ text: 'A' }, { text: 'B' }], correct: 1, explanation: null }).items).toHaveLength(2)
		expect(analyzeCsvImport('Welche Antwort passt zur Frage?;A;B;1\nZweite;x;y;2\n').items.map((i) => i.question.text)).toEqual(['Welche Antwort passt zur Frage?', 'Zweite'])
		expect(analyzeCsvImport('Explain question and answer.,A model answer,open\nSecond,x,y,2\n').items).toHaveLength(2)
		// a header in the open-question form (the manual's `question,model_answer,open`) is still a header
		expect(analyzeCsvImport('question,model_answer,open\nExplain DNS.,Name resolution,open\n').items.map((i) => i.question.text)).toEqual(['Explain DNS.'])
		expect(analyzeCsvImport('Fragetext;Musterantwort;OPEN\nErkläre DNS.;Namensauflösung;open\n').items.map((i) => i.question.text)).toEqual(['Erkläre DNS.'])
		// ... also with column names that are not bare (spaces, plural): a label is short and has no sentence mark
		for (const header of ['Question text,Model answer,open', 'Questions,Answers,open', 'Fragen,Antworten,open']) {
			expect(analyzeCsvImport(header + '\nWas ist DNS?,Namensauflösung,open\n').items.map((i) => i.question.text)).toEqual(['Was ist DNS?'])
		}
		expect(analyzeCsvImport('Describe the question and the answer,A model answer,open\nSecond,x,y,2\n').items).toHaveLength(2)
	})
	it('2. answers with an unquoted ";" do not switch the reader to the ";" delimiter', () => {
		const r = exported({ text: 'Which command lists /tmp?', answers: [{ text: 'cd;ls;pwd' }, { text: 'id;w;who' }], correct: 0, explanation: null })
		expect(r.items[0].question?.answers.map((a) => a.text)).toEqual(['cd;ls;pwd', 'id;w;who'])
		expect(r.importable).toBe(2)
	})
	it('3. a numeric explanation is not read as the number of the correct answer when that reading cannot import', () => {
		// eight answers: the old reading would take nine answers (the number field "1" among them), more than 8
		const q = { text: 'How many usable hosts has a /30 network?', answers: ['2', '4', '6', '8', '14', '30', '62', '126'].map((t) => ({ text: t })), correct: 0, explanation: '2' }
		expect(exportCsv([q])).toBe('"How many usable hosts has a /30 network?",2,4,6,8,14,30,62,126,1,2')
		const r = exported(q)
		expect(r.items[0].question).toEqual({ text: q.text, type: 'single', answers: q.answers.map((a, i) => ({ text: a.text, correct: i === 0 })), explanation: '2' })
		expect(r.warnings).toEqual([])
	})
	it('ambiguity, warned: without a header "Q,a,b,1,2" is read as before 5.18.0 — three answers, number 2 — with a warning', () => {
		const q = { text: 'Q', answers: [{ text: 'a' }, { text: 'b' }, { text: '1' }], correct: 1, explanation: null }
		expect(lastAnswerReadsAsNumber(q)).toBe(true)
		expect(exportCsv([q])).toBe(exportCsv([{ text: 'Q', answers: [{ text: 'a' }, { text: 'b' }], correct: 0, explanation: '2' }]))
		const r = analyzeCsvImport(exportCsv([q]))
		expect(r.items[0].question).toEqual({ text: 'Q', type: 'single', answers: [{ text: 'a', correct: false }, { text: 'b', correct: true }, { text: '1', correct: false }], explanation: null })
		const warning = 'Line 1: Ambiguous numbers — read as correct answer 2 without explanation; if the last number is an explanation, add a header row with a "correct" column'
		expect(r.warnings).toEqual([warning])
		expect(r.items[0].warnings).toEqual([warning])
	})
	it('ambiguity, warned: numeric answers ("Wie viel ist 3-1?,1,3,2,3") are read as before; a short numeric explanation is read as the number', () => {
		const r = analyzeCsvImport('Wie viel ist 3-1?,1,3,2,3\n')
		expect(r.items[0].question.answers).toEqual([{ text: '1', correct: false }, { text: '3', correct: false }, { text: '2', correct: true }])
		expect(r.warnings).toEqual(['Line 1: Ambiguous numbers — read as correct answer 3 without explanation; if the last number is an explanation, add a header row with a "correct" column'])
		const hosts = analyzeCsvImport('How many usable hosts has a /30 network?,2,4,1,2\n')
		expect(hosts.items[0].question).toEqual({ text: 'How many usable hosts has a /30 network?', type: 'single', answers: [{ text: '2', correct: false }, { text: '4', correct: true }, { text: '1', correct: false }], explanation: null })
		expect(hosts.warnings).toHaveLength(1)
	})
	it('no ambiguity warning when only one reading imports or a header decides', () => {
		for (const [csv, warns] of [
			['Q,a,b,1,2\n', 1],
			['Q,a,b,1,4\n', 0], // there is no 4th answer among a, b, 1: one reading
			['Q,a,b,2,Weil.\n', 0],
			['Q,a,b,2\n', 0],
			['question,answer1,answer2,answer3,correct\nQ,a,b,1,2\n', 0],
			['Frage,Antwort 1,Antwort 2,Richtig,Erklärung\nQ,a,b,1,2\n', 0],
		]) {
			expect(analyzeCsvImport(csv).warnings, csv).toHaveLength(warns)
		}
	})
	it('a header that names the correct column decides: the number is read from that column only', () => {
		const withHeader = (header) => analyzeCsvImport(header + '\nQ,a,b,1,2\n').items[0].question
		expect(withHeader('question,answer1,answer2,answer3,correct')).toEqual({ text: 'Q', type: 'single', answers: [{ text: 'a', correct: false }, { text: 'b', correct: true }, { text: '1', correct: false }], explanation: null })
		expect(withHeader('Frage,Antwort 1,Antwort 2,Richtig,Erklärung')).toEqual({ text: 'Q', type: 'single', answers: [{ text: 'a', correct: true }, { text: 'b', correct: false }], explanation: '2' })
		// a record narrower or wider than the header is read without it
		expect(analyzeCsvImport('question,answer1,answer2,answer3,correct\nQ,a,b,c,d,2\n').items[0].question.answers.map((a) => a.correct)).toEqual([false, true, false, false])
	})
	it('only a whole column name names the correct column: "Lösungsweg" or "Solution notes" is an explanation', () => {
		const read = (name) => analyzeCsvImport(`Frage,Antwort 1,Antwort 2,${name},Erklärung\nQ,a,b,1,2\n`)
		const byColumn = { text: 'Q', type: 'single', answers: [{ text: 'a', correct: true }, { text: 'b', correct: false }], explanation: '2' }
		for (const name of ['correct', 'Correct', ' correct ', 'correct_answer', 'Correct answer', 'CORRECT-ANSWER', 'correct_number', 'right answer',
			'Richtig', 'richtige Antwort', 'Korrekt', 'korrekte_antwort', 'solution', 'Lösung', 'LÖSUNG', 'Loesung']) {
			expect(read(name).items[0].question, name).toEqual(byColumn)
			expect(read(name).warnings, name).toEqual([])
		}
		// not a correct column: the row is read without the header (as before 5.18.0), with the ambiguity warning
		for (const name of ['Lösungsweg', 'Solution notes', 'solutions', 'incorrect', 'correctness', 'Korrektur', 'Richtigkeit', 'correct answer explanation']) {
			expect(read(name).items[0].question.answers, name).toEqual([{ text: 'a', correct: false }, { text: 'b', correct: true }, { text: '1', correct: false }])
			expect(read(name).warnings, name).toHaveLength(1)
		}
	})
})

// --- 3. JSON export -> import ---------------------------------------------------------------

/** A stored text that fits a column of `max` characters (mb_strlen). */
const columnText = (max) => storedText.filter((s) => Array.from(s).length <= max)
/**
 * The optional fields exportPoolJson writes, as the database holds them (within the column
 * limits, chapter_order 1..9999), each absent or set: absent keys are left out, as the exporter
 * does for null and ''. The reader's output exposes all of them; explanation and scenario are
 * written too, so a reader that trips over them fails, but the JavaScript port does not carry them
 * (PoolExportRoundTripTest checks them, and pbq_config, on the PHP importers).
 */
const metadata = fc.record({
	scenario: storedText,
	explanation: storedText,
	difficulty: columnText(20),
	handbook_key: columnText(64),
	handbook_title: columnText(255),
	exam_key: columnText(64),
	chapter_key: columnText(64),
	chapter_title: columnText(255),
	chapter_order: fc.integer({ min: 1, max: 9999 }),
}, { requiredKeys: [] })
const METADATA_KEYS = ['handbook_key', 'handbook_title', 'exam_key', 'chapter_key', 'chapter_title', 'chapter_order']

const choiceQuestion = fc.integer({ min: 2, max: 8 }).chain((n) => fc.record({
	type: fc.constantFrom('single', 'multi'),
	text: storedText,
	answers: fc.array(storedText, { minLength: n, maxLength: n }),
	flags: fc.array(fc.boolean(), { minLength: n, maxLength: n }),
	pick: fc.integer({ min: 0, max: n - 1 }),
	meta: metadata,
}).map((q) => {
	// what the app can store: single = exactly one correct answer, multi = at least one
	const flags = q.type === 'single' ? q.flags.map((_, i) => i === q.pick) : q.flags.map((f, i) => f || i === q.pick)
	return { text: q.text, type: q.type, ...q.meta, answers: q.answers.map((t, i) => ({ text: t, is_correct: flags[i] })) }
}))
const openQuestion = fc.record({ text: storedText, model: storedText, meta: metadata })
	.map((q) => ({ text: q.text, type: 'open', ...q.meta, answers: [{ text: q.model, is_correct: true }] }))
const pbqQuestion = fc.record({
	text: storedText,
	subtype: fc.constantFrom(...PBQ_SUBTYPES),
	pbq_config: fc.dictionary(fc.string({ maxLength: 8 }), fc.jsonValue({ maxDepth: 2 }), { maxKeys: 4 }),
	meta: metadata,
}).map((q) => ({ text: q.text, type: 'pbq', subtype: q.subtype, pbq_config: q.pbq_config, ...q.meta, answers: [] }))

/** What the reader reports for an exported question: everything it stores of it. */
const expectedRead = (q) => ({
	text: q.text,
	type: q.type,
	answers: q.answers.map((a) => ({ text: a.text, correct: a.is_correct })),
	// a PBQ without a difficulty is stored as 'medium' (importPbqItem)
	difficulty: q.difficulty ?? (q.type === 'pbq' ? 'medium' : null),
	subtype: q.type === 'pbq' ? q.subtype : null,
	...Object.fromEntries(METADATA_KEYS.map((k) => [k, q[k] ?? null])),
})

describe('JSON export -> import (property)', () => {
	it('a pool of single, multi, open and PBQ questions comes back as the same questions, with their metadata', () => {
		fc.assert(fc.property(fc.array(fc.oneof(choiceQuestion, openQuestion, pbqQuestion), { minLength: 1, maxLength: 5 }), (exported) => {
			const result = analyzeJsonImport(JSON.stringify(exported, null, 4))
			expect(result.error).toBeUndefined()
			expect(result.errors).toEqual([])
			expect(result.warnings).toEqual([])
			expect(result.items.map((i) => i.question)).toEqual(exported.map(expectedRead))
		}), params)
	})
})

// --- 4. totality ----------------------------------------------------------------------------

/** JSON shaped like an import file: the readers' own keys, nested, with arbitrary values. */
const importKey = fc.constantFrom('_meta', 'pools', 'questions', 'fragen', 'items', 'text', 'question', 'frage', 'question_text', 'type', 'answers', 'antworten', 'options', 'choices', 'is_correct', 'correct', 'correct_answer', 'korrekt', 'subtype', 'pbq_config', 'scenario', 'difficulty', 'hint', 'feedback_per_option', 'option_index', 'default_question_type', 'chapter_order', '0', '1', 'A')
const importish = fc.letrec((tie) => ({
	value: fc.oneof({ depthSize: 'small' }, fc.jsonValue({ maxDepth: 1 }), tie('object'), tie('array')),
	object: fc.dictionary(importKey, tie('value'), { maxKeys: 6 }),
	array: fc.array(tie('value'), { maxLength: 5 }),
})).value

const answersWithErrorOrSummary = (r) => {
	expect(r).toBeTypeOf('object')
	if (r.error === undefined) {
		expect(Array.isArray(r.items)).toBe(true)
		expect(r.importable).toBeLessThanOrEqual(r.total)
	} else {
		expect(r.error).toBeTypeOf('string')
	}
}

describe('import readers never throw (property)', () => {
	it('JSON reader: arbitrary text', () => {
		fc.assert(fc.property(fc.string({ unit: 'grapheme', maxLength: 200 }), (s) => answersWithErrorOrSummary(analyzeJsonImport(s))), params)
	})
	it('JSON reader: arbitrary JSON shaped like an import file', () => {
		fc.assert(fc.property(importish, (v) => answersWithErrorOrSummary(analyzeJsonImport(JSON.stringify(v)))), { ...params, numRuns: RUNS * 3 })
	})
	it('CSV reader: arbitrary text and tables', () => {
		fc.assert(fc.property(fc.oneof(fc.string({ unit: 'grapheme', maxLength: 200 }), fc.array(fc.array(text, { maxLength: 6 }), { maxLength: 6 }).map((rows) => rows.map((r) => r.join(',')).join('\n'))), (s) => answersWithErrorOrSummary(analyzeCsvImport(s))), params)
	})
})
