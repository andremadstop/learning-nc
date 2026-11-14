import { describe, it, expect } from 'vitest'
import fs from 'fs'
import path from 'path'
import { shouldShowNote } from '../../src/utils/instructorNoteUtils.js'

describe('shouldShowNote', () => {
	it('noteVisible=true AND instructor_note present → shows the note', () => {
		expect(shouldShowNote(true, 'This is a note for the instructor.')).toBe(true)
	})

	it('noteVisible=false AND instructor_note present → hides the note', () => {
		expect(shouldShowNote(false, 'This is a note for the instructor.')).toBe(false)
	})

	it('noteVisible=true AND instructor_note is empty string → hides the note', () => {
		expect(shouldShowNote(true, '')).toBe(false)
	})

	it('noteVisible=true AND instructor_note is null → hides the note', () => {
		expect(shouldShowNote(true, null)).toBe(false)
	})

	it('noteVisible=undefined (falsy) → hides the note', () => {
		expect(shouldShowNote(undefined, 'This is a note.')).toBe(false)
	})

	it('note_visible as stored integer 0/1 is respected', () => {
		expect(shouldShowNote(0, 'n')).toBe(false)
		expect(shouldShowNote(1, 'n')).toBe(true)
	})
})

describe('ExamMode uses shouldShowNote for the instructor note card', () => {
	const src = fs.readFileSync(path.resolve(__dirname, '../../src/components/ExamMode.vue'), 'utf8')

	it('every NcNoteCard that renders res.instructorNote is guarded by shouldShowNote', () => {
		const cards = [...src.matchAll(/<NcNoteCard([^>]*)>(?:(?!<\/NcNoteCard>)[\s\S])*res\.instructorNote/g)]
		expect(cards.length).toBeGreaterThan(0)
		for (const card of cards) {
			expect(card[1]).toContain('v-if="shouldShowNote(res.noteVisible, res.instructorNote)"')
		}
	})

	it('registers the helper as a method so the template can call it', () => {
		expect(src).toMatch(/import \{ shouldShowNote \} from '\.\.\/utils\/instructorNoteUtils\.js'/)
		expect(src).toMatch(/methods: \{\s*shouldShowNote,/)
	})
})
