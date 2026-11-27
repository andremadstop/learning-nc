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
		// its own module state: the later tests replace @nextcloud/router with doMock
		vi.resetModules()
		vi.doMock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => '/nextcloud/index.php' + url) }))
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

// GitHub #82 (review): the start view says when it was reached through an address the app does not
// know. Every form the app root is opened by (notifications, activity, dashboard widget, calendar
// entries, ?learningView=…) must stay a plain start, or every app open would carry that note.
describe('unknown addresses vs the app root (GitHub #82)', () => {
	async function landing(pathname, generated = (url) => url) {
		vi.resetModules()
		vi.doMock('@nextcloud/router', () => ({ generateUrl: vi.fn(generated) }))
		window.history.replaceState(null, '', pathname)
		try {
			const { default: router } = await import('../../src/router/index.js')
			const { cameFromUnknownPath } = await import('../../src/utils/unknownPath.js')
			await router.replace(router.options.history.location)
			const route = router.currentRoute.value
			return { name: route.name, unknown: cameFromUnknownPath(route) }
		} finally {
			window.history.replaceState(null, '', '/')
		}
	}

	it.each([
		['/apps/learning'],
		['/apps/learning/'],
		['/apps/learning/?learningView=abenteuer'],
		['/apps/learning/?learningCoop=1&learningCoopCode=ABC'],
		['/index.php/apps/learning'],
		['/index.php/apps/learning/'],
	])('%s is the app root, not an unknown address', async (pathname) => {
		expect(await landing(pathname)).toEqual({ name: 'home', unknown: false })
	})

	it('the app root without pretty URLs is not an unknown address either', async () => {
		expect(await landing('/nextcloud/index.php/apps/learning/', (url) => '/nextcloud/index.php' + url))
			.toEqual({ name: 'home', unknown: false })
	})

	it.each([
		['/apps/learning/kurse/5'],
		['/apps/learning/courses/5/summary/extra'],
		['/index.php/apps/learning/gibtsnicht'],
	])('%s lands on the start view as an unknown address', async (pathname) => {
		expect(await landing(pathname)).toEqual({ name: 'home', unknown: true })
	})

	it('known views are not unknown addresses', async () => {
		expect(await landing('/apps/learning/courses/5/summary')).toEqual({ name: 'course-tab', unknown: false })
		expect(await landing('/apps/learning/pools?poolId=7')).toEqual({ name: 'pools', unknown: false })
	})
})
