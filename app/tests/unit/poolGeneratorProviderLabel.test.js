/**
 * GitHub #115: PoolGenerator names the provider PoolGeneratorService reports
 * (LlmService::getProviderId()). openai_compatible showed the raw id; it now uses the label the
 * admin page already uses for that option. Calls the real `providerLabel` computed.
 */
import { describe, it, expect, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'en',
	getCanonicalLocale: () => 'en-US',
	isRTL: () => false,
}))

import PoolGenerator from '../../src/components/PoolGenerator.vue'

const label = (provider) => PoolGenerator.computed.providerLabel.call({ providerInfo: { provider } })

describe('PoolGenerator providerLabel', () => {
	it('names an OpenAI-compatible connection like the admin page does, not by its raw id', () => {
		expect(label('openai_compatible')).toBe('OpenAI-compatible (OpenAI, DeepSeek, MiMo, …)')
	})

	it('keeps the Gemini, Ollama and disabled labels', () => {
		expect(label('gemini')).toBe('Gemini Cloud')
		expect(label('ollama')).toBe('Local (Ollama)')
		expect(label('disabled')).toBe('Disabled')
	})

	it('shows a placeholder before the provider info has loaded', () => {
		expect(PoolGenerator.computed.providerLabel.call({ providerInfo: null })).toBe('...')
	})
})
