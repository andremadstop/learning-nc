/**
 * Audit 2026-11:
 *  #93  a knowledge source titled 'Kapitel 3 / Netzwerktopologien' could not be deleted: the
 *       title went into the URL path, where '/' never reaches the route
 *  #100 KnowledgeModeration showed 'no pending contributions' when loading failed, and a failed
 *       approve/reject gave no feedback
 *  #105 a click next to the pool generator closed it and discarded the AI drafts in review
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
})

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => url.replace(/{(\w+)}/g, (m, k) => encodeURIComponent(String(params[k])))),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'en',
	isRTL: () => false,
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => stub('NcButton'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => stub('NcLoadingIcon'))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: stub('NcLoadingIcon').default, NcNoteCard: stub('NcNoteCard').default }))
vi.mock('../../src/components/PoolDraftReview.vue', () => stub('PoolDraftReview'))
vi.mock('../../src/components/AiConsentHint.vue', () => stub('AiConsentHint'))

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import CourseKnowledgeImport from '../../src/components/CourseKnowledgeImport.vue'
import KnowledgeModeration from '../../src/components/KnowledgeModeration.vue'
import PoolGenerator from '../../src/components/PoolGenerator.vue'

beforeEach(() => {
	vi.clearAllMocks()
})

describe('#93 deleting a knowledge source', () => {
	it('sends the title as query parameter, so a "/" in it survives', async () => {
		vi.stubGlobal('confirm', () => true)
		axios.delete.mockResolvedValueOnce({ data: { deleted: 3 } })
		const ctx = {
			courseId: 5,
			error: null,
			deleting: {},
			imports: [{ source_file: 'Kapitel 3 / Netzwerktopologien' }, { source_file: 'Other' }],
		}

		await CourseKnowledgeImport.methods.deleteImport.call(ctx, 'Kapitel 3 / Netzwerktopologien')

		expect(axios.delete).toHaveBeenCalledWith(
			'/apps/learning/api/courses/5/knowledge',
			{ params: { title: 'Kapitel 3 / Netzwerktopologien' } },
		)
		expect(ctx.imports).toEqual([{ source_file: 'Other' }])
		vi.unstubAllGlobals()
	})
})

describe('#100 KnowledgeModeration errors are visible', () => {
	function ctx() {
		return { courseId: 5, pending: [], loading: false, loadError: '', moderating: {}, $emit: vi.fn() }
	}

	it('a failed load sets an error instead of looking like an empty queue', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 403, data: { error: 'Forbidden' } } })
		const c = ctx()
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await KnowledgeModeration.methods.loadPending.call(c)

		expect(c.loadError).toBe('Failed to load pending contributions.')
		expect(c.loading).toBe(false)
	})

	it('a later successful load clears the error', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 1 }] })
		const c = { ...ctx(), loadError: 'old' }

		await KnowledgeModeration.methods.loadPending.call(c)

		expect(c.loadError).toBe('')
		expect(c.pending).toEqual([{ id: 1 }])
	})

	it('a failed moderation shows the server message and keeps the item', async () => {
		axios.post.mockRejectedValueOnce({ response: { status: 404, data: { error: 'Contribution not found' } } })
		const c = { ...ctx(), pending: [{ id: 7 }] }
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await KnowledgeModeration.methods.moderate.call(c, 7, 'approve')

		expect(showError).toHaveBeenCalledWith('Contribution not found')
		expect(c.pending).toEqual([{ id: 7 }])
		expect(c.moderating[7]).toBe(false)
	})

	it('the template has an error state with a retry', () => {
		expect(KnowledgeModeration.components).toHaveProperty('NcNoteCard')
		expect(KnowledgeModeration.components).toHaveProperty('NcButton')
	})
})

describe('#105 the pool generator backdrop', () => {
	it.each(['generating', 'review'])('does not close in step %s', (step) => {
		const ctx = { step, $emit: vi.fn() }
		PoolGenerator.methods.onBackdropClick.call(ctx)
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('still closes in the input step', () => {
		const ctx = { step: 'input', $emit: vi.fn() }
		PoolGenerator.methods.onBackdropClick.call(ctx)
		expect(ctx.$emit).toHaveBeenCalledWith('close')
	})
})
