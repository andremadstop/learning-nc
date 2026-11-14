import { describe, expect, it } from 'vitest'
import { teilnehmerTabIds } from '../../src/utils/courseView.js'

// GitHub #36: the class register has its own course switch (mode_config.classbook) and is no
// longer the first thing a learner sees under "Teilnehmer" — without visible profiles it was an
// empty page. CourseDetail (routing) and CourseTabTeilnehmer (sub-navigation) both read this list.
describe('teilnehmerTabIds — class register switch (GitHub #36)', () => {
	it('lands learners on their own progress, the class register comes second', () => {
		expect(teilnehmerTabIds({ mode_config: {} })).toEqual(['my-progress', 'classbook'])
	})

	it('drops the class register for learners when the course switched it off', () => {
		expect(teilnehmerTabIds({ mode_config: { classbook: false } })).toEqual(['my-progress'])
	})

	it('treats a missing switch (course saved before it existed) as on', () => {
		expect(teilnehmerTabIds({ mode_config: null })).toContain('classbook')
		expect(teilnehmerTabIds(null)).toContain('classbook')
	})

	it('leaves the instructor leaves unchanged, whatever the switch says', () => {
		const all = ['members', 'classbook', 'progress', 'heatmap', 'weak-questions', 'class-profile', 'summary']
		expect(teilnehmerTabIds({ mode_config: { classbook: false } }, { actsAsInstructor: true })).toEqual(all)
		expect(teilnehmerTabIds({ mode_config: {} }, { actsAsInstructor: true })).toEqual(all)
	})
})
