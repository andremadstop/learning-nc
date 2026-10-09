import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, h, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: {
		name: 'AccessibleDialog',
		props: ['name'],
		emits: ['closing'],
		template: '<div role="dialog"><h2>{{ name }}</h2><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
}))

import axios from '@nextcloud/axios'
import AiConsentHint from '../../src/components/AiConsentHint.vue'
import LeitnerMode from '../../src/components/LeitnerMode.vue'
import TrainingMode from '../../src/components/TrainingMode.vue'
import PoolGenerator from '../../src/components/PoolGenerator.vue'
import AIGenerator from '../../src/components/AIGenerator.vue'
import AbenteuerMode from '../../src/components/AbenteuerMode.vue'
import CourseSummary from '../../src/components/CourseSummary.vue'

const REFUSALS = [
	['403 consent_required', { response: { status: 403, data: { error: 'AI consent required', consent_required: true } } }],
	['409 recipient_changed', { response: { status: 409, data: { error: 'recipient_changed' } } }],
]

/**
 * 5.7.0 D3: every AI entry point answers a missing consent with the shared hint (a way to the
 * dialog) instead of vanishing, a dead-end text ("open VirtuProf") or a generic error - for
 * consent_required AND for a recipient that changed (409).
 */
describe.each(REFUSALS)('entry points on %s', (_label, error) => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.post.mockRejectedValue(error)
	})

	it('LeitnerMode.requestExplain', async () => {
		const ctx = { explainText: '', explainTaskId: null, explainLoading: false, explainPollTimer: null, aiConsentRequired: false, currentItem: { id: 1 }, isOpenQuestion: false, isCurrentMulti: false, lastSelectedAnswerId: 1 }
		await LeitnerMode.methods.requestExplain.call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.explainText).toBe('')
		expect(ctx.explainLoading).toBe(false)
	})

	it('TrainingMode.requestExplain', async () => {
		const ctx = { explainText: '', explainTaskId: null, explainLoading: false, explainPollTimer: null, aiConsentRequired: false, currentQuestion: { id: 1 }, isOpenQuestion: false, isMultiSelect: false, lastSelectedAnswerId: 1 }
		await TrainingMode.methods.requestExplain.call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.explainText).toBe('')
	})

	it('TrainingMode.generateSummaryNote', async () => {
		const ctx = { summaryGenerating: false, summaryError: null, summaryPath: null, summaryConsentRequired: false, poolId: 1, courseId: 2 }
		await TrainingMode.methods.generateSummaryNote.call(ctx)
		expect(ctx.summaryConsentRequired).toBe(true)
		expect(ctx.summaryError).toBeNull()
		expect(ctx.summaryGenerating).toBe(false)
	})

	it.each(['generateFromText', 'generateFromFile'])('PoolGenerator.%s', async (name) => {
		const ctx = { generating: false, error: '', step: 'input', aiConsentRequired: false, inputText: 'x', filePath: '/a', questionCount: 5 }
		await PoolGenerator.methods[name].call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.error).toBe('')
		expect(ctx.step).toBe('input')
	})

	it('PoolGenerator.createPool', async () => {
		const ctx = { creating: false, error: '', aiConsentRequired: false, draftQuestions: [{ _status: 'accepted', text: 'q' }], poolTitle: 'T', $emit: vi.fn() }
		await PoolGenerator.methods.createPool.call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.error).toBe('')
		expect(ctx.creating).toBe(false)
	})

	it('AIGenerator.startGeneration', async () => {
		const ctx = { generating: false, error: '', aiConsentRequired: false, poolId: 1, questionCount: 5, language: 'de' }
		await AIGenerator.methods.startGeneration.call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.error).toBe('')
		expect(ctx.generating).toBe(false)
	})

	it('AbenteuerMode.submitFreetext', async () => {
		const ctx = { freetextInput: 'ich trenne den Server', freetextLoading: false, freetextError: '', aiConsentRequired: false, selectedCampaign: { id: 3 } }
		await AbenteuerMode.methods.submitFreetext.call(ctx)
		expect(ctx.aiConsentRequired).toBe(true)
		expect(ctx.freetextError).toBe('')
		expect(ctx.freetextLoading).toBe(false)
	})
})

describe('other failures are not mistaken for a missing consent', () => {
	it('a 500 stays a normal error', async () => {
		axios.post.mockReset()
		axios.post.mockRejectedValue({ response: { status: 500, data: { error: 'Generation failed' } } })
		const ctx = { generating: false, error: '', step: 'input', aiConsentRequired: false, inputText: 'x', questionCount: 5 }
		await PoolGenerator.methods.generateFromText.call(ctx)
		expect(ctx.aiConsentRequired).toBe(false)
		expect(ctx.error).toBe('Generation failed')
	})
})

describe('CourseSummary narrative', () => {
	it('reports a consent_required body (200) as missing consent', async () => {
		axios.post.mockReset()
		axios.post.mockResolvedValueOnce({ data: { narrative: null, cached: false, consent_required: true } })
		const ctx = { narrativeLoading: false, narrative: null, narrativeConsentRequired: false, courseId: 4 }
		await CourseSummary.methods.loadNarrative.call(ctx)
		expect(ctx.narrativeConsentRequired).toBe(true)
	})

	it('reports a 403/409 refusal as missing consent too', async () => {
		axios.post.mockReset()
		axios.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'recipient_changed' } } })
		const ctx = { narrativeLoading: false, narrative: null, narrativeConsentRequired: false, courseId: 4 }
		await CourseSummary.methods.loadNarrative.call(ctx)
		expect(ctx.narrativeConsentRequired).toBe(true)
	})
})

describe('AiConsentHint', () => {
	let host, app, events
	async function flush() {
		for (let i = 0; i < 8; i++) await Promise.resolve()
		await nextTick()
	}
	function mountHint() {
		events = []
		host = document.createElement('div')
		document.body.appendChild(host)
		app = createApp({ render: () => h(AiConsentHint, { onGranted: () => events.push('granted') }) })
		app.config.globalProperties.t = (_a, s) => s
		app.config.warnHandler = () => {}
		app.mount(host)
	}
	beforeEach(() => {
		document.body.innerHTML = ''
		axios.get.mockReset()
		axios.post.mockReset()
	})
	afterEach(() => { app?.unmount(); app = null; host?.remove() })

	it('says the AI functions are off and offers the dialog; nothing is written by showing it', async () => {
		mountHint()
		expect(host.textContent).toContain('KI-Funktionen sind aus')
		expect(host.querySelector('button')).not.toBeNull()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('the button opens the consent dialog; accepting reports granted and hides the hint', async () => {
		axios.get.mockResolvedValue({ data: { valid: false, reason: 'never', recipient: { name: 'Google Gemini', region: 'us', processing_local: false, intermediary: false, fingerprint: 'fp' } } })
		axios.post.mockResolvedValue({ data: { status: 'ok', valid: true } })
		mountHint()
		host.querySelector('button').click()
		await flush()
		expect(host.querySelector('[role="dialog"]')).not.toBeNull()

		host.querySelector('.actions button:last-child').click()
		await flush()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/profile/telos/consent', expect.objectContaining({ recipient_fingerprint: 'fp', source: 'dialog' }))
		expect(events).toEqual(['granted'])
		expect(host.querySelector('.ai-consent-hint')).toBeNull()
	})

	it('when the AI is switched off meanwhile it says so instead of opening a dialog', async () => {
		axios.get.mockResolvedValue({ data: { valid: false, recipient: null, ai_enabled: false } })
		mountHint()
		host.querySelector('button').click()
		await flush()
		expect(host.querySelector('[role="dialog"]')).toBeNull()
		expect(host.textContent).toContain('keine KI eingerichtet')
	})
})
