/**
 * Audit 2026-11 (S30): the group report returns due_date and expires_at as Unix seconds (int)
 * (AssignmentService (int) cast, Certificate expiresAt integer). The dashboard printed them raw,
 * so a team lead saw "1767225600" under "Fällig am" / "Gültig bis". The older fixtures in
 * TeamLeadDashboard.test.js used date strings, which hid it. This test mounts the real template.
 */
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => String(vars[k] ?? '')) : text)

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))

import axios from '@nextcloud/axios'
import TeamLeadDashboard from '../../src/components/TeamLeadDashboard.vue'

const DUE = 1767225600 // 2026-01-01T00:00:00Z
const EXPIRES = 1798761600 // 2027-01-01T00:00:00Z

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

function dateCells() {
	return [...host.querySelectorAll('.teamlead-dashboard__cell--date')].map((el) => el.textContent.trim())
}

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
	vi.clearAllMocks()
})

describe('TeamLeadDashboard date cells', () => {
	it('renders due_date and expires_at Unix seconds as dates, not as raw numbers', async () => {
		await mountWithRows([
			{ display_name: 'A', member_ref: 'a', status: 'overdue', due_date: DUE, expires_at: null },
			{ display_name: 'B', member_ref: 'b', status: 'passed', due_date: null, expires_at: EXPIRES },
		])
		const cells = dateCells()
		expect(cells).toHaveLength(2)
		expect(cells).toEqual(['2026-01-01', '2027-01-01'])
		for (const text of cells) {
			expect(text).not.toMatch(/^\d{9,}$/)
		}
	})

	it('shows a dash for a missing due date', async () => {
		await mountWithRows([
			{ display_name: 'C', member_ref: 'c', status: 'missing', due_date: null, expires_at: null },
		])
		expect(dateCells()).toEqual(['—'])
	})
})
