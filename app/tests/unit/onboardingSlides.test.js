import { describe, expect, it } from 'vitest'

import {
	STUDENT_SLIDES,
	INSTRUCTOR_SLIDES_FULL,
	slidesForWizard,
	STUDENT_SLIDES_FULL,
	ADMIN_SLIDES,
	GOAL_TILES,
	INTENSITY_TILES,
	AI_CONSENT_TILES,
	getSlidesForRole,
	getSlidesForRoleFull,
} from '../../src/utils/onboarding-slides.js'

describe('onboarding slides', () => {
	it('includes the FSRS slide directly after the Leitner slide for students (full)', () => {
		const ids = STUDENT_SLIDES_FULL.map((slide) => slide.id)
		const leitnerIndex = ids.indexOf('leitner')
		const fsrsIndex = ids.indexOf('fsrs')

		expect(leitnerIndex).toBeGreaterThanOrEqual(0)
		expect(fsrsIndex).toBe(leitnerIndex + 1)
	})

	it('exposes the FSRS slide through the full student role helper', () => {
		const studentSlides = getSlidesForRoleFull('student')
		const fsrsSlide = studentSlides.find((slide) => slide.id === 'fsrs')

		expect(fsrsSlide).toMatchObject({
			icon: '🧠',
			titleKey: 'slide:student:fsrs:title',
			textKey: 'slide:student:fsrs:text',
		})
	})

	it('trimmed student slides have exactly 3 entries', () => {
		expect(STUDENT_SLIDES).toHaveLength(3)
		expect(getSlidesForRole('student')).toHaveLength(3)
	})

	it('getSlidesForRole returns admin slides for admin role', () => {
		expect(getSlidesForRole('admin')).toBe(ADMIN_SLIDES)
		expect(ADMIN_SLIDES).toHaveLength(3)
	})

	it('getSlidesForRole accepts dozent as instructor alias', () => {
		expect(getSlidesForRole('dozent')).toEqual(getSlidesForRole('instructor'))
	})

	it('exports tile data arrays', () => {
		expect(GOAL_TILES).toHaveLength(3)
		expect(INTENSITY_TILES).toHaveLength(3)
		expect(AI_CONSENT_TILES).toHaveLength(2)
		expect(INTENSITY_TILES[0]).toHaveProperty('hours')
	})
})

/**
 * Codeberg #5: making the wizard the single instructor entry point removed the 9-slide tour that
 * used to follow it (OnboardingIntro, getSlidesForRoleFull). Without compensation an instructor
 * would drop from 12 slides to 3 in a patch release — a feature loss smuggled in as a bugfix.
 */
describe('instructor tour depth after the single-gate change', () => {
	it('gives instructors the full slide set inside the wizard', () => {
		expect(slidesForWizard('instructor')).toBe(INSTRUCTOR_SLIDES_FULL)
	})

	it('leaves the student tour short on purpose', () => {
		expect(slidesForWizard('student')).toBe(STUDENT_SLIDES)
	})
})
