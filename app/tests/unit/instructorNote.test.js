import { describe, it, expect } from 'vitest'

// Inline for RED phase — will be extracted to src/utils/instructorNoteUtils.js in Task 2
const shouldShowNote = (noteVisible, instructorNote) => !!(noteVisible && instructorNote)

describe('shouldShowNote', () => {
  it('noteVisible=true AND instructor_note present → should show (returns true)', () => {
    expect(shouldShowNote(true, 'This is a note for the instructor.')).toBe(true)
  })

  it('noteVisible=false AND instructor_note present → should NOT show (returns false)', () => {
    expect(shouldShowNote(false, 'This is a note for the instructor.')).toBe(false)
  })

  it('noteVisible=true AND instructor_note is empty string → should NOT show (returns false)', () => {
    expect(shouldShowNote(true, '')).toBe(false)
  })

  it('noteVisible=true AND instructor_note is null → should NOT show (returns false)', () => {
    expect(shouldShowNote(true, null)).toBe(false)
  })

  it('noteVisible=undefined (falsy) → should NOT show (returns false)', () => {
    expect(shouldShowNote(undefined, 'This is a note.')).toBe(false)
  })
})
