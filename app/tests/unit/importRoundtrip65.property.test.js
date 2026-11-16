/**
 * Property-based tests for the pool import readers (GitHub #65), with fast-check.
 *
 * The example tests (importPreview*.test.js) pin chosen cases. These generate thousands: arbitrary
 * text in every field (quotes, delimiters, line breaks, formula prefixes, Unicode), and check
 *  1. csvRecords() reads back any table an independent RFC 4180 writer produced,
 *  2. a pool exported as CSV (DataMobilityService::exportPoolCsv) imports as the same questions,
 *  3. a pool exported as JSON (DataMobilityService::exportPoolJson) imports as the same questions,
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

import { analyzeCsvImport, analyzeJsonImport, csvRecords } from '../../src/utils/importPreview.js'

const SEED = Number(process.env.FC_SEED ?? 65065)
const RUNS = Number(process.env.FC_RUNS ?? 300)
const params = { seed: SEED, numRuns: RUNS }

/** PHP trim(): " \t\n\r\0\x0B" only. */
const phpTrim = (s) => s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')

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
 * KNOWN FINDINGS (GitHub #65, open). The CSV format has no column names, so the reader guesses,
 * and for these exports it guesses wrong. Each is excluded from the property by exactly its
 * condition and pinned by an example test below (it.fails: it turns red once the reader is fixed,
 * then the exclusion and the example go).
 */
/** 1. The first record is taken for a header when it mentions question+answer (or Frage+Antwort). */
const looksLikeHeader = (q) => {
	const line = [q.text, ...q.answers.map((a) => a.text), q.explanation ?? ''].join(' ').replace(/[A-Z]/g, (c) => c.toLowerCase())
	return (line.includes('question') && line.includes('answer')) || (line.includes('frage') && line.includes('antwort'))
}
/** 2. ';' wins when the first record splits into more fields at ';' (fputcsv does not quote a ';'). */
const semicolonWins = (csv) => (csvRecords(csv, ';', 1)[0]?.length ?? 0) > (csvRecords(csv, ',', 1)[0]?.length ?? 0)
/** 3. A numeric explanation is read as the number of the correct answer. */
const numericExplanation = (q) => {
	if (q.explanation === null || !/^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(q.explanation)) {
		return false
	}
	const n = Math.trunc(Number(q.explanation.trim())) // is_numeric() and (int), as csvChoice() checks it
	return n >= 1 && n <= 8 && n <= q.answers.length + 1
}

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
			fc.pre(!looksLikeHeader(questions[0]) && !semicolonWins(csv) && !questions.some(numericExplanation))
			const result = analyzeCsvImport(csv)
			expect(result.error).toBeUndefined()
			expect(result.errors).toEqual([])
			// Line breaks come back as "\n" (the reader normalizes \r\n and \r first, as the server does).
			const nl = (s) => (s === null ? null : s.replace(/\r\n|\r/g, '\n'))
			expect(result.items.map((i) => i.question)).toEqual(questions.map((q) => ({
				text: nl(q.text),
				type: 'single',
				answers: q.answers.map((a, i) => ({ text: nl(a.text), correct: i === q.correct })),
				explanation: nl(q.explanation),
			})))
		}), params)
	})
})

describe('CSV export -> import: known findings (GitHub #65, open)', () => {
	const exported = (q) => analyzeCsvImport(exportCsv([q, { text: 'Second', answers: [{ text: 'x' }, { text: 'y' }], correct: 1, explanation: null }]))
	it.fails('1. a first question that mentions "Frage" and "Antwort" is not dropped as a header', () => {
		const r = exported({ text: 'Welche Antwort passt zur Frage?', answers: [{ text: 'A' }, { text: 'B' }], correct: 0, explanation: null })
		expect(r.items).toHaveLength(2)
	})
	it.fails('2. answers with an unquoted ";" do not switch the reader to the ";" delimiter', () => {
		const r = exported({ text: 'Which command lists /tmp?', answers: [{ text: 'cd;ls;pwd' }, { text: 'id;w;who' }], correct: 0, explanation: null })
		expect(r.items[0].question?.answers.map((a) => a.text)).toEqual(['cd;ls;pwd', 'id;w;who'])
	})
	it.fails('3. a numeric explanation stays the explanation', () => {
		const r = exported({ text: 'How many usable hosts has a /30 network?', answers: [{ text: '2' }, { text: '4' }], correct: 0, explanation: '2' })
		expect(r.items[0].question).toEqual({ text: 'How many usable hosts has a /30 network?', type: 'single', answers: [{ text: '2', correct: true }, { text: '4', correct: false }], explanation: '2' })
	})
})

// --- 3. JSON export -> import ---------------------------------------------------------------

const choiceQuestion = fc.integer({ min: 2, max: 8 }).chain((n) => fc.record({
	type: fc.constantFrom('single', 'multi'),
	text: storedText,
	answers: fc.array(storedText, { minLength: n, maxLength: n }),
	flags: fc.array(fc.boolean(), { minLength: n, maxLength: n }),
	pick: fc.integer({ min: 0, max: n - 1 }),
}).map((q) => {
	// what the app can store: single = exactly one correct answer, multi = at least one
	const flags = q.type === 'single' ? q.flags.map((_, i) => i === q.pick) : q.flags.map((f, i) => f || i === q.pick)
	return { text: q.text, type: q.type, answers: q.answers.map((t, i) => ({ text: t, is_correct: flags[i] })) }
}))
const openQuestion = fc.record({ text: storedText, model: storedText }).map((q) => ({ text: q.text, type: 'open', answers: [{ text: q.model, is_correct: true }] }))

describe('JSON export -> import (property)', () => {
	it('a pool of single, multi and open questions comes back as the same questions', () => {
		fc.assert(fc.property(fc.array(fc.oneof(choiceQuestion, openQuestion), { minLength: 1, maxLength: 5 }), (exported) => {
			const result = analyzeJsonImport(JSON.stringify(exported, null, 4))
			expect(result.error).toBeUndefined()
			expect(result.errors).toEqual([])
			expect(result.items.map((i) => {
				const q = i.question
				return { text: q.text, type: q.type, answers: q.answers }
			})).toEqual(exported.map((q) => ({ text: q.text, type: q.type, answers: q.answers.map((a) => ({ text: a.text, correct: a.is_correct })) })))
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
