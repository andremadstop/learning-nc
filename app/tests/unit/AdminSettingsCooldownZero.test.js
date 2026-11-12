/**
 * Audit 2026-11 (S26): an exam cooldown of 0 minutes ("no cooldown", accepted by the input min="0",
 * SettingsController max(0, …) and TrainingService) must survive load() and save(). `x || 10`
 * turned a stored or entered 0 into 10 on both paths.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, str) => str }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import AdminSettings from '../../src/components/AdminSettings.vue'

function createInstance() {
	const instance = { ...AdminSettings.data(), $emit: vi.fn() }
	for (const [name, fn] of Object.entries(AdminSettings.methods)) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

function mockAdminPayload(adminData) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/settings/tools')) {
			return Promise.resolve({ data: { enabled_tools: [] } })
		}
		return Promise.resolve({ data: adminData })
	})
}

function adminPutBody() {
	const call = axios.put.mock.calls.find(([url]) => url === '/apps/learning/api/settings/admin')
	return call ? call[1] : null
}

describe('AdminSettings exam cooldown of 0 minutes', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
	})

	it('shows a stored cooldown of 0 as 0, not as the default 10', async () => {
		mockAdminPayload({ exam_attempt_cooldown_minutes: 0 })
		const instance = createInstance()
		await instance.load()
		expect(instance.form.examAttemptCooldownMinutes).toBe(0)
	})

	it('still falls back to 10 when the payload has no cooldown', async () => {
		mockAdminPayload({})
		const instance = createInstance()
		await instance.load()
		expect(instance.form.examAttemptCooldownMinutes).toBe(10)
	})

	it('saves an entered 0 as 0', async () => {
		const instance = createInstance()
		instance.form.examAttemptCooldownMinutes = 0
		await instance.save()
		expect(adminPutBody().exam_attempt_cooldown_minutes).toBe(0)
	})

	it('saves an emptied field as the default 10 and clamps to 0..1440', async () => {
		const instance = createInstance()
		instance.form.examAttemptCooldownMinutes = ''
		await instance.save()
		expect(adminPutBody().exam_attempt_cooldown_minutes).toBe(10)

		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
		instance.form.examAttemptCooldownMinutes = 5000
		await instance.save()
		expect(adminPutBody().exam_attempt_cooldown_minutes).toBe(1440)
	})
})
