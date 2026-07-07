import { describe, it, expect } from 'vitest'
import { recommendedFaqCategoryId, contextHelpEntry } from '../../src/utils/virtuprof-context-help.js'

// Charakterisierungs-Tests: fixieren das Verhalten aus VirtuProf.vue. Zero-Behavior-Change.
const vt = (key, params) => (params ? `${key}::${JSON.stringify(params)}` : key)

describe('recommendedFaqCategoryId', () => {
	it('mappt bekannte Bereiche per Substring', () => {
		expect(recommendedFaqCategoryId('course-leitner-active')).toBe('leitner')
		expect(recommendedFaqCategoryId('course-exam-pool-select')).toBe('exam')
		expect(recommendedFaqCategoryId('course-arena-sprint')).toBe('arena')
		expect(recommendedFaqCategoryId('gameshow')).toBe('arena')
		expect(recommendedFaqCategoryId('course-league')).toBe('league')
		expect(recommendedFaqCategoryId('course-leaderboard')).toBe('progress')
		expect(recommendedFaqCategoryId('personal-settings')).toBe('settings')
		expect(recommendedFaqCategoryId('courses')).toBe('gettingStarted')
		expect(recommendedFaqCategoryId('pool-select')).toBe('gettingStarted')
	})

	it('unbekannter/leerer Bereich → "training" (Default)', () => {
		expect(recommendedFaqCategoryId('course-training-active')).toBe('training')
		expect(recommendedFaqCategoryId('')).toBe('training')
		expect(recommendedFaqCategoryId(undefined)).toBe('training')
	})
})

describe('contextHelpEntry', () => {
	it('bekannter Bereich liefert festen Titel', () => {
		expect(contextHelpEntry('courses', '', '', vt).title).toBe('Courses')
		expect(contextHelpEntry('course-arena', '', '', vt).title).toBe('Arena')
	})

	it('interpoliert Kurstitel im pool-select', () => {
		expect(contextHelpEntry('course-training-pool-select', '', 'Netzwerk', vt).title)
			.toBe('Training in {course}::{"course":"Netzwerk"}')
	})

	it('fehlender Kurstitel → Fallback "this course"', () => {
		expect(contextHelpEntry('course-training-pool-select', '', '', vt).title)
			.toBe('Training in {course}::{"course":"this course"}')
	})

	it('interpoliert Pool-Namen im aktiven Modus', () => {
		expect(contextHelpEntry('course-training-active', 'Pool A', '', vt).title)
			.toBe('Training: {pool}::{"pool":"Pool A"}')
	})

	it('undefined area → Default "courses"', () => {
		expect(contextHelpEntry(undefined, '', '', vt).title).toBe('Courses')
	})

	it('unbekannter Bereich → generischer Fallback', () => {
		expect(contextHelpEntry('nonexistent-area', '', '', vt).title).toBe('This area')
	})
})
