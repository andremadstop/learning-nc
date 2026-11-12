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
