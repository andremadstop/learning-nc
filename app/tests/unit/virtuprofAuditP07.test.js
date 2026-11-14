/**
 * Audit 2026-11, VirtuProf frontend:
 *  #17  the saved 'Bot sounds' choice only applied after a visit to the settings page
 *  #112 the question last explained via VirtuProf was sent as lastWrongQuestionId forever
 *  #113 'report this question' pushed { content } — the renderers read text, the bubble was empty
 *  #114 the bubble's file link ignored generateUrl and gave dir='' for a root file
 *  #192 ticket status 'answered' had no catalogue entry and stayed English
 *  #193 bubble timestamps for fr/uk were formatted with the 'en' locale
 *  #195 fullscreen 'RAG sources' showed an older answer's sources
 *
 * The components' real methods/computeds run on a plain context object.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: {} })) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => '/nextcloud/index.php' + url),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'de',
	getCanonicalLocale: () => 'de-DE',
	isRTL: () => false,
}))

import axios from '@nextcloud/axios'
import VirtuProf from '../../src/components/VirtuProf.vue'
import VirtuProfBubble from '../../src/components/VirtuProfBubble.vue'
import VirtuProfFullscreen from '../../src/components/VirtuProfFullscreen.vue'
import { ncFileUrl } from '../../src/utils/ncFileUrl.js'
import { novaAudio } from '../../src/utils/nova-audio-manager.js'
import { translateVirtuProf } from '../../src/utils/virtuprof-i18n.js'

describe('#17 bot sounds follow the saved state', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		novaAudio.setEnabled(false)
	})

	it('a saved "on" enables the sounds when the VirtuProf state loads', () => {
		const ctx = { $emit: vi.fn(), getBrowserVoiceLanguage: () => 'de-DE' }
		VirtuProf.methods.applyVirtuProfState.call(ctx, { bot_sounds_enabled: true })
		expect(novaAudio.isEnabled()).toBe(true)

		VirtuProf.methods.applyVirtuProfState.call(ctx, { bot_sounds_enabled: false })
		expect(novaAudio.isEnabled()).toBe(false)
	})
})

describe('#112 the explained question does not stick to later chats', () => {
	function ctxWith(currentContext) {
		return { currentContext, hintLevel: 0, lastHintQuestionId: null }
	}

	it('leaving the question drops questionId', () => {
		const ctx = ctxWith({ poolId: 3, courseId: 9, questionId: 41, questionContext: { questionId: 41 } })
		VirtuProf.methods.updateContext.call(ctx, { questionContext: null })
		expect(ctx.currentContext.questionId).toBeUndefined()
	})

	it('the next question drops it', () => {
		const ctx = ctxWith({ poolId: 3, courseId: 9, questionId: 41, questionContext: { questionId: 41 } })
		VirtuProf.methods.updateContext.call(ctx, { poolId: 3, courseId: 9, questionContext: { questionId: 42 } })
		expect(ctx.currentContext.questionId).toBeUndefined()
	})

	it('another course drops it', () => {
		const ctx = ctxWith({ poolId: 3, courseId: 9, questionId: 41 })
		VirtuProf.methods.updateContext.call(ctx, { area: 'course', courseId: 12 })
		expect(ctx.currentContext.questionId).toBeUndefined()
	})

	it('the same question keeps it', () => {
		const ctx = ctxWith({ poolId: 3, courseId: 9, questionId: 41, questionContext: { questionId: 41 } })
		VirtuProf.methods.updateContext.call(ctx, { poolId: 3, courseId: 9, questionContext: { questionId: 41 } })
		expect(ctx.currentContext.questionId).toBe(41)
	})
})

describe('#113 reporting a question answers in the chat', () => {
	function reportCtx() {
		return {
			currentContext: { questionContext: { questionId: 7, poolId: 3, courseId: 9 } },
			chatMessages: [],
			language: 'fr',
			vt: VirtuProf.methods.vt,
		}
	}

	it('a sent report shows a non-empty, translated confirmation', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const ctx = reportCtx()
		await VirtuProf.methods.handleReportError.call(ctx)
		const last = ctx.chatMessages.at(-1)
		expect(last.role).toBe('assistant')
		expect(last.text).toBe('Merci pour le signalement ! La question a été transmise pour vérification.')
	})

	it('a failed report says so', async () => {
		axios.post.mockRejectedValueOnce(new Error('500'))
		const ctx = reportCtx()
		await VirtuProf.methods.handleReportError.call(ctx)
		expect(ctx.chatMessages.at(-1).text).toBe("Le signalement n'a pas pu être envoyé. Veuillez réessayer plus tard.")
	})
})

describe('#114 file links go through generateUrl', () => {
	it('keeps the instance prefix and uses / for a root file', () => {
		expect(ncFileUrl('/Lernplan.md')).toBe('/nextcloud/index.php/apps/files/?dir=%2F&scrollto=Lernplan.md')
	})

	it('links a nested file to its folder', () => {
		expect(ncFileUrl('/Learning/Zusammenfassungen/VLAN.md'))
			.toBe('/nextcloud/index.php/apps/files/?dir=%2FLearning%2FZusammenfassungen&scrollto=VLAN.md')
	})

	it('the bubble and fullscreen use the same builder', () => {
		expect(VirtuProfBubble.methods.ncFileUrl).toBe(ncFileUrl)
		expect(VirtuProfFullscreen.methods.ncFileUrl).toBe(ncFileUrl)
	})
})

describe('bubble ticket status and timestamps', () => {
	function bubble(effectiveLanguage) {
		const ctx = { effectiveLanguage }
		ctx.vt = (key, params) => translateVirtuProf(effectiveLanguage, key, params)
		return ctx
	}

	it('#192 an answered ticket is labelled in the user language', () => {
		expect(VirtuProfBubble.methods.statusLabel.call(bubble('de'), 'answered')).toBe('Beantwortet')
		expect(VirtuProfBubble.methods.statusLabel.call(bubble('uk'), 'answered')).toBe('Відповідь надано')
	})

	it('#192 the other labels still translate, unknown ones pass through', () => {
		expect(VirtuProfBubble.methods.statusLabel.call(bubble('de'), 'open'))
			.toBe(translateVirtuProf('de', 'Open'))
		expect(VirtuProfBubble.methods.statusLabel.call(bubble('de'), 'weird')).toBe('weird')
	})

	it.each(['uk', 'fr'])('#193 %s timestamps use their own locale', (lang) => {
		const ts = 1794500645
		expect(VirtuProfBubble.methods.formatTimestamp.call(bubble(lang), ts))
			.toBe(new Date(ts * 1000).toLocaleString(lang))
	})
})

describe('#195 fullscreen sources belong to the latest answer', () => {
	const latestSources = VirtuProfFullscreen.computed.latestSources

	it('an answer without sources shows none, not the previous answer’s', () => {
		const chatMessages = [
			{ role: 'user', text: 'Q1' },
			{ role: 'assistant', text: 'A1', sources: [{ source_file: 'old.md' }] },
			{ role: 'user', text: 'Q2' },
			{ role: 'assistant', text: 'A2' },
		]
		expect(latestSources.call({ chatMessages })).toEqual([])
	})

	it('the latest answer’s sources are shown', () => {
		const chatMessages = [
			{ role: 'assistant', text: 'A1', sources: [{ source_file: 'old.md' }] },
			{ role: 'assistant', text: 'A2', sources: [{ source_file: 'new.md' }] },
			{ role: 'user', text: 'Q3' },
		]
		expect(latestSources.call({ chatMessages })).toEqual([{ source_file: 'new.md' }])
	})
})
