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
		display_name: 'Erika Mustermann',
		member_ref: 'erika',
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
			makeRow({ member_ref: 'alice', status: 'overdue' }),
			makeRow({ member_ref: 'bob', status: 'missing' }),
			makeRow({ member_ref: 'carol', status: 'passed', expires_at: '2026-08-01' }),
		]
		axios.get.mockResolvedValueOnce({ data: { rows } })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'g1' })]
		instance.selectedScopeIndex = 0
		await instance.fetchReport()

		expect(instance.reportRows).toEqual(rows)
		// Contract with CertificateReportController::groupReport — camelCase query params.
		const [, config] = axios.get.mock.calls[0]
		expect(config.params.groupId).toBe('g1')
		expect(config.params.expiringDays).toBe(30)
		expect(config.params.group_id).toBeUndefined()
	})

	it('overdueOrMissing computed filters to overdue + missing only', async () => {
		const instance = createInstance()
		instance.reportRows = [
			makeRow({ member_ref: 'alice', status: 'overdue' }),
			makeRow({ member_ref: 'bob', status: 'missing' }),
			makeRow({ member_ref: 'carol', status: 'passed', expires_at: '2026-08-01' }),
		]

		expect(instance.overdueOrMissing.length).toBe(2)
		expect(instance.overdueOrMissing.map((r) => r.member_ref)).toEqual(['alice', 'bob'])
	})

	it('upcomingExpirations computed includes only passed rows with expires_at', () => {
		const instance = createInstance()
		instance.reportRows = [
			makeRow({ member_ref: 'alice', status: 'overdue', expires_at: null }),
			makeRow({ member_ref: 'carol', status: 'passed', expires_at: '2026-08-01' }),
			makeRow({ member_ref: 'dave', status: 'passed', expires_at: null }),
		]

		expect(instance.upcomingExpirations.length).toBe(1)
		expect(instance.upcomingExpirations[0].member_ref).toBe('carol')
	})

	it('reportRows carry no email field', async () => {
		const rows = [makeRow({ member_ref: 'alice', status: 'overdue' })]
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

	it('POSTs to the remind URL with the backend contract (groupId + memberRef)', async () => {
		axios.post.mockResolvedValueOnce({ status: 200, data: {} })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		instance.selectedScopeIndex = 0
		const row = makeRow({ member_ref: 'ref-erika-abc', status: 'overdue' })
		await instance.sendReminder(row)

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toContain('courses')
		expect(url).toContain('7')
		expect(url).toContain('remind')
		// Contract with CertificateReportController::remindMember — camelCase param names.
		expect(body.groupId).toBe('awo-group')
		expect(body.memberRef).toBe('ref-erika-abc')
		// The raw-uid-shaped keys the controller does NOT read must be absent.
		expect(body.group_id).toBeUndefined()
		expect(body.target_user_id).toBeUndefined()
	})

	it('sets success state on 200', async () => {
		axios.post.mockResolvedValueOnce({ status: 200, data: {} })

		const instance = createInstance()
		instance.scopes = [makeScope({ course_id: 7, group_id: 'awo-group' })]
		instance.selectedScopeIndex = 0
		const row = makeRow({ member_ref: 'erika', status: 'overdue' })
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
		const row = makeRow({ member_ref: 'hans', status: 'overdue' })
		await instance.sendReminder(row)

		expect(instance.reminderStates['hans']).toBe('error')
		// Generic error — must NOT contain the server's membership detail string
		expect(instance.reminderError).not.toContain('Membership detail')
		expect(instance.reminderError).toBeTruthy()
	})
})
