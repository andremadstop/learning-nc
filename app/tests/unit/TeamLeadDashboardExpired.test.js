/**
 * Audit 2026-11 (H1/H7): the team-lead dashboard classified members against a certificate list the
 * backend had already cut down to the 30-day expiry window. Members with a valid certificate
 * (never expiring, or +365d) were shown as "Fehlend" with a reminder button, and an expired
 * certificate stood under "Ablaufende Zertifikate" as passed. The backend now returns every
 * member's status ('passed' | 'expired' | 'overdue' | 'missing') plus an 'expiring' flag; this test
 * mounts the real template and checks which panel each row lands in.
 */
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => String(vars[k] ?? '')) : text)

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))

import axios from '@nextcloud/axios'
import TeamLeadDashboard from '../../src/components/TeamLeadDashboard.vue'

let app = null
let host = null

async function mountWithRows(rows) {
	axios.get.mockImplementation((url) => {
		if (url.includes('my-team-lead-scopes')) {
			return Promise.resolve({ data: { scopes: [{ course_id: 7, group_id: 'g' }] } })
		}
		return Promise.resolve({ data: { rows } })
	})
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(TeamLeadDashboard)
	app.config.globalProperties.t = globalThis.t
	app.mount(host)
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
		await nextTick()
	}
	return host
}

function panels() {
	const [missingPanel, expiringPanel] = host.querySelectorAll('.teamlead-dashboard__panel')
	const rowsOf = (panel) => [...panel.querySelectorAll('tbody tr')].map((tr) => ({
		name: tr.querySelector('td').textContent.trim(),
		badge: tr.querySelector('.teamlead-dashboard__status-badge')?.textContent.trim() ?? null,
		badgeClass: tr.querySelector('.teamlead-dashboard__status-badge')?.className ?? null,
		hasRemind: !!tr.querySelector('.teamlead-dashboard__remind-btn'),
	}))
	return { missing: rowsOf(missingPanel), expiring: rowsOf(expiringPanel) }
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	vi.clearAllMocks()
})

describe('TeamLeadDashboard expired / valid certificates', () => {
	it('shows an expired member with a reminder and keeps valid certificates out of both lists', async () => {
		await mountWithRows([
			{ display_name: 'Never', member_ref: 'n', status: 'passed', due_date: null, expires_at: null, expiring: false },
			{ display_name: 'Year', member_ref: 'y', status: 'passed', due_date: null, expires_at: 1798761600, expiring: false },
			{ display_name: 'Soon', member_ref: 's', status: 'passed', due_date: null, expires_at: 1767225600, expiring: true },
			{ display_name: 'Gone', member_ref: 'g', status: 'expired', due_date: null, expires_at: 1700000000, expiring: false },
		])
		const { missing, expiring } = panels()

		expect(missing).toEqual([
			{ name: 'Gone', badge: 'Expired', badgeClass: expect.stringContaining('teamlead-dashboard__status-badge--expired'), hasRemind: true },
		])
		expect(expiring.map((r) => r.name)).toEqual(['Soon'])
	})
})
