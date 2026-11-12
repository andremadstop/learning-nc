/**
 * GitHub #34: "I can't import question via JSON. Nothing works right now."
 *
 * The dialog previewed with its own narrow reader: it threw silently on the documented
 * {_meta, pools:[…]} format and on a single object, and showed "(4 answers)" for string-array
 * answers the server then refused ("Need at least 2 answers"). The preview now ports the
 * server's reader. These cases mirror app/tests/Unit/Controller/ImportControllerJsonTest.php
 * one for one, so both readers are held to the same examples.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const { calls } = vi.hoisted(() => ({ calls: [] }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars, count, options) => {
		calls.push({ text, options })
		return text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	},
}))

import { analyzeCsvImport, analyzeJsonImport, csvRecords, parseCsv } from '../../src/utils/importPreview.js'

const run = (data) => analyzeJsonImport(typeof data === 'string' ? data : JSON.stringify(data))

function ok(result, n) {
	expect(result.error).toBeUndefined()
	expect(result.importable).toBe(n)
	return result
}

describe('importPreview: the documented format and an export', () => {
	it('the first json example of docs/import-pool-workflow.md previews as importable', () => {
		const here = dirname(fileURLToPath(import.meta.url))
		const doc = readFileSync(resolve(here, '../../../docs/import-pool-workflow.md'), 'utf-8')
		const block = /```json\n([\s\S]*?)```/.exec(doc)
		expect(block).not.toBeNull()
		const data = JSON.parse(block[1])
		expect(data.pools).toHaveLength(1)
		const r = ok(run(block[1]), data.pools[0].questions.length)
		expect(r.errors).toEqual([])
	})

	it('a pool export (bare list with is_correct flags, multi, open and scenario) previews unchanged', () => {
		const r = ok(run([
			{ text: 'HTTPS port?', question_type: 'single', explanation: '443/tcp.', answers: [{ text: '443', is_correct: true }, { text: '80', is_correct: false }] },
			{ text: 'Private ranges?', question_type: 'multi', scenario: 'An office network.\nTwo sites.', answers: [{ text: '10.0.0.0/8', is_correct: true }, { text: '8.8.8.0/24', is_correct: false }, { text: '192.168.0.0/16', is_correct: true }] },
			{ text: 'DNS?', question_type: 'open', answers: [{ text: 'Domain Name System', is_correct: true }] },
		]), 3)
		expect(r.items.map((i) => i.kind)).toEqual(['single', 'multi', 'open'])
		expect(r.items[1].correctCount).toBe(2)
	})
})

describe('importPreview: answers as strings / options, correct answer on the question', () => {
	it('options with a 0-based answer index', () => {
		const r = ok(run([{ question: 'Capital of France?', options: ['Berlin', 'Paris', 'Rome'], answer: 1 }]), 1)
		expect(r.items[0]).toMatchObject({ text: 'Capital of France?', answerCount: 3, correctCount: 1, kind: 'single' })
		expect(r.warnings).toEqual([])
	})

	it('correct_index 0 means the first answer', () => {
		const r = ok(run([{ text: 'First?', choices: ['Yes', 'No'], correct_index: 0 }]), 1)
		expect(r.warnings).toEqual([])
	})

	it('correct_number counts from 1; 3 of 3 resolves, 4 does not', () => {
		ok(run([{ text: 'Second?', answers: ['a', 'b', 'c'], correct_number: 3 }]), 1)
		expect(run([{ text: 'Second?', answers: ['a', 'b', 'c'], correct_number: 4 }]).importable).toBe(0)
	})

	it('string answers with a letter', () => {
		ok(run([{ text: 'Which is a prime?', answers: ['4', '6', '7', '9'], correct: 'C' }]), 1)
	})

	it('the correct answer given as its text (trimmed, case-insensitive)', () => {
		ok(run([{ text: 'Largest planet?', options: ['Mars', 'Jupiter', 'Venus'], correct_answer: 'jupiter ' }]), 1)
	})

	it('answer text wins over letter and number', () => {
		const r = ok(run([
			{ text: 'Pick the letter', options: ['B', 'A', 'C'], answer: 'A' },
			{ text: '1 + 1 = ?', options: ['1', '3', '2'], answer: '2' },
		]), 2)
		expect(r.warnings).toEqual([])
	})

	it('an array of markers makes multiple choice', () => {
		const r = ok(run([{ text: 'Even numbers?', options: ['1', 'two', '3', 'four'], correct: ['B', 3] }]), 1)
		expect(r.items[0]).toMatchObject({ kind: 'multi', correctCount: 2 })
	})

	it('keyed options resolve by their label', () => {
		const r = ok(run([{ text: 'OSI layer 3?', options: { A: 'Transport', B: 'Network' }, answer: 'B' }]), 1)
		expect(r.items[0].answerCount).toBe(2)
	})

	it('numeric keyed options resolve by their key, not by position', () => {
		// "answer": 3 is key 3 ("c"); read as a 0-based position it would match nothing.
		const r = ok(run('[{"text":"Numbered","options":{"1":"a","2":"b","3":"c"},"answer":3},'
			+ '{"text":"Numbered, as string","options":{"1":"a","2":"b"},"correct":"2"}]'), 2)
		expect(r.errors).toEqual([])
	})

	it('existing per-answer flags still work, including FILTER_VALIDATE_BOOLEAN strings', () => {
		ok(run([{ text: 'Q?', answers: [{ text: 'x', correct: true }, { text: 'y', korrekt: false }] }]), 1)
		const r = ok(run([{ text: 'Q?', answers: [{ text: 'x', is_correct: 'yes' }, { text: 'y', is_correct: 'on' }, { text: 'z', is_correct: 'no' }] }]), 1)
		expect(r.items[0].correctCount).toBe(2)
	})

	it('a per-answer flag wins over the question-level marker (which is then not read)', () => {
		const r = ok(run([{ text: 'Q?', answers: [{ text: 'x', is_correct: true }, { text: 'y' }], correct: 'F' }]), 1)
		expect(r.errors).toEqual([])
	})

	it('an unresolvable marker skips the item instead of marking the first answer', () => {
		const r = run([
			{ text: 'Out of range (0-based)', options: ['a', 'b', 'c'], answer: 3 },
			{ text: 'Unknown letter', options: ['a', 'b'], correct: 'F' },
			{ text: 'No such text', options: ['a', 'b'], correct_answer: 'zzz' },
			{ text: 'Fine', options: ['a', 'b'], answer: 0 },
		])
		ok(r, 1)
		expect(r.errors).toHaveLength(3)
		expect(r.errors[0]).toContain('Item 1')
		expect(r.errors[0]).toContain('"3"')
		expect(r.errors[0]).toContain('0 = first')
		expect(r.errors[1]).toContain('Item 2')
		expect(r.errors[2]).toContain('Item 3')
		expect(r.items[0].answerCount).toBe(0)
	})

	it('a boolean on the question names no answer: the first answer is defaulted with a warning', () => {
		const r = ok(run([{ text: 'Q?', options: ['a', 'b'], correct: true }]), 1)
		expect(r.warnings).toEqual(['Item 1: No correct answer marked — defaulted to first answer'])
	})

	it('an open question takes its model answer from "answer"', () => {
		const r = ok(run([{ text: 'What is H2O?', question_type: 'open', answer: 'Water' }]), 1)
		expect(r.items[0]).toMatchObject({ kind: 'open', answerCount: 1 })
	})
})

describe('importPreview: numeric and non-scalar values', () => {
	it('numeric fields do not break the import', () => {
		const r = ok(run([
			{ text: 1984, explanation: 42, difficulty: 3, answers: [{ text: 1, is_correct: true }, { text: 2.5 }] },
			{ text: 'Next one is still read', answers: [1, 2], answer: 0 },
		]), 2)
		expect(r.items[0].text).toBe('1984')
	})

	it('non-scalar fields are reported per item', () => {
		const r = run([
			{ text: ['not', 'text'], answers: ['a', 'b'] },
			{ text: 'Bad answers', answers: 'a,b' },
			{ text: 'Good', explanation: ['x'], difficulty: ['y'], handbook_key: ['z'], answers: ['a', 'b'], answer: 1 },
			'just a string',
			{ type: 'pbq', text: 'PBQ', subtype: ['dropdown'], pbq_config: [] },
		])
		ok(r, 1)
		expect(r.errors).toHaveLength(4)
		expect(r.errors[0]).toContain('Item 1')
		expect(r.errors[1]).toBe('Item 2: Answers must be a list')
		expect(r.errors[2]).toBe('Item 4: Not a valid object')
		expect(r.errors[3]).toBe('PBQ #5: invalid subtype \'\'')
	})

	it('an empty question text is not replaced by a later key (PHP ?? skips only null)', () => {
		const r = run([{ text: '', question: 'x', answers: ['a', 'b'] }])
		expect(r.errors).toEqual(['Item 1: Missing question text'])
	})
})

describe('importPreview: question_type', () => {
	it('question_type is read like type, case-insensitively', () => {
		const r = ok(run([
			{ text: 'Explain TCP.', question_type: 'open', answers: [{ text: 'Reliable transport', is_correct: true }] },
			{ text: 'Open, upper case', question_type: 'OPEN', answers: ['Model'] },
		]), 2)
		expect(r.items.map((i) => i.kind)).toEqual(['open', 'open'])
	})

	it('question_type pbq takes the PBQ path', () => {
		const r = ok(run([{ question_type: 'pbq', question_text: 'Match the ports', subtype: 'dropdown', pbq_config: { items: [] }, difficulty: 2 }]), 1)
		expect(r.items[0]).toMatchObject({ kind: 'pbq', text: 'Match the ports' })
	})

	it('a non-open item with one answer is refused, as the server does', () => {
		const r = run([{ text: 'Only one', answers: ['a'] }])
		expect(r.importable).toBe(0)
		expect(r.errors).toEqual(['Item 1: Need at least 2 answers'])
	})
})

describe('importPreview: shapes', () => {
	const q = { text: 'Q?', answers: [{ text: 'a', is_correct: true }, { text: 'b' }] }

	it('several pools are refused with a clear message', () => {
		const r = run({ pools: [{ name: 'One', questions: [q] }, { name: 'Two', questions: [q] }] })
		expect(r.error).toContain('2 pools')
		expect(r.error).toContain('occ learning:import-pool-json')
	})

	it('one pool in the pools wrapper is unwrapped', () => {
		ok(run({ _meta: { source: 'x' }, pools: [{ name: 'One', questions: [q, q] }] }), 2)
	})

	it('an empty pools list is refused', () => {
		expect(run({ pools: [] }).error).toBe('The file contains no pools — expected a list of questions')
	})

	it('a single question object is imported', () => {
		ok(run({ question: 'Only one?', answers: [{ text: 'yes', is_correct: true }, { text: 'no' }] }), 1)
	})

	it('an object that is no question list is an error', () => {
		expect(run({ name: 'My pool', stuff: [1, 2] }).error).toBe('Expected a list of questions')
		expect(run({ questions: { a: q } }).error).toBe('Expected a list of questions')
	})

	it('an empty list, and {} (an empty array to PHP), say "no questions"', () => {
		expect(run('[]').error).toBe('No questions found in the JSON')
		expect(run('{}').error).toBe('No questions found in the JSON')
	})

	it('item numbers count up inside a wrapper', () => {
		const r = run({ questions: [{ text: 'ok', answers: ['a', 'b'], answer: 0 }, { answers: ['a', 'b'] }] })
		ok(r, 1)
		expect(r.errors).toEqual(['Item 2: Missing question text'])
		expect(r.total).toBe(2)
	})

	it('broken JSON is an error the dialog can show, a scalar is invalid JSON, a BOM is accepted', () => {
		expect(run('[{"text": "x",]').error).toMatch(/^The JSON cannot be read: /)
		expect(run('5').error).toBe('Invalid JSON — check for missing brackets, commas, or quotes')
		ok(analyzeJsonImport('\uFEFF' + JSON.stringify([q])), 1)
	})

	it('more than 500 items is refused', () => {
		expect(run(Array.from({ length: 501 }, () => q)).error).toBe('Maximum 500 items per import')
	})
})

describe('importPreview: its own messages go through t()', () => {
	// The server translates its messages itself now (IL10N); the preview builds its own with the
	// same wording, as translation keys with named placeholders.
	it('a preview message is a translation key with the values as parameters', () => {
		calls.length = 0
		const r = run([{ text: 'Bad marker', options: ['a', 'b'], answer: '<b>' }])
		expect(r.errors).toEqual(['Item 1: Correct answer "<b>" matches no answer — numbers count from 0 (0 = first answer, or use correct_number to count from 1), letters from A, or give the exact answer text'])
		const call = calls.find((c) => c.text.startsWith('Item {num}: Correct answer'))
		expect(call).toBeDefined()
		// Vue escapes the text already; translate() must not escape it a second time.
		expect(call.options).toEqual({ escape: false })
	})

	it('the module no longer rewrites server messages', async () => {
		const mod = await import('../../src/utils/importPreview.js')
		expect(mod.localizeImportMessage).toBeUndefined()
	})
})

describe('importPreview: the metadata defaults of the pool file', () => {
	it('pool chapter, exam_key prefix and _meta difficulty reach the stored question', () => {
		const r = ok(run({
			_meta: { default_difficulty: 'hard', handbook_title: 'CCNA' },
			pools: [{ chapter_key: '1.1', chapter_title: 'Basics', chapter_order: '2', exam_key_prefix: 'ccna-1.1', questions: [
				{ text: 'Q', answers: ['a', 'b'], answer: 0 },
				{ text: 'Own', kapitel_key: 'x', schwierigkeit: 'easy', answers: ['a', 'b'], answer: 1 },
			] }],
		}), 2)
		expect(r.items[0].question).toMatchObject({ difficulty: 'hard', chapter_key: '1.1', chapter_title: 'Basics', chapter_order: 2, exam_key: 'ccna-1.1-q1', handbook_title: 'CCNA' })
		expect(r.items[1].question).toMatchObject({ difficulty: 'easy', chapter_key: 'x', exam_key: 'ccna-1.1-q2' })
	})

	it('default_question_type pbq routes items without a type to the PBQ path; ranking is a subtype', () => {
		const r = ok(run({ _meta: { default_question_type: 'pbq' }, questions: [
			{ question_text: 'Order the layers', pbq_subtype: 'ranking', pbq_config: { items: ['a', 'b'] } },
		] }), 1)
		expect(r.items[0]).toMatchObject({ kind: 'pbq', question: { type: 'pbq', subtype: 'ranking', difficulty: 'medium' } })
	})

	it('a long question text is stored cut to 5000 characters, long answers to 2000', () => {
		const r = ok(run([{ text: 'x'.repeat(5001), answers: [{ text: 'y'.repeat(2001), is_correct: true }, 'b'] }]), 1)
		expect(r.items[0].question.text).toHaveLength(5000)
		expect(r.items[0].question.answers[0].text).toHaveLength(2000)
		expect(r.warnings).toEqual(['Item 1: Question text truncated to 5000 chars', 'Item 1: Answer text truncated to 2000 chars'])
	})

	it('a truncated model answer of an open question is reported', () => {
		const r = ok(run([{ text: 'Open', type: 'open', answer: 'y'.repeat(2001) }]), 1)
		expect(r.warnings).toEqual(['Item 1: Answer text truncated to 2000 chars'])
	})

	it('marker errors name their reason, and nothing is defaulted to the first answer', () => {
		const ten = ['o1', 'o2', 'o3', 'o4', 'o5', 'o6', 'o7', 'o8', 'o9', 'o10']
		const r = run([
			{ text: 'Past eight', options: ten, correct_index: 9 },
			{ text: 'Mask', options: ['a', 'b', 'c', 'd'], correct: [1, 0, 1, 0] },
			{ text: 'Too many', options: ['a', 'b'], correct: [0, 1, 0] },
			{ text: 'Huge', options: Array.from({ length: 51 }, (_, i) => `x${i + 1}`), answer: 0 },
		])
		expect(r.importable).toBe(0)
		expect(r.errors).toEqual([
			'Item 1: A correct answer comes after answer 8, but only the first 8 answers are imported — question skipped',
			'Item 2: Correct answer "1, 0, 1, 0" names the same answer twice — list each correct answer once (a 0/1 list per answer is not supported)',
			'Item 3: Correct answer "0, 1, 0" names more answers than the question has',
			'Item 4: More than 50 answers — question skipped',
		])
		expect(r.warnings).toEqual([])
	})
})

describe('importPreview: CSV, the same records the server reads (ImportControllerCsvTest.php)', () => {
	it('quoted fields keep their line breaks and commas', () => {
		const csv = 'question,answer1,answer2,answer3,correct,explanation\n'
			+ '"Look at this output:\nPING 10.0.0.1\n100% loss\nWhat is wrong?","Cable","DNS, probably","Nothing",1,"Line one\r\nline two"\n'
			+ 'Plain one,a,b,2\n'
		const r = analyzeCsvImport(csv)
		expect(r.importable).toBe(2)
		expect(r.errors).toEqual([])
		expect(r.items[0].question).toEqual({
			text: 'Look at this output:\nPING 10.0.0.1\n100% loss\nWhat is wrong?',
			type: 'single',
			answers: [{ text: 'Cable', correct: true }, { text: 'DNS, probably', correct: false }, { text: 'Nothing', correct: false }],
			explanation: 'Line one\nline two',
		})
		expect(r.items[0].answerCount).toBe(3)
		expect(r.items[1].question.answers).toEqual([{ text: 'a', correct: false }, { text: 'b', correct: true }])
	})

	it('semicolon, BOM, doubled quotes and a backslash at the end of a field', () => {
		const csv = '\uFEFFFrage;Antwort1;Antwort2;Korrekt\r\n'
			+ '"Er sagte ""Hallo"";\nwas nun?";"C:\\";"D:\\";2\r\n'
			+ '\r\n'
			+ 'Zweite; ja ; nein ;1\r\n'
		const r = analyzeCsvImport(csv)
		expect(r.importable).toBe(2)
		expect(r.items[0].question.text).toBe('Er sagte "Hallo";\nwas nun?')
		expect(r.items[0].question.answers).toEqual([{ text: 'C:\\', correct: false }, { text: 'D:\\', correct: true }])
		expect(r.items[1].question.answers).toEqual([{ text: 'ja', correct: true }, { text: 'nein', correct: false }])
	})

	it('an open question with a multi-line model answer; "Line" counts records', () => {
		const r = analyzeCsvImport('"Explain TCP.","Reliable,\nordered",open\nBroken line without enough fields,a\n')
		expect(r.importable).toBe(1)
		expect(r.items[0]).toMatchObject({ kind: 'open', question: { type: 'open', answers: [{ text: 'Reliable,\nordered', correct: true }] } })
		expect(r.errors).toEqual(['Line 2: Not enough fields (need: question, answers, correct number)'])
	})

	it('the 500 limit counts records, not physical lines', () => {
		expect(analyzeCsvImport('"Q\nwith\nfour lines",a,b,1\n'.repeat(200)).importable).toBe(200)
		expect(analyzeCsvImport('Q,a,b,1\n'.repeat(501)).error).toBe('Maximum 500 items per import')
	})

	it('the correct answer as its text, an explanation after the number, and the server\'s refusals', () => {
		const r = analyzeCsvImport([
			'Capital?,Berlin,Paris,paris',
			'With explanation,a,b,c,3,Because c.',
			'Out of range,a,b,5',
			'Nine answers,1,2,3,4,5,6,7,8,9,1',
			',a,b,1',
		].join('\n'))
		expect(r.items.map((i) => i.error === null)).toEqual([true, true, false, false, false])
		expect(r.items[0].question.answers).toEqual([{ text: 'Berlin', correct: false }, { text: 'Paris', correct: true }])
		expect(r.items[1].question).toMatchObject({ explanation: 'Because c.', answers: [{ text: 'a', correct: false }, { text: 'b', correct: false }, { text: 'c', correct: true }] })
		expect(r.errors).toEqual([
			'Line 3: Could not determine correct answer — use a number (1-N) to indicate which answer is correct',
			'Line 4: Maximum 8 answers',
			'Line 5: Empty question text',
		])
	})

	it('the header does not count toward the 500 limit', () => {
		expect(analyzeCsvImport('question,answer1,answer2,correct\n' + 'Q,a,b,1\n'.repeat(500)).importable).toBe(500)
		expect(analyzeCsvImport('question,answer1,answer2,correct\n' + 'Q,a,b,1\n'.repeat(501)).error).toBe('Maximum 500 items per import')
	})

	it('csvRecords stops reading at its limit; parseCsv reads at most 502 records', () => {
		expect(csvRecords('Q,a,b,1\n'.repeat(900), ',', 502)).toHaveLength(502)
		expect(parseCsv('Q;a;b;1\n'.repeat(900))).toHaveLength(502)
		expect(parseCsv('Q;a;b;1\n'.repeat(900))[0]).toEqual(['Q', 'a', 'b', '1'])
	})

	it('a truncated answer is reported as a warning', () => {
		const long = 'y'.repeat(2001)
		const r = analyzeCsvImport(`Q,${long},b,1\nOpen?,${long},open\n`)
		expect(r.importable).toBe(2)
		expect(r.items[0].question.answers[0].text).toHaveLength(2000)
		expect(r.warnings).toEqual(['Line 1: Answer text truncated to 2000 chars', 'Line 2: Answer text truncated to 2000 chars'])
	})

	it('empty input is "No data to import"; a tie in field counts means comma', () => {
		expect(analyzeCsvImport('\n\n  \n').error).toBe('No data to import')
		expect(parseCsv('a;b,c;d,e\n')[0]).toEqual(['a;b', 'c;d', 'e'])
		expect(parseCsv('a;b;c,d\n')[0]).toEqual(['a', 'b', 'c,d'])
	})
})
