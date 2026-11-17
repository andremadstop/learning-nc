/**
 * Audit 2026-11 (S35): the history base was hardcoded to '/apps/learning/'. On a Nextcloud served
 * under a webroot (/nextcloud/) or without pretty URLs (/index.php/apps/learning/), every path fell
 * through to the catch-all and each push rewrote the address bar to a URL the web server cannot
 * serve. The base has to come from generateUrl(), like every other URL the frontend builds.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => '/nextcloud/index.php' + url),
}))

describe('router history base', () => {
	it('follows the Nextcloud webroot and index.php routing', async () => {
		const { default: router } = await import('../../src/router/index.js')
		expect(router.options.history.base).toBe('/nextcloud/index.php/apps/learning')
	})
})

// GitHub #82: pretty URLs on (generateUrl without index.php), but the page was opened through
// /index.php/apps/learning/… — Nextcloud serves both. The base has to be the form in the address
// bar, or /courses/<id> falls through to the catch-all and lands on the start view.
describe('router history base with pretty URLs, opened through index.php (GitHub #82)', () => {
	it('takes the index.php form when the address has it, the pretty form otherwise', async () => {
		vi.resetModules()
		vi.doMock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => '/nextcloud' + url) }))
		const { historyBase } = await import('../../src/router/index.js')
		expect(historyBase('/nextcloud/index.php/apps/learning/courses/4/summary')).toBe('/nextcloud/index.php/apps/learning/')
		expect(historyBase('/nextcloud/index.php/apps/learning')).toBe('/nextcloud/index.php/apps/learning/')
		expect(historyBase('/nextcloud/apps/learning/courses/4')).toBe('/nextcloud/apps/learning/')
		expect(historyBase('/nextcloud/index.php/apps/files/')).toBe('/nextcloud/apps/learning/')
	})

	it('a deep link opened through index.php keeps its route', async () => {
		vi.resetModules()
		vi.doMock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
		window.history.replaceState(null, '', '/index.php/apps/learning/courses/4/summary')
		try {
			const { default: router } = await import('../../src/router/index.js')
			// what the first navigation (app.use(router)) resolves the address bar to
			const route = router.resolve(router.options.history.location)
			expect(route.name).toBe('course-tab')
			expect(route.params).toEqual({ id: '4', tab: 'summary' })
		} finally {
			window.history.replaceState(null, '', '/')
		}
	})
})
