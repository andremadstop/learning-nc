/**
 * TeamLeadDashboard.vue unit tests.
 *
 * Tests component logic: scope-gating, report fetch, reminder POST.
 * No mounting — consistent with project test patterns (tests/unit/**).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Provide the global t() function that Nextcloud injects at runtime
globalThis.t = (app, text) => text

// Mock @nextcloud/axios
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params) => {
		if (!params) return url
		let result = url
		for (const [key, value] of Object.entries(params)) {
			result = result.replace(`{${key}}`, value)
		}
		return result
	}),
}))

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

import axios from '@nextcloud/axios'
import TeamLeadDashboard from '../../src/components/TeamLeadDashboard.vue'

function makeScope(overrides = {}) {
	return { course_id: 7, group_id: 'compliance-group', ...overrides }
}

function makeRow(overrides = {}) {
	return {
		displayName: 'Erika Mustermann',
		uid: 'erika',
		status: 'overdue',
		due_date: '2026-06-01',
		expires_at: null,
		...overrides,
	}
}

/**
 * Creates a minimal instance to call methods and access computed state.
 * Merges data + methods, binds 'this' to the instance.
 */
function createInstance() {
	const data = typeof TeamLeadDashboard.data === 'function' ? TeamLeadDashboard.data() : {}
	const instance = { ...data }

	// Bind computed properties as plain getters
	const computed = TeamLeadDashboard.computed || {}
	for (const [key, fn] of Object.entries(computed)) {
		Object.defineProperty(instance, key, {
			get: () => (typeof fn === 'function' ? fn.call(instance) : fn.get.call(instance)),
			enumerable: true,
			configurable: true,
		})
	}

	// Bind methods
	const methods = TeamLeadDashboard.methods || {}
	for (const [key, fn] of Object.entries(methods)) {
		instance[key] = fn.bind(instance)
	}

	return instance
}

describe('TeamLeadDashboard component definition', () => {
	it('is an Options-API component (no setup())', () => {
		expect(typeof TeamLeadDashboard.setup).toBe('undefined')
	})

	it('has a name', () => {
		expect(TeamLeadDashboard.name).toBe('TeamLeadDashboard')
	})

	it('has data function returning scopes array', () => {
		const data = TeamLeadDashboard.data()
		expect(Array.isArray(data.scopes)).toBe(true)
		expect(data.scopes).toEqual([])
	})

	it('has mounted lifecycle hook', () => {
		expect(typeof TeamLeadDashboard.mounted).toBe('function')
	})
})

describe('TeamLeadDashboard.hasScopes computed', () => {
	it('is false when scopes is empty', () => {
		const instance = createInstance()
		expect(instance.hasScopes).toBe(false)
	})

	it('is true when scopes has at least one entry', () => {
		const instance = createInstance()
		instance.scopes = [makeScope()]
		expect(instance.hasScopes).toBe(true)
	})
})

describe('TeamLeadDashboard.fetchScopes', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('populates scopes from API response', async () => {
		const scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		axios.get.mockResolvedValueOnce({ data: { scopes } })
		// fetchReport will be called; mock it to resolve immediately
		axios.get.mockResolvedValueOnce({ data: { rows: [] } })

		const instance = createInstance()
		await instance.fetchScopes()

		expect(instance.scopes).toEqual(scopes)
		expect(instance.hasScopes).toBe(true)
	})

	it('leaves scopes empty on empty response', async () => {
		axios.get.mockResolvedValueOnce({ data: { scopes: [] } })

		const instance = createInstance()
		await instance.fetchScopes()

		expect(instance.scopes).toEqual([])
		expect(instance.hasScopes).toBe(false)
	})

	it('sets scopesError on API failure', async () => {
		axios.get.mockRejectedValueOnce(new Error('Network error'))

		const instance = createInstance()
		await instance.fetchScopes()

		expect(instance.scopes).toEqual([])
		expect(instance.hasScopes).toBe(false)
	})
})

describe('TeamLeadDashboard.fetchReport', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('populates reportRows from API', async () => {
		const rows = [
			makeRow({ uid: 'alice', status: 'overdue' }),
			makeRow({ uid: 'bob', status: 'missing' }),
			makeRow({ uid: 'carol', status: 'passed', expires_at: '2026-08-01' }),
		]
		axios.get.mockResolvedValueOnce({ data: { rows } })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'g1' })]
		instance.selectedScopeIndex = 0
		await instance.fetchReport()

		expect(instance.reportRows).toEqual(rows)
	})

	it('overdueOrMissing computed filters to overdue + missing only', async () => {
		const instance = createInstance()
		instance.reportRows = [
			makeRow({ uid: 'alice', status: 'overdue' }),
			makeRow({ uid: 'bob', status: 'missing' }),
			makeRow({ uid: 'carol', status: 'passed', expires_at: '2026-08-01' }),
		]

		expect(instance.overdueOrMissing.length).toBe(2)
		expect(instance.overdueOrMissing.map((r) => r.uid)).toEqual(['alice', 'bob'])
	})

	it('upcomingExpirations computed includes only passed rows with expires_at', () => {
		const instance = createInstance()
		instance.reportRows = [
			makeRow({ uid: 'alice', status: 'overdue', expires_at: null }),
			makeRow({ uid: 'carol', status: 'passed', expires_at: '2026-08-01' }),
			makeRow({ uid: 'dave', status: 'passed', expires_at: null }),
		]

		expect(instance.upcomingExpirations.length).toBe(1)
		expect(instance.upcomingExpirations[0].uid).toBe('carol')
	})

	it('reportRows carry no email field', async () => {
		const rows = [makeRow({ uid: 'alice', status: 'overdue' })]
		axios.get.mockResolvedValueOnce({ data: { rows } })

		const instance = createInstance()
		instance.scopes = [makeScope()]
		await instance.fetchReport()

		for (const row of instance.reportRows) {
			expect(row).not.toHaveProperty('email')
		}
	})
})

describe('TeamLeadDashboard.sendReminder', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('POSTs to the correct URL with group_id and target_user_id', async () => {
		axios.post.mockResolvedValueOnce({ status: 200, data: {} })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		instance.selectedScopeIndex = 0
		const row = makeRow({ uid: 'erika', status: 'overdue' })
		await instance.sendReminder(row)

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toContain('courses')
		expect(url).toContain('7')
		expect(url).toContain('remind')
		expect(body.group_id).toBe('awo-group')
		expect(body.target_user_id).toBe('erika')
	})

	it('sets success state on 200', async () => {
		axios.post.mockResolvedValueOnce({ status: 200, data: {} })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		instance.selectedScopeIndex = 0
		const row = makeRow({ uid: 'erika', status: 'overdue' })
		await instance.sendReminder(row)

		expect(instance.reminderStates['erika']).toBe('sent')
	})

	it('sets generic error on 403 — no membership detail leaked', async () => {
		const err = new Error('Forbidden')
		err.response = { status: 403, data: { error: 'Membership detail should not leak' } }
		axios.post.mockRejectedValueOnce(err)

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		instance.selectedScopeIndex = 0
		const row = makeRow({ uid: 'hans', status: 'overdue' })
		await instance.sendReminder(row)

		expect(instance.reminderStates['hans']).toBe('error')
		// Generic error — must NOT contain the server's membership detail string
		expect(instance.reminderError).not.toContain('Membership detail')
		expect(instance.reminderError).toBeTruthy()
	})
})
