import { describe, expect, it } from 'vitest'

import router from '../../src/router/index.js'

describe('router', () => {
	it('backs all named app-shell routes with a route component', () => {
		const routeMap = new Map(router.options.routes.map((route) => [route.name, route]))

		for (const name of ['home', 'dashboard', 'courses', 'course-tab', 'pools', 'tools', 'skill-map', 'settings', 'virtuprof']) {
			expect(routeMap.get(name)?.component).toBeTruthy()
		}
	})

	it('keeps the course-tab deep-link route shape', () => {
		const courseTabRoute = router.options.routes.find((route) => route.name === 'course-tab')

		expect(courseTabRoute?.path).toBe('/courses/:id/:tab?')
		expect(courseTabRoute?.meta?.mainView).toBe('courses')
	})
})
