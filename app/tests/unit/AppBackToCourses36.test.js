/**
 * Persona sweep (GitHub #36): "← Back to courses" showed the course list but left the address bar
 * on /courses/<id>/<tab>, so a reload or a bookmark reopened the course. The button must go
 * through the router, whose watcher clears the selected course.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const source = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')

describe('"Back to courses" updates the route (GitHub #36)', () => {
	it('CourseDetail\'s back event is wired to backToCourseList, not to a bare state change', () => {
		const block = source.slice(source.indexOf('<CourseDetail'), source.indexOf('/>', source.indexOf('<CourseDetail')))
		expect(block).toMatch(/@back="backToCourseList"/)
		expect(block).not.toMatch(/@back="selectedCourse = null"/)
	})

	it('backToCourseList pushes the course list route', async () => {
		vi.stubGlobal('t', (_a, s) => s)
		const methodSrc = source.match(/backToCourseList\(\) \{([\s\S]*?)\n {4}\},/)
		expect(methodSrc, 'method exists').not.toBeNull()
		const self = { pushAppRoute: vi.fn() }
		// eslint-disable-next-line no-new-func
		new Function(methodSrc[1]).call(self)
		expect(self.pushAppRoute).toHaveBeenCalledWith({ name: 'courses' })
	})
})
