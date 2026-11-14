import { describe, expect, it, vi } from 'vitest'

// GitHub #36: CourseSummary hides the swarm counters without AI; the Markdown export it
// triggers has to get the same switch, otherwise view and export disagree.
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alex', displayName: 'Alex' }) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../../src/utils/summary-export.js', () => ({ exportMarkdown: vi.fn() }))

const { exportMarkdown } = await import('../../src/utils/summary-export.js')
const { default: CourseSummary } = await import('../../src/components/CourseSummary.vue')

function exportWith(props) {
	const instance = { summary: { sessions: {} }, courseName: 'Kurs', gamificationEnabled: true, leitnerEnabled: true, ...props }
	CourseSummary.methods.exportSummaryMarkdown.call(instance)
	return exportMarkdown.mock.calls.at(-1)[3]
}

describe('CourseSummary Markdown export and the AI switch', () => {
	it.each([true, false])('passes aiEnabled=%s to the export', (aiEnabled) => {
		expect(exportWith({ aiEnabled }).aiEnabled).toBe(aiEnabled)
	})
})
