/**
 * GitHub #108 (review of #109): Gemini and OpenAI-compatible share one key field. A key typed for
 * one provider must never be saved for the other; "key saved" only while the form still points
 * at the provider and endpoint the key belongs to; no Ollama defaults for an OpenAI-compatible
 * endpoint; a refused settings request must not leave the other requests saved.
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
// GitHub #59: AdminSettings opens AccessibleDialog (NcDialog) to confirm deleting a ticket
vi.mock('../../src/components/AccessibleDialog.vue', () => ({ default: { name: 'AccessibleDialog', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div />' } }))

globalThis.t = (app, text) => text

import axios from '@nextcloud/axios'
import AdminSettings from '../../src/components/AdminSettings.vue'

function createInstance() {
	const instance = { ...AdminSettings.data(), $emit: vi.fn() }
	for (const [name, fn] of Object.entries(AdminSettings.methods)) {
		instance[name] = fn.bind(instance)
	}
	for (const [name, fn] of Object.entries(AdminSettings.computed)) {
		Object.defineProperty(instance, name, { get: fn.bind(instance) })
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

describe('AdminSettings OpenAI-compatible provider (#108)', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
	})

	it('drops a key typed for one provider when the provider changes', async () => {
		const instance = createInstance()
		instance.form.aiProvider = 'openai_compatible'
		instance.handleAiProviderChange()
		instance.form.aiOllamaUrl = 'https://api.deepseek.com'
		instance.form.aiOllamaModel = 'deepseek-chat'
		instance.form.geminiApiKey = 'sk-deepseek'
		instance.form.aiProvider = 'gemini'
		instance.handleAiProviderChange()
		await instance.save()
		expect(adminPutBody().ai_provider).toBe('gemini')
		expect(adminPutBody()).not.toHaveProperty('gemini_api_key')
	})

	it('starts the OpenAI-compatible fields empty instead of the Ollama defaults, and restores them for Ollama', () => {
		const instance = createInstance()
		instance.form.aiProvider = 'openai_compatible'
		instance.handleAiProviderChange()
		expect(instance.form.aiOllamaUrl).toBe('')
		expect(instance.form.aiOllamaModel).toBe('')
		instance.form.aiProvider = 'ollama'
		instance.handleAiProviderChange()
		expect(instance.form.aiOllamaUrl).toBe('http://localhost:11434')
		expect(instance.form.aiOllamaModel).toBe('llama3')
	})

	it('sends an empty OpenAI-compatible endpoint as empty, never as localhost', async () => {
		const instance = createInstance()
		instance.form.aiProvider = 'openai_compatible'
		instance.handleAiProviderChange()
		await instance.save()
		expect(adminPutBody().ai_ollama_url).toBe('')
		expect(adminPutBody().ai_ollama_model).toBe('')
	})

	it('shows "key saved" only for the provider and endpoint the key belongs to', async () => {
		mockAdminPayload({ ai_provider: 'openai_compatible', ai_ollama_url: 'https://api.deepseek.com', ai_ollama_model: 'deepseek-chat', gemini_api_key_set: true })
		const instance = createInstance()
		await instance.load()
		expect(instance.aiKeySaved).toBe(true)
		instance.form.aiOllamaUrl = 'https://other.example'
		expect(instance.aiKeySaved).toBe(false)
		instance.form.aiOllamaUrl = 'https://api.deepseek.com'
		instance.form.aiProvider = 'gemini'
		instance.handleAiProviderChange()
		expect(instance.aiKeySaved).toBe(false)
	})

	it('sends the internal-network switch only for OpenAI-compatible', async () => {
		const instance = createInstance()
		instance.form.aiAllowInternalNetwork = true
		instance.form.aiProvider = 'ollama'
		await instance.save()
		expect(adminPutBody().ai_allow_internal_network).toBe('no')
		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
		instance.form.aiProvider = 'openai_compatible'
		instance.form.aiOllamaUrl = 'http://llm.lan:8080/v1'
		instance.form.aiOllamaModel = 'm'
		await instance.save()
		expect(adminPutBody().ai_allow_internal_network).toBe('yes')
	})

	it('does not save tools or instructors when the server refuses the AI connection', async () => {
		axios.put.mockImplementation((url) => url === '/apps/learning/api/settings/admin'
			? Promise.reject({ response: { status: 400, data: { error: 'Invalid AI connection: Base URL must use https' } } })
			: Promise.resolve({ data: {} }))
		const instance = createInstance()
		instance.form.aiProvider = 'openai_compatible'
		instance.form.aiOllamaUrl = 'http://api.deepseek.com'
		instance.form.aiOllamaModel = 'deepseek-chat'
		await instance.save()
		expect(axios.put.mock.calls.map(([url]) => url)).toEqual(['/apps/learning/api/settings/admin'])
		expect(instance.saved).toBe(false)
		expect(instance.error).toContain('must use https')
	})
})
