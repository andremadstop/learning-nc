/**
 * AdminSettings.vue — AUDIT-08 audit-trail liveness widget.
 *
 * The repo has no @vue/test-utils (not installed); the project convention is to test the component
 * definition directly (data + bound methods, no DOM mount) — see GlobalFeed.test.js / StudentDashboard.test.js.
 * The overdue banner is driven purely by `v-if="auditIsOverdue"`, so exercising load()'s payload→state
 * mapping (does audit_is_overdue reach auditIsOverdue?) is the meaningful teeth on the AUDIT-08 truth:
 * "is_overdue=true → warning shown; false → hidden".
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock @nextcloud/axios
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		put: vi.fn(),
		post: vi.fn(),
	},
}))

// Mock @nextcloud/router
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

// Mock @nextcloud/l10n
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, str) => str,
}))

// Mock @nextcloud/vue components (avoids CSS import chain on component import)
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'

import AdminSettings from '../../src/components/AdminSettings.vue'

/**
 * Creates a minimal component instance to access methods/data without mounting.
 */
function createInstance() {
	const data = typeof AdminSettings.data === 'function' ? AdminSettings.data() : {}
	const instance = {
		...data,
		$emit: vi.fn(),
	}
	if (AdminSettings.methods) {
		for (const [name, fn] of Object.entries(AdminSettings.methods)) {
			instance[name] = fn.bind(instance)
		}
	}
	return instance
}

/**
 * Mocks the two GETs load() fires in Promise.all: the admin payload and the tools payload.
 * url-based so it is order-independent.
 */
function mockAdminPayload(adminData) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/settings/tools')) {
			return Promise.resolve({ data: { enabled_tools: [] } })
		}
		return Promise.resolve({ data: adminData })
	})
}

describe('AdminSettings audit-liveness state defaults', () => {
	it('defaults to a neutral, non-overdue state so the banner is hidden before load()', () => {
		const data = AdminSettings.data()
		expect(data.auditIsOverdue).toBe(false)
		expect(data.auditLastCheckpointAt).toBe(0)
		expect(data.auditEventsSinceCheckpoint).toBe(0)
		expect(data.auditAnchorStatus).toBe('none')
		expect(data.auditAnchorEnabled).toBe(false)
	})
})

describe('AdminSettings audit-liveness overdue banner', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('sets auditIsOverdue=true (drives the .audit-overdue-warning v-if) when the payload is overdue', async () => {
		const nineDaysAgo = Math.floor(Date.now() / 1000) - 9 * 86400
		mockAdminPayload({
			daily_challenge_enabled: 'yes',
			audit_is_overdue: true,
			audit_last_checkpoint_at: nineDaysAgo,
			audit_events_since_checkpoint: 42,
			audit_anchor_enabled: false,
			audit_anchor_status: 'none',
		})
		const instance = createInstance()
		await instance.load()
		expect(instance.auditIsOverdue).toBe(true)
		expect(instance.auditEventsSinceCheckpoint).toBe(42)
		expect(instance.auditLastCheckpointAt).toBe(nineDaysAgo)
	})

	it('keeps auditIsOverdue=false (banner hidden) when the payload is fresh', async () => {
		mockAdminPayload({
			daily_challenge_enabled: 'yes',
			audit_is_overdue: false,
			audit_last_checkpoint_at: Math.floor(Date.now() / 1000) - 3600,
			audit_events_since_checkpoint: 3,
			audit_anchor_enabled: true,
			audit_anchor_status: 'ok',
		})
		const instance = createInstance()
		await instance.load()
		expect(instance.auditIsOverdue).toBe(false)
		expect(instance.auditEventsSinceCheckpoint).toBe(3)
		expect(instance.auditAnchorEnabled).toBe(true)
		expect(instance.auditAnchorStatus).toBe('ok')
	})

	it('falls back to a neutral state (no 500, no banner) when the payload omits all audit_* keys', async () => {
		// Frontend half of the fresh-install truth: getAdmin()'s try/catch already neutralises the
		// server side; here the keys are simply absent and the ?? fallbacks must hold.
		mockAdminPayload({ daily_challenge_enabled: 'yes' })
		const instance = createInstance()
		await instance.load()
		expect(instance.auditIsOverdue).toBe(false)
		expect(instance.auditLastCheckpointAt).toBe(0)
		expect(instance.auditEventsSinceCheckpoint).toBe(0)
		expect(instance.auditAnchorStatus).toBe('none')
		expect(instance.auditAnchorEnabled).toBe(false)
	})
})
