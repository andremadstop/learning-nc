import { describe, expect, it } from 'vitest'

import { STUDENT_SLIDES, getSlidesForRole } from '../../src/utils/onboarding-slides.js'

describe('onboarding slides', () => {
	it('includes the FSRS slide directly after the Leitner slide for students', () => {
		const ids = STUDENT_SLIDES.map((slide) => slide.id)
		const leitnerIndex = ids.indexOf('leitner')
		const fsrsIndex = ids.indexOf('fsrs')

		expect(leitnerIndex).toBeGreaterThanOrEqual(0)
		expect(fsrsIndex).toBe(leitnerIndex + 1)
	})

	it('exposes the FSRS slide through the student role helper', () => {
		const studentSlides = getSlidesForRole('student')
		const fsrsSlide = studentSlides.find((slide) => slide.id === 'fsrs')

		expect(fsrsSlide).toMatchObject({
			icon: '🧠',
			titleKey: 'slide:student:fsrs:title',
			textKey: 'slide:student:fsrs:text',
		})
	})
})
