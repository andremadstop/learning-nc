/**
 * Review of GitHub #36: the learners' Abschluss area shows RecentSessionsList. The mode badge
 * printed the stored value ('training', 'exam') in every language, and the date followed the
 * browser locale instead of the Nextcloud one.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { setTestLanguage } from './support/l10nCatalog.js'
import RecentSessionsList from '../../src/components/RecentSessionsList.vue'

let app, host

async function render(sessions) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(RecentSessionsList, { sessions })
	app.mount(host)
	await nextTick()
	return host
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	setTestLanguage('de')
})

describe('RecentSessionsList in the reader\'s language (GitHub #36)', () => {
	it('translates the session mode', async () => {
		setTestLanguage('fr')
		const el = await render([
			{ completed_at: 1700000000, pool_name: 'P', mode: 'exam', score: '9/10' },
			{ completed_at: 1700000000, pool_name: 'P', mode: 'training', score: '5/10' },
		])
		const modes = Array.from(el.querySelectorAll('.session-mode')).map((n) => n.textContent.trim())
		expect(modes).toEqual(['Examen', 'Entraînement'])
	})

	it('shows no badge for an unknown mode instead of the raw value', async () => {
		const el = await render([{ completed_at: 1700000000, pool_name: 'P', mode: 'xyz_internal', score: '1/1' }])
		expect(el.querySelector('.session-mode')).toBeNull()
		expect(el.textContent).not.toContain('xyz_internal')
	})

	it('formats the date in the Nextcloud locale, not the browser\'s', async () => {
		setTestLanguage('ar')
		const el = await render([{ completed_at: 1700000000, pool_name: 'P', mode: 'exam', score: '9/10' }])
		const expected = new Date(1700000000 * 1000).toLocaleDateString('ar', { year: 'numeric', month: 'short', day: 'numeric' })
		expect(el.querySelector('.session-date').textContent.trim()).toBe(expected)
	})
})
